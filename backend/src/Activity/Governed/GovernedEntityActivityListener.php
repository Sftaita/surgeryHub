<?php

namespace App\Activity\Governed;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * H5b (D-132) — the journal entry of every create / update / delete of a GOVERNED entity
 * (catalogue, firms and offerings, sites and groups, habitual planning, preferences, push
 * devices, encoding content, exports…), written in the transaction of the flush that makes
 * the change — the same Doctrine-level discipline as H1's AbsenceActivityListener, extended
 * to a registry of describers (GovernedEntityDescriber, one per entity class).
 *
 * Doctrine-level on purpose: these entities are changed from many controllers and services;
 * hooking the entity covers every present and future entry point with one atomic write,
 * instead of an easily-forgotten call per endpoint (the risk H5b closes).
 *
 * Granularity: one event per entity and flush — a form saving five fields is ONE update
 * event carrying the diff of the TRACKED fields only; an update touching none of them
 * (technical refresh) writes nothing. Free-text fields are recorded as "changed", never by
 * value. Snapshots are taken in onFlush (before any SQL, while the change set and the
 * removed entity's id exist) and written in postPersist / postUpdate / postRemove, inside
 * the flush transaction: the event commits if and only if the change commits.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
final class GovernedEntityActivityListener
{
    /** @var array<class-string, GovernedEntityDescriber> */
    private array $describers = [];

    /** @var array<int, array{changes: list<array>, original: array<string, mixed>}> */
    private array $pendingUpdates = [];

    /** @var array<int, ?ActivityRecord> described at onFlush, written in postRemove */
    private array $pendingDeletes = [];

    /** @param iterable<GovernedEntityDescriber> $describers */
    public function __construct(
        private readonly ActivityJournal $journal,
        private readonly Security $security,
        #[AutowireIterator(GovernedEntityDescriber::TAG)]
        iterable $describers,
    ) {
        foreach ($describers as $describer) {
            $this->describers[$describer->entityClass()] = $describer;
        }
    }

    /** @return list<class-string> the governed entity classes (architecture test) */
    public function governedClasses(): array
    {
        return array_keys($this->describers);
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $this->pendingUpdates = [];
        $this->pendingDeletes = [];
        if (!$this->journal->isEnabled()) {
            return;
        }

        $em  = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            [$describer, $meta] = $this->describerOf($em, $entity);
            if ($describer === null) {
                continue;
            }
            $changes  = [];
            $original = [];
            foreach ($uow->getEntityChangeSet($entity) as $field => [$from, $to]) {
                if (!isset($describer->trackedFields()[$field]) || self::same($from, $to)) {
                    continue;
                }
                $changes[]        = $this->normalize($describer, $meta, $field, $from, $to);
                $original[$field] = $from;
            }
            if ($changes !== []) {
                $this->pendingUpdates[spl_object_id($entity)] = ['changes' => $changes, 'original' => $original];
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            [$describer] = $this->describerOf($em, $entity);
            if ($describer !== null && ($id = self::idOf($em, $entity)) !== null) {
                // Described NOW, while the row and its relations are still loadable (a lazy
                // proxy removed by id, or a relation deleted in the same flush, can no longer
                // be initialised in postRemove); written in postRemove, inside the flush
                // transaction, so it lands only if the deletion does.
                try {
                    $this->pendingDeletes[spl_object_id($entity)] = $describer->describe(
                        new GovernedChange(GovernedChange::DELETED, $entity, $id, [], [], $this->actor(), new \DateTimeImmutable()),
                    );
                } catch (EntityNotFoundException) {
                    // The row is already gone (removed by a bulk DQL/DBAL delete): this flush
                    // deletes nothing, there is nothing to record.
                }
            }
        }
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $em = $args->getObjectManager();
        [$describer, $meta] = $this->describerOf($em, $entity = $args->getObject());
        if ($describer === null || !$this->journal->isEnabled() || ($id = self::idOf($em, $entity)) === null) {
            return;
        }

        $changes = [];
        foreach ($describer->trackedFields() as $field => $label) {
            $value = self::read($meta, $entity, $field);
            if ($value !== null && $value !== '' && $value !== []) {
                $changes[] = $this->normalize($describer, $meta, $field, null, $value);
            }
        }

        $this->write($describer, new GovernedChange(GovernedChange::CREATED, $entity, $id, $changes, [], $this->actor(), new \DateTimeImmutable()), $args);
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity  = $args->getObject();
        $pending = $this->pendingUpdates[spl_object_id($entity)] ?? null;
        unset($this->pendingUpdates[spl_object_id($entity)]);
        $em = $args->getObjectManager();
        [$describer] = $this->describerOf($em, $entity);
        if ($pending === null || $describer === null || !$this->journal->isEnabled() || ($id = self::idOf($em, $entity)) === null) {
            return;
        }

        $this->write($describer, new GovernedChange(GovernedChange::UPDATED, $entity, $id, $pending['changes'], $pending['original'], $this->actor(), new \DateTimeImmutable()), $args);
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $entity  = $args->getObject();
        $record  = $this->pendingDeletes[spl_object_id($entity)] ?? null;
        unset($this->pendingDeletes[spl_object_id($entity)]);
        if ($record instanceof ActivityRecord && $this->journal->isEnabled()) {
            $this->journal->record($record, $args->getObjectManager()->getConnection());
        }
    }

    private function write(GovernedEntityDescriber $describer, GovernedChange $change, PostPersistEventArgs|PostUpdateEventArgs|PostRemoveEventArgs $args): void
    {
        $record = $describer->describe($change);
        if ($record !== null) {
            $this->journal->record($record, $args->getObjectManager()->getConnection());
        }
    }

    /** @return array{0: ?GovernedEntityDescriber, 1: ?ClassMetadata} */
    private function describerOf(EntityManagerInterface $em, object $entity): array
    {
        if ($this->describers === []) {
            return [null, null];
        }
        $meta = $em->getClassMetadata($entity::class);
        $describer = $this->describers[$meta->getName()] ?? null;

        return [$describer, $describer !== null ? $meta : null];
    }

    /** @return array{field: string, label: string, from: mixed, to: mixed, fromId: ?int, toId: ?int, redacted: bool} */
    private function normalize(GovernedEntityDescriber $describer, ClassMetadata $meta, string $field, mixed $from, mixed $to): array
    {
        $redacted = in_array($field, $describer->redactedFields(), true);
        $type     = $meta->hasField($field) ? $meta->getTypeOfField($field) : null;

        return [
            'field'    => $field,
            'label'    => $describer->trackedFields()[$field] ?? $field,
            'from'     => $redacted ? null : self::value($from, $type),
            'to'       => $redacted ? null : self::value($to, $type),
            'fromId'   => self::refId($from),
            'toId'     => self::refId($to),
            'redacted' => $redacted,
        ];
    }

    private static function value(mixed $v, ?string $type): mixed
    {
        return match (true) {
            $v === null => null,
            $v instanceof \DateTimeInterface => match (true) {
                $type !== null && str_contains($type, 'time') && !str_contains($type, 'date') => $v->format('H:i'),
                $type !== null && str_contains($type, 'date') && !str_contains($type, 'datetime') => $v->format('Y-m-d'),
                default => $v->format('Y-m-d H:i'),
            },
            $v instanceof \BackedEnum => $v->value,
            $v instanceof \UnitEnum => $v->name,
            $v instanceof User => ActivityJournal::displayName($v),
            is_object($v) => self::objectLabel($v),
            is_array($v) => implode(', ', array_map(static fn ($x) => is_scalar($x) ? (string) $x : json_encode($x), $v)),
            default => $v,
        };
    }

    private static function objectLabel(object $o): string
    {
        foreach (['getName', 'getLabel', 'getCode', 'getQuestion'] as $getter) {
            if (method_exists($o, $getter)) {
                try {
                    $label = $o->{$getter}();
                } catch (\Throwable) {
                    $label = null;
                }
                if (is_string($label) && $label !== '') {
                    return $label;
                }
            }
        }
        $short = (new \ReflectionClass($o))->getShortName();

        return method_exists($o, 'getId') ? sprintf('%s #%s', $short, (string) $o->getId()) : $short;
    }

    private static function refId(mixed $v): ?int
    {
        if (is_object($v) && !$v instanceof \DateTimeInterface && !$v instanceof \UnitEnum && method_exists($v, 'getId')) {
            try {
                $id = $v->getId();
            } catch (\Throwable) {
                return null;
            }

            return is_int($id) ? $id : null;
        }

        return null;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if ($a instanceof \DateTimeInterface && $b instanceof \DateTimeInterface) {
            return $a->format('Y-m-d H:i:s') === $b->format('Y-m-d H:i:s');
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return $a === $b;
    }

    private static function read(ClassMetadata $meta, object $entity, string $field): mixed
    {
        if ($meta->hasField($field) || $meta->hasAssociation($field)) {
            return $meta->getFieldValue($entity, $field);
        }

        return null;
    }

    private static function idOf(EntityManagerInterface $em, object $entity): ?int
    {
        $ids = $em->getClassMetadata($entity::class)->getIdentifierValues($entity);
        $id  = reset($ids);

        return is_int($id) ? $id : (is_numeric($id) ? (int) $id : null);
    }

    private function actor(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
