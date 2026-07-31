<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Single authority for "is this person absent during this period" — the one temporal-
 * overlap rule every Planning V2 collaborator that reasons about absences should answer
 * through here rather than re-deriving its own comparison.
 *
 * Semantics (matches Absence's own model — see docs/decisions.md D-050): `Absence.dateStart`/
 * `dateEnd` are whole-day, inclusive-both-ends bounds (`date_immutable`, no time-of-day
 * component — a "partial day" absence cannot be represented today). Two ranges overlap when
 * `absence.dateStart <= missionEnd AND absence.dateEnd >= missionStart`, compared at day
 * granularity. There is no status field on `Absence` — every row is, by construction, a
 * confirmed absence (no pending/validated/rejected/cancelled distinction exists in this
 * model today; a deleted row is the only way an absence stops applying).
 *
 * `PlanningGeneratorServiceV2::isAbsentFast()`, `AbsenceMissionReactionService::findOverlapping()`
 * and `AbsenceImpactService::findOverlappingMissions()` each independently implement this same
 * inclusive-day-bounds comparison (verified consistent — see docs/decisions.md D-089 audit
 * note) for their own performance profile (in-memory map for the generator's per-day loop vs.
 * a single DQL query here) — this class is the version new code should call, and the
 * canonical reference for what "overlap" means if those are ever consolidated onto it.
 */
class AbsenceOverlapService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * True if $user has at least one Absence row overlapping [$start, $end] (compared at
     * day granularity — a mission any time on a given calendar day counts as occurring
     * "on" that day for absence purposes, consistent with the whole-day Absence model).
     */
    public function isUserAbsentDuring(User $user, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        $startDay = $start->format('Y-m-d');
        $endDay   = $end->format('Y-m-d');

        $count = (int) $this->em->createQuery(
            'SELECT COUNT(a.id) FROM App\Entity\Absence a
             WHERE a.user = :user AND a.dateStart <= :endDay AND a.dateEnd >= :startDay'
        )
            ->setParameter('user', $user)
            ->setParameter('startDay', $startDay)
            ->setParameter('endDay', $endDay)
            ->getSingleScalarResult();

        return $count > 0;
    }
}
