<?php

namespace App\Activity\Recorder;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Entity\Absence;
use App\Entity\User;
use App\Enum\ActivityEventType;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * H1 (D-126) — ABSENCE_CREATED / ABSENCE_UPDATED / ABSENCE_DELETED, written in the same
 * flush transaction as the Absence row change.
 *
 * Doctrine-level on purpose: absences are created, edited, shrunk, split and deleted from
 * several entry points (AbsenceController, SelfAbsenceController create/update/delete/
 * remove-day/shrink…). Hooking the entity covers every one of them — present and future —
 * with one atomic write each, instead of one easily-forgotten call per controller.
 *
 * Snapshots are captured in onFlush (before any SQL runs, while the removed entity still
 * has its id and the updated one its change set) and written in postPersist/postUpdate/
 * postRemove, which run inside the flush transaction. ABSENCE_DELETED's occurredAt is the
 * onFlush instant — i.e. before the row disappears — and the event commits only if the
 * DELETE commits.
 *
 * The free-text reason is never copied (it may contain health information) — only
 * hasReason.
 *
 * Each event registers itself as the causal anchor "absence:{id}" of the current
 * operation, so the AuditEvents produced by the absence reaction services
 * (causedByAbsenceId / absenceId) — and Messenger handlers dispatched by this request —
 * point back to it.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: Absence::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: Absence::class)]
#[AsEntityListener(event: Events::postRemove, method: 'postRemove', entity: Absence::class)]
final class AbsenceActivityListener
{
    /** @var array<int, array{before: array<string, mixed>, after: array<string, mixed>, changed: list<string>}> */
    private array $pendingUpdates = [];

    /** @var array<int, array{snapshot: array<string, mixed>, user: ?User, occurredAt: \DateTimeImmutable}> */
    private array $pendingDeletes = [];

    public function __construct(
        private readonly ActivityJournal $journal,
        private readonly Security $security,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        // Each flush recomputes its own snapshots: a previous flush that failed before its
        // post* events must never leave a stale entry behind (spl_object_id can be reused).
        $this->pendingUpdates = [];
        $this->pendingDeletes = [];

        if (!$this->journal->isEnabled()) {
            return;
        }

        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Absence) {
                continue;
            }
            $changeSet = $uow->getEntityChangeSet($entity);
            $changed   = array_values(array_intersect(array_keys($changeSet), ['dateStart', 'dateEnd', 'reason', 'user']));
            if ($changed === []) {
                continue;
            }
            $before = self::dates($entity);
            foreach (['dateStart', 'dateEnd'] as $field) {
                if (isset($changeSet[$field])) {
                    $before[$field] = $changeSet[$field][0]?->format('Y-m-d');
                }
            }
            if (isset($changeSet['reason'])) {
                $before['hasReason'] = self::hasText($changeSet['reason'][0]);
            }

