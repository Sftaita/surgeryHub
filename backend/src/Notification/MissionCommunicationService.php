<?php

namespace App\Notification;

use App\Activity\ActivityJournal;
use App\Activity\CorrelationContext;
use App\Entity\Mission;
use App\Entity\MissionCommunication;
use App\Entity\NotificationDispatch;
use App\Entity\OutboundNotification;
use App\Entity\User;
use App\Enum\MissionCommunicationSource;
use App\Enum\MissionCommunicationWithdrawal;
use Doctrine\ORM\EntityManagerInterface;

/**
 * H4 (D-130) — the mission_communication registry.
 *
 * Activation happens ONLY on proof that a channel reached the person:
 *   - NotificationDispatcher, right after an in-app item was created or a push accepted;
 *   - OutboundNotificationService::recordEmailAttempt(success), when the relay accepted the
 *     email (possibly long after the worker queued it);
 *   - a self action (claim) — the person did it herself.
 * A queued email, a skipped or a failed channel never activates anything.
 *
 * Idempotent: an ACTIVE row for (mission, person, role) is kept (the earliest proof wins);
 * a mission the person no longer holds when the proof arrives (released meanwhile) is not
 * activated. Withdrawal never deletes: it closes the active rows.
 */
class MissionCommunicationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CorrelationContext $correlation,
    ) {
    }

    /** Called once a delivery of $dispatch actually reached its recipient. */
    public function activateFromDispatch(NotificationDispatch $dispatch): void
    {
        $spec = $dispatch->getCommunicates();
        $user = $dispatch->getRecipient();
        if ($spec === null || $user === null) {
            return;
        }
        $spec = MissionCommunicationSpec::fromArray($spec);
        foreach ($spec->missionIds as $missionId) {
            $mission = $this->em->find(Mission::class, $missionId);
            if ($mission !== null) {
                $this->activate($mission, $user, $spec->role, $spec->source, $dispatch);
            }
        }
    }

    /** An email accepted by the relay: every dispatch it served is proven. */
    public function activateForDelivery(OutboundNotification $delivery): void
    {
        $ids = $this->em->getConnection()->fetchFirstColumn(
            'SELECT d.dispatch_id FROM notification_dispatch_delivery d JOIN notification_dispatch n ON n.id = d.dispatch_id
             WHERE d.outbound_notification_id = ? AND n.communicates IS NOT NULL',
            [$delivery->getId()],
        );
        foreach ($ids as $id) {
            $dispatch = $this->em->find(NotificationDispatch::class, (int) $id);
            if ($dispatch !== null) {
                $this->activateFromDispatch($dispatch);
            }
        }
    }

    public function activate(Mission $mission, User $user, string $role, MissionCommunicationSource $source, ?NotificationDispatch $dispatch = null): ?MissionCommunication
    {
        $holder = $role === MissionCommunicationSpec::ROLE_SURGEON ? $mission->getSurgeon() : $mission->getInstrumentist();
        if ($holder?->getId() !== $user->getId()) {
            return null; // she no longer holds it (released / reassigned before the proof arrived)
        }
        $active = $this->active($mission, $user, $role);
        if ($active !== null) {
            return $active;
        }

        $row = MissionCommunication::open(
            $mission, $user, (string) ActivityJournal::displayName($user), $role, $source,
            new \DateTimeImmutable(), $this->correlation->correlationId(), $dispatch,
        );
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    /** The person leaves the Mission: close what had been communicated to her about it. */
    public function withdraw(Mission $mission, User $user, MissionCommunicationWithdrawal $reason, ?string $role = null): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c')->from(MissionCommunication::class, 'c')
            ->where('c.missionIdSnapshot = :mission')->andWhere('c.user = :user')->andWhere('c.withdrawnAt IS NULL')
            ->setParameter('mission', $mission->getId())->setParameter('user', $user);
        if ($role !== null) {
            $qb->andWhere('c.role = :role')->setParameter('role', $role);
        }
        $rows = $qb->getQuery()->getResult();
        $now = new \DateTimeImmutable();
        foreach ($rows as $row) {
            $row->withdraw($reason, $now);
        }

        return count($rows);
    }

    public function active(Mission $mission, User $user, string $role): ?MissionCommunication
    {
        return $this->em->createQueryBuilder()
            ->select('c')->from(MissionCommunication::class, 'c')
            ->where('c.missionIdSnapshot = :mission')->andWhere('c.user = :user')
            ->andWhere('c.role = :role')->andWhere('c.withdrawnAt IS NULL')
            ->setParameter('mission', $mission->getId())->setParameter('user', $user)->setParameter('role', $role)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
