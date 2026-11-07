<?php

namespace App\Activity;

use App\Doctrine\Type\UtcDateTimeMicroImmutableType;
use App\Entity\User;
use App\Enum\ActivityActorType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * H1 (D-126) — the ONLY write API of the transversal activity journal (activity_event).
 *
 * Responsibilities, and nothing more (no Mission/Planning/Absence logic lives here —
 * domain recorders build the ActivityRecord):
 *   - validate eventType (closed registry) and domain;
 *   - filter the payload through ActivityPayloadPolicy;
 *   - snapshot the actor (name, primary role, actor type) — never an email;
 *   - stamp correlationId / requestId / messengerMessageId from CorrelationContext;
 *   - resolve causation from an explicit id or an explicit anchor — never from order;
 *   - honour idempotencyKey (a second write with the same key returns the first id);
 *   - INSERT through the EntityManager's DBAL connection, INSIDE the caller's
 *     transaction.
 *
 * Transaction rule: record() refuses to run outside an active transaction. The event must
 * commit or roll back together with the business mutation it describes — a business
 * change committed without its trace, or a trace without its change, is exactly the
 * "lying history" H1 forbids. Callers inside a Doctrine flush (postPersist/postUpdate/
 * postRemove listeners) are inside the UnitOfWork's transaction by construction. The only
 * exception is recordStandalone(), for an event that describes no mutation of its own
 * (e.g. a batch that already failed and must still be reported), which opens its own
 * short transaction.
 *
 * Failure policy: any error propagates. When enabled, an unrecordable event makes the
 * business transaction roll back rather than silently losing history.
 *
 * ACTIVITY_JOURNAL_ENABLED=0 is an operational kill switch only: record() becomes a no-op.
 */
class ActivityJournal
{
    public const SYSTEM_USER_EMAIL = 'system@surgicalhub.internal';