            $this->pendingUpdates[spl_object_id($entity)] = [
                'before'  => $before,
                'after'   => self::dates($entity),
                'changed' => $changed,
            ];
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof Absence && $entity->getId() !== null) {
                $this->pendingDeletes[spl_object_id($entity)] = [
                    'snapshot'   => self::dates($entity) + [
                        'absenceId'   => $entity->getId(),
                        'createdById' => $entity->getCreatedBy()?->getId(),
                        'createdAt'   => $entity->getCreatedAt()->format(\DateTimeInterface::ATOM),
                    ],
                    'user'       => $entity->getUser(),
                    'occurredAt' => new \DateTimeImmutable(),
                ];
            }
        }
    }

    public function postPersist(Absence $absence, PostPersistEventArgs $args): void
    {
        if (!$this->journal->isEnabled()) {
            return;
        }

        $person  = $absence->getUser();
        $creator = $absence->getCreatedBy();
        $self    = $creator !== null && $person !== null && $creator->getId() === $person->getId();

        $summary = $self
            ? sprintf('%s a enregistré une absence du %s au %s', self::name($person), self::dmy($absence->getDateStart()), self::dmy($absence->getDateEnd()))
            : sprintf('%s a enregistré une absence pour %s du %s au %s', self::name($creator), self::name($person), self::dmy($absence->getDateStart()), self::dmy($absence->getDateEnd()));

        $this->journal->record(new ActivityRecord(
            eventType: ActivityEventType::ABSENCE_CREATED,
            subjectType: 'Absence',
            subjectId: $absence->getId(),
            subjectLabel: self::label($absence, $person),
            summary: $summary,
            payload: self::personPayload($absence, $person) + self::dates($absence) + [
                'createdById'  => $creator?->getId(),
                'selfDeclared' => $self,
            ],
            actor: $creator ?? $this->currentUser(),
            absenceId: $absence->getId(),
            userId: $person?->getId(),
            idempotencyKey: sprintf('absence:%d:created', $absence->getId()),
            anchorKey: 'absence:' . $absence->getId(),
        ), $args->getObjectManager()->getConnection());
    }

    public function postUpdate(Absence $absence, PostUpdateEventArgs $args): void
    {
        $pending = $this->pendingUpdates[spl_object_id($absence)] ?? null;
        unset($this->pendingUpdates[spl_object_id($absence)]);
        if ($pending === null || !$this->journal->isEnabled()) {
            return;
        }

        $person = $absence->getUser();
        $actor  = $this->currentUser();

        $this->journal->record(new ActivityRecord(
            eventType: ActivityEventType::ABSENCE_UPDATED,
            subjectType: 'Absence',
            subjectId: $absence->getId(),
            subjectLabel: self::label($absence, $person),
            summary: sprintf('%s a modifié l\'absence #%d de %s : %s → %s',
                $actor !== null ? self::name($actor) : 'Système', $absence->getId(), self::name($person),
                self::range($pending['before']), self::range($pending['after'])),
            payload: self::personPayload($absence, $person) + [
                'before'        => $pending['before'],
                'after'         => $pending['after'],
                'changedFields' => $pending['changed'],
            ],
            actor: $actor,
            absenceId: $absence->getId(),
            userId: $person?->getId(),
            anchorKey: 'absence:' . $absence->getId(),
        ), $args->getObjectManager()->getConnection());
    }

    public function postRemove(Absence $absence, PostRemoveEventArgs $args): void
    {
        $pending = $this->pendingDeletes[spl_object_id($absence)] ?? null;
        unset($this->pendingDeletes[spl_object_id($absence)]);
        if ($pending === null || !$this->journal->isEnabled()) {
            return;
        }

        $snapshot  = $pending['snapshot'];
        $person    = $pending['user'];
        $absenceId = (int) $snapshot['absenceId'];
        $actor     = $this->currentUser();

        $this->journal->record(new ActivityRecord(
            eventType: ActivityEventType::ABSENCE_DELETED,
            subjectType: 'Absence',
            subjectId: $absenceId,
            subjectLabel: sprintf('Absence #%d — %s (%s)', $absenceId, self::name($person), self::range($snapshot)),
            summary: sprintf('%s a supprimé l\'absence #%d de %s (%s)',
                $actor !== null ? self::name($actor) : 'Système', $absenceId, self::name($person), self::range($snapshot)),
            payload: [
                'absenceId' => $absenceId,
                'userId'    => $person?->getId(),
                'userName'  => ActivityJournal::displayName($person),
                'userRole'  => ActivityJournal::primaryRole($person),
            ] + $snapshot,
            actor: $actor,
            absenceId: $absenceId,
            userId: $person?->getId(),
            idempotencyKey: sprintf('absence:%d:deleted', $absenceId),
            anchorKey: 'absence:' . $absenceId,
            occurredAt: $pending['occurredAt'],
        ), $args->getObjectManager()->getConnection());
    }

    private function currentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    /** @return array<string, mixed> */
    private static function personPayload(Absence $absence, ?User $person): array
    {
        return [
            'absenceId' => $absence->getId(),
            'userId'    => $person?->getId(),
            'userName'  => ActivityJournal::displayName($person),
            'userRole'  => ActivityJournal::primaryRole($person),
        ];
    }

    /** @return array{dateStart: string, dateEnd: string, hasReason: bool} */
    private static function dates(Absence $absence): array
    {
        return [
            'dateStart' => $absence->getDateStart()->format('Y-m-d'),
            'dateEnd'   => $absence->getDateEnd()->format('Y-m-d'),
            'hasReason' => self::hasText($absence->getReason()),
        ];
    }

    private static function label(Absence $absence, ?User $person): string
    {
        return sprintf('Absence #%d — %s (%s → %s)', $absence->getId(), self::name($person),
            self::dmy($absence->getDateStart()), self::dmy($absence->getDateEnd()));
    }

    /** @param array<string, mixed> $dates */
    private static function range(array $dates): string
    {
        $fmt = static function (?string $ymd): string {
            $d = $ymd !== null ? \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd) : false;

            return $d !== false ? $d->format('d/m/Y') : '?';
        };

        return $fmt($dates['dateStart'] ?? null) . ' → ' . $fmt($dates['dateEnd'] ?? null);
    }

    private static function name(?User $user): string
    {
        return ActivityJournal::displayName($user) ?? 'Inconnu';
    }

    private static function dmy(\DateTimeInterface $d): string
    {
        return $d->format('d/m/Y');
    }

    private static function hasText(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
