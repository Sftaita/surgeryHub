<?php

namespace App\Service;

use App\Entity\Mission;
use App\Enum\MissionStatus;
use App\Enum\UncoveredReason;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Heuristic service that resolves why a deployed Mission is still OPEN (uncovered).
 * Best-effort — used only for notification labels; result is never persisted.
 */
class UncoveredReasonResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function resolveForMission(Mission $mission): UncoveredReason
    {
        $site = $mission->getSite();
        if ($site === null) {
            return UncoveredReason::NO_SITE_MEMBERSHIP;
        }

        $day = $mission->getStartAt()->format('Y-m-d');

        // 1. Are there any instrumentists affiliated with this site?
        $affiliated = (int) $this->em->createQuery(
            'SELECT COUNT(u.id) FROM App\Entity\User u
             JOIN u.siteMemberships sm
             WHERE sm.site = :site AND u.active = true AND u.roles LIKE :role'
        )
            ->setParameter('site', $site)
            ->setParameter('role', '%ROLE_INSTRUMENTIST%')
            ->getSingleScalarResult();

        if ($affiliated === 0) {
            return UncoveredReason::NO_SITE_MEMBERSHIP;
        }

        // 2. Are all of them absent that day?
        $absent = (int) $this->em->createQuery(
            'SELECT COUNT(DISTINCT u.id) FROM App\Entity\User u
             JOIN u.siteMemberships sm
             JOIN App\Entity\Absence a WITH a.user = u
             WHERE sm.site = :site AND u.active = true AND u.roles LIKE :role
               AND a.dateStart <= :day AND a.dateEnd >= :day'
        )
            ->setParameter('site', $site)
            ->setParameter('role', '%ROLE_INSTRUMENTIST%')
            ->setParameter('day', new \DateTimeImmutable($day))
            ->getSingleScalarResult();

        if ($absent >= $affiliated) {
            return UncoveredReason::ALL_ABSENT;
        }

        // 3. Do all non-absent instrumentists have a conflicting mission?
        $conflicting = (int) $this->em->createQuery(
            'SELECT COUNT(DISTINCT u.id) FROM App\Entity\User u
             JOIN u.siteMemberships sm
             JOIN App\Entity\Mission m2 WITH m2.instrumentist = u
             WHERE sm.site = :site AND u.active = true AND u.roles LIKE :role
               AND m2.id <> :missionId
               AND m2.startAt < :endAt AND m2.endAt > :startAt
               AND m2.status NOT IN (:excluded)'
        )
            ->setParameter('site', $site)
            ->setParameter('role', '%ROLE_INSTRUMENTIST%')
            ->setParameter('missionId', $mission->getId() ?? 0)
            ->setParameter('startAt', $mission->getStartAt())
            ->setParameter('endAt', $mission->getEndAt())
            ->setParameter('excluded', [MissionStatus::CANCELLED, MissionStatus::REJECTED])
            ->getSingleScalarResult();

        if ($conflicting >= ($affiliated - $absent)) {
            return UncoveredReason::ALL_IN_CONFLICT;
        }

        return UncoveredReason::MANUALLY_LEFT_OPEN;
    }
}