    private const ROLE_PRIORITY = ['ROLE_ADMIN', 'ROLE_MANAGER', 'ROLE_SURGEON', 'ROLE_INSTRUMENTIST'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CorrelationContext $context,
        private readonly ActivityPayloadPolicy $payloadPolicy,
        #[Autowire('%env(bool:ACTIVITY_JOURNAL_ENABLED)%')]
        private readonly bool $enabled,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param Connection|null $connection the connection of the transaction being described —
     *        Doctrine listeners pass the flushing EntityManager's own connection, so the event
     *        joins THAT transaction even when several EntityManagers coexist (concurrency
     *        tests, future multi-EM setups). Defaults to the default EntityManager's.
     * @return int|null the activity_event id (or the existing one for a duplicate idempotencyKey); null when disabled
     */
    public function record(ActivityRecord $record, ?Connection $connection = null): ?int
    {
        if (!$this->enabled) {
            return null;
        }

        $connection ??= $this->em->getConnection();
        if (!$connection->isTransactionActive()) {
            throw new \LogicException(sprintf(
                'ActivityJournal::record(%s) must run inside the business transaction it describes.',
                $record->eventType->value,
            ));
        }

        return $this->insert($record, $connection);
    }

    /** For an event describing no mutation of its own (see class docblock). */
    public function recordStandalone(ActivityRecord $record): ?int
    {
        if (!$this->enabled) {
            return null;
        }

        $connection = $this->em->getConnection();

        return $connection->transactional(fn () => $this->insert($record, $connection));
    }

    private function insert(ActivityRecord $record, Connection $connection): int
    {
        $type = $record->eventType;
        if (!ActivityEventTypeRegistry::isKnown($type)) {
            throw new \InvalidArgumentException(sprintf('Unknown activity event type %s::%s.', $type::class, $type->name));
        }
        $domain = $record->domain ?? ActivityEventTypeRegistry::domainOf($type);

        if ($record->idempotencyKey !== null) {
            $existing = $this->findByIdempotencyKey($record->idempotencyKey, $connection);
            if ($existing !== null) {
                return $existing;
            }
        }

        $summary = trim(preg_replace('/\s+/u', ' ', ActivityPayloadPolicy::maskEmails(strip_tags($record->summary))) ?? '');
        if ($summary === '') {
            throw new \InvalidArgumentException(sprintf('Activity event %s needs a summary.', $type->value));
        }

        $payload = $this->payloadPolicy->filter($type, $domain, $record->payload);

        [$actorType, $actorId, $actorName, $actorRole] = $this->actorSnapshot($record->actor, $record->actorType);

        $causation = $record->causationEventId;
        if ($causation === null) {
            foreach ($record->causeAnchors as $anchorKey) {
                $causation = $this->context->anchor($anchorKey);
                if ($causation !== null) {
                    break;
                }
            }
        }
        $causation ??= $this->context->messageCausationEventId();

        $row = [
            'event_type'             => $type->value,
            'domain'                 => $domain->value,
            'occurred_at'            => UtcDateTimeMicroImmutableType::format($record->occurredAt ?? new \DateTimeImmutable()),
            'actor_type'             => $actorType->value,
            'actor_user_id'          => $actorId,
            'actor_name_snapshot'    => self::cap($actorName, 255),
            'actor_role_snapshot'    => $actorRole,
            'subject_type'           => $record->subjectType,
            'subject_id'             => $record->subjectId,
            'subject_label_snapshot' => self::cap($record->subjectLabel !== null ? ActivityPayloadPolicy::maskEmails($record->subjectLabel) : null, 255),
            'correlation_id'         => $this->context->correlationId(),
            'causation_event_id'     => $causation,
            'site_id'                => $record->siteId,
            'planning_version_id'    => $record->planningVersionId,
            'mission_id'             => $record->missionId,
            'absence_id'             => $record->absenceId,
            'user_id'                => $record->userId,
            'outcome'                => $record->outcome->value,
            'summary'                => self::cap($summary, 500),
            'payload'                => $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'source_ref'             => $record->sourceRef,
            'idempotency_key'        => $record->idempotencyKey,
            'request_id'             => $this->context->requestId(),
            'messenger_message_id'   => $this->context->messengerMessageId(),
        ];

        try {
            $connection->insert('activity_event', $row);
        } catch (UniqueConstraintViolationException $e) {
            // Concurrent writer with the same idempotencyKey (e.g. a Messenger retry racing
            // the original) — MySQL keeps the transaction usable after a failed statement.
            $existing = $record->idempotencyKey !== null ? $this->findByIdempotencyKey($record->idempotencyKey, $connection) : null;
            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }

        $id = (int) $connection->lastInsertId();

        if ($record->anchorKey !== null) {
            $this->context->registerAnchor($record->anchorKey, $id);
        }

        return $id;
    }

    private function findByIdempotencyKey(string $key, Connection $connection): ?int
    {
        $id = $connection->fetchOne(
            'SELECT id FROM activity_event WHERE idempotency_key = ?',
            [$key],
        );

        return $id === false ? null : (int) $id;
    }

    /** @return array{0: ActivityActorType, 1: ?int, 2: ?string, 3: ?string} */
    private function actorSnapshot(?User $actor, ?ActivityActorType $override): array
    {
        if ($actor === null) {
            $default = $this->context->defaultActorType();
            // An HTTP operation with no identified actor (e.g. an automated reaction) is
            // not a "USER" action — report it as SYSTEM rather than inventing a person.
            $type = $override ?? ($default === ActivityActorType::USER ? ActivityActorType::SYSTEM : $default);

            return [$type, null, null, null];
        }

        try {
            $isSystem = $actor->getEmail() === self::SYSTEM_USER_EMAIL;
        } catch (EntityNotFoundException) {
            $isSystem = false;
        }
        $type     = $override ?? ($isSystem ? ActivityActorType::SYSTEM : ActivityActorType::USER);

        return [$type, $actor->getId(), self::displayName($actor), self::primaryRole($actor)];
    }

    /** Never falls back to the email address — the journal must not carry one. */
    public static function displayName(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }
        try {
            $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        } catch (EntityNotFoundException) {
            // Dangling reference (row deleted behind Doctrine's back) — the history must
            // still be writable, e.g. when cleaning up exactly that corrupted data.
            return sprintf('Utilisateur #%d (introuvable)', (int) $user->getId());
        }

        return $name !== '' ? $name : sprintf('Utilisateur #%d', (int) $user->getId());
    }

    public static function primaryRole(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }
        try {
            $roles = $user->getRoles();
        } catch (EntityNotFoundException) {
            return null;
        }
        foreach (self::ROLE_PRIORITY as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        return null;
    }

    private static function cap(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
