<?php

namespace App\Activity\Mirror;

use App\Activity\ActivityJournal;
use App\Entity\AuditEvent;
use App\Entity\UserAuditEvent;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;

/**
 * H1 (D-126) — every AuditEvent / UserAuditEvent inserted by the application gets its
 * ActivityEvent mirror IN THE SAME TRANSACTION.
 *
 * Why postPersist: it is dispatched by the UnitOfWork right after the INSERTs, inside the
 * flush's own transaction (ORM 3: between beginTransaction() and commit()), and is the
 * first moment the audit row's generated id exists — needed for sourceRef/idempotencyKey.
 * The ActivityJournal INSERT therefore commits or rolls back with the audit row and the
 * business mutation flushed alongside it; a failure here aborts the whole flush instead
 * of leaving an audited change without its timeline entry.
 *
 * AuditService::record()/recordGlobal() and UserAuditService keep their exact signatures
 * and contracts — this listener is the only coupling point.
 */
#[AsEntityListener(event: Events::postPersist, method: 'postPersistAudit', entity: AuditEvent::class)]
#[AsEntityListener(event: Events::postPersist, method: 'postPersistUserAudit', entity: UserAuditEvent::class)]
final class AuditEventMirrorListener
{
    public function __construct(
        private readonly ActivityJournal $journal,
        private readonly AuditEventActivityMapper $mapper,
    ) {
    }

    public function postPersistAudit(AuditEvent $event, PostPersistEventArgs $args): void
    {
        if ($this->journal->isEnabled()) {
            $this->journal->record($this->mapper->fromAuditEvent($event), $args->getObjectManager()->getConnection());
        }
    }

    public function postPersistUserAudit(UserAuditEvent $event, PostPersistEventArgs $args): void
    {
        if ($this->journal->isEnabled()) {
            $this->journal->record($this->mapper->fromUserAuditEvent($event), $args->getObjectManager()->getConnection());
        }
    }
}
