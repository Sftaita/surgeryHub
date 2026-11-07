<?php

namespace App\Activity\AppendOnly;

use App\Entity\ActivityEvent;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;

/**
 * H1 (D-126) — ORM-level half of the append-only guarantee: an ActivityEvent is never
 * persisted through the ORM (ActivityJournal writes with DBAL), and an existing one can
 * neither be removed nor have a changed field flushed. Fails loudly instead of letting the
 * SQL-level guard surface a less explicit error mid-flush.
 */
#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::onFlush)]
final class ActivityEventOrmGuard
{
    public function preRemove(PreRemoveEventArgs $args): void
    {
        if ($args->getObject() instanceof ActivityEvent) {
            throw new ActivityEventImmutableException('An ActivityEvent cannot be removed.');
        }
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof ActivityEvent) {
                throw new ActivityEventImmutableException('ActivityEvent rows are written by ActivityJournal only.');
            }
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof ActivityEvent) {
                throw new ActivityEventImmutableException('An ActivityEvent cannot be modified.');
            }
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof ActivityEvent) {
                throw new ActivityEventImmutableException('An ActivityEvent cannot be removed.');
            }
        }
    }
}
