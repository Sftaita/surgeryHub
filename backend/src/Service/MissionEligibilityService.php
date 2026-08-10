<?php

namespace App\Service;

use App\Dto\EligibilityResult;
use App\Entity\Absence;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\EligibilityReason;
use App\Enum\EmploymentType;
use App\Enum\MissionStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Single source of truth for mission eligibility decisions (ADR D-057).
 *
 * All eligibility checks MUST go through this service — never duplicate
 * rules in controllers, voters, notification handlers, or anywhere else.
 *
 * Performance contract (D-036): ≤ 3 DB queries for the batch methods
 * (findEligible, evaluateAllCandidates), regardless of candidate or mission count.
 */
class MissionEligibilityService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Evaluates whether a single candidate is eligible to claim a mission.
     * Runs up to 3 targeted DB queries (membership, absence, conflict) — the membership
     * query is skipped entirely for FREELANCER candidates (RC1-C).
     * Used by MissionPostDeployService::claim() before acquiring the pessimistic lock.
     */
    public function evaluate(Mission $mission, User $candidate): EligibilityResult
    {
        $reasons = [];

        // In-memory checks — no DB queries
        if (!$candidate->isActive()) {
            $reasons[] = EligibilityReason::INACTIVE;
        }
        if ($mission->getStatus() !== MissionStatus::OPEN) {
            $reasons[] = EligibilityReason::INCOMPATIBLE_STATUS;
        }
        if ($mission->getInstrumentist() !== null) {
            $reasons[] = EligibilityReason::ALREADY_ASSIGNED;
        }

        $site    = $mission->getSite();
        $startAt = $mission->getStartAt();
        $endAt   = $mission->getEndAt();

        if ($site !== null && $this->lacksSiteMembership($candidate, $site)) {
            $reasons[] = EligibilityReason::NO_SITE_MEMBERSHIP;
        }

        if ($startAt !== null && $this->isAbsentOn($candidate, $startAt)) {
            $reasons[] = EligibilityReason::ABSENT;
        }

        if ($startAt !== null && $endAt !== null
            && $this->hasScheduleConflict($candidate, $startAt, $endAt, $mission->getId() ?? 0)) {
            $reasons[] = EligibilityReason::SCHEDULE_CONFLICT;
        }

        return new EligibilityResult($candidate, $reasons);
    }

    /**
     * Single source of truth (D-101) for "can this candidate be assigned/reassigned to
     * this mission's current slot?" — used by every MANAGER-driven assign/reassign/
     * schedule-change path (MissionService::assignInstrumentistDraft,
     * MissionPostDeployService::assign()/reassign()/updateSchedule()/createPostDeploy(),
     * PlanningModificationService, PlanningGeneratorServiceV2's overrideLines branch).
     *
     * Deliberately checks only INACTIVE, ABSENT, SCHEDULE_CONFLICT — never
     * NO_SITE_MEMBERSHIP, and never INCOMPATIBLE_STATUS/ALREADY_ASSIGNED (evaluate()'s
     * OPEN/claim()-specific concerns, wrong here since the whole point of reassign is that
     * the mission already has someone, or isn't OPEN).
     *
     * NO_SITE_MEMBERSHIP is intentionally scoped to evaluate() (claim() self-service) and
     * to the candidate-listing methods (evaluateAllCandidates()/findEligible()) only — a
     * manager reassigning a mission has always been able to target any instrumentist
     * regardless of formal site affiliation (confirmed: no existing manager-facing
     * assign/reassign functional test ever set up a SiteMembership row), and this lot's
     * scope is strictly "an inactive/absent/already-busy person must never be assigned
     * silently" — not a new site-affiliation policy for manager overrides. Whether a
     * manager should ALSO be blocked from assigning outside site affiliation is an explicit,
     * separate business/UX decision, not decided by this lot.
     *
     * Reuses the exact same absence/conflict queries as evaluate() — no second
     * implementation of either check.
     *
     * $excludeMissionId defaults to the mission's own id (a schedule change on mission #42
     * must never conflict against mission #42 itself); callers evaluating a hypothetical
     * *new* slot not yet attached to this mission may pass a different id.
     */
    public function evaluateForReassignment(Mission $mission, User $candidate, ?int $excludeMissionId = null): EligibilityResult
    {
        $reasons = [];

        if (!$candidate->isActive()) {
            $reasons[] = EligibilityReason::INACTIVE;
        }

        $startAt = $mission->getStartAt();
        $endAt   = $mission->getEndAt();

        if ($startAt !== null && $this->isAbsentOn($candidate, $startAt)) {
            $reasons[] = EligibilityReason::ABSENT;
        }

        if ($startAt !== null && $endAt !== null) {
            $excludeId = $excludeMissionId ?? ($mission->getId() ?? 0);
            if ($this->hasScheduleConflict($candidate, $startAt, $endAt, $excludeId)) {
                $reasons[] = EligibilityReason::SCHEDULE_CONFLICT;
            }
        }

        return new EligibilityResult($candidate, $reasons);
    }

    /** Q1 — site membership. FREELANCER bypasses this requirement (D-057). */
    private function lacksSiteMembership(User $candidate, \App\Entity\Hospital $site): bool
    {
        if ($candidate->getEmploymentType() === EmploymentType::FREELANCER) {
            return false;
        }

        $count = (int) $this->em->createQuery(
            'SELECT COUNT(sm.id) FROM App\Entity\SiteMembership sm
             WHERE sm.user = :user AND sm.site = :site'
        )
            ->setParameter('user', $candidate)
            ->setParameter('site', $site)
            ->getSingleScalarResult();

        return $count === 0;
    }

    /** Q2 — approved absence covering the given day. */
    private function isAbsentOn(User $candidate, \DateTimeImmutable $startAt): bool
    {
        $day = new \DateTimeImmutable($startAt->format('Y-m-d'));
        $count = (int) $this->em->createQuery(
            'SELECT COUNT(a.id) FROM App\Entity\Absence a
             WHERE a.user = :user
               AND a.dateStart <= :day AND a.dateEnd >= :day'
        )
            ->setParameter('user', $candidate)
            ->setParameter('day', $day)
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Q3 — overlapping active mission (cross-site, cross-PlanningVersion by construction —
     * scoped by person only, matches PlanningConflictDetectionService::findConflict()'s
     * same end-exclusive overlap rule: A.startAt < B.endAt AND A.endAt > B.startAt).
     */
    private function hasScheduleConflict(User $candidate, \DateTimeImmutable $startAt, \DateTimeImmutable $endAt, int $excludeMissionId): bool
    {
        $count = (int) $this->em->createQuery(
            'SELECT COUNT(m.id) FROM App\Entity\Mission m
             WHERE m.instrumentist = :user
               AND m.id <> :missionId
               AND m.startAt < :endAt AND m.endAt > :startAt
               AND m.status NOT IN (:excluded)'
        )
            ->setParameter('user', $candidate)
            ->setParameter('missionId', $excludeMissionId)
            ->setParameter('startAt', $startAt)
            ->setParameter('endAt', $endAt)
            ->setParameter('excluded', [MissionStatus::CANCELLED, MissionStatus::REJECTED])
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Returns eligible candidates per site for pool notifications.
     * Uses exactly 3 DB queries for any number of open missions (D-036).
     *
     * @param  Mission[] $openMissions
     * @return array<int, User[]>  siteId → eligible User[]
     */
    public function findEligible(array $openMissions): array
    {
        if (empty($openMissions)) {
            return [];
        }

        // Collect unique sites from the open missions
        $siteById = [];
        foreach ($openMissions as $m) {
            $site = $m->getSite();
            if ($site !== null && $site->getId() !== null) {
                $siteById[$site->getId()] = $site;
            }
        }
        if (empty($siteById)) {
            return [];
        }

        // Q1 — every active ROLE_INSTRUMENTIST who is either a FREELANCER (eligible at any
        // site, bypassing the membership requirement — same rule evaluate() already applies,
        // RC1-C/D-057) or has a SiteMembership at one of the relevant sites.
        //
        // SiteMembership is joined as an unrelated ("theta") join rather than via
        // u.siteMemberships, and the site id is selected as a scalar (IDENTITY(sm.site))
        // rather than the fetch-joined entity — this way a FREELANCER with zero matching
        // memberships still comes back as exactly one row (siteId = NULL) instead of being
        // silently dropped by an INNER JOIN, and a partially-filtered collection is never
        // attached to the User entity (which would be misleading for callers reusing $u).
        $rows = $this->em->createQuery(
            'SELECT u AS user, IDENTITY(sm.site) AS siteId
             FROM App\Entity\User u
             LEFT JOIN App\Entity\SiteMembership sm ON sm.user = u AND sm.site IN (:sites)
             WHERE u.active = true AND u.roles LIKE :role
               AND (u.employmentType = :freelancer OR sm.id IS NOT NULL)'
        )
            ->setParameter('sites', array_values($siteById))
            ->setParameter('role', '%ROLE_INSTRUMENTIST%')
            ->setParameter('freelancer', EmploymentType::FREELANCER)
            ->getResult();

        if (empty($rows)) {
            return [];
        }

        $candidateById = []; // userId → User
        $usersBySiteId = []; // siteId → [userId => true, ...] (set — avoids duplicates)

        foreach ($rows as $row) {
            /** @var User $user */
            $user   = $row['user'];
            $userId = $user->getId();
            $candidateById[$userId] = $user;

            if ($user->getEmploymentType() === EmploymentType::FREELANCER) {
                // Eligible at every relevant site, regardless of membership.
                foreach ($siteById as $siteId => $_) {
                    $usersBySiteId[$siteId][$userId] = true;
                }
            } elseif ($row['siteId'] !== null) {
                $usersBySiteId[(int) $row['siteId']][$userId] = true;
            }
        }

        $candidates = array_values($candidateById);

        // Compute period bounds for batch queries
        $minDate    = null;
        $maxDate    = null;
        $minStartAt = null;
        $maxEndAt   = null;

        foreach ($openMissions as $m) {
            $startAt = $m->getStartAt();
            $endAt   = $m->getEndAt();
            if ($startAt === null || $endAt === null) {
                continue;
            }
            $day = new \DateTimeImmutable($startAt->format('Y-m-d'));
            if ($minDate === null || $day < $minDate) {
                $minDate = $day;
            }
            if ($maxDate === null || $day > $maxDate) {
                $maxDate = $day;
            }
            if ($minStartAt === null || $startAt < $minStartAt) {
                $minStartAt = $startAt;
            }
            if ($maxEndAt === null || $endAt > $maxEndAt) {
                $maxEndAt = $endAt;
            }
        }

        // Q2 — absences for all candidates covering any part of the period
        $absencesByUser = [];
        if ($minDate !== null && $maxDate !== null) {
            /** @var Absence[] $absences */
            $absences = $this->em->createQuery(
                'SELECT a FROM App\Entity\Absence a
                 WHERE a.user IN (:users)
                   AND a.dateStart <= :maxDate AND a.dateEnd >= :minDate'
            )
                ->setParameter('users', $candidates)
                ->setParameter('minDate', $minDate)
                ->setParameter('maxDate', $maxDate)
                ->getResult();

            foreach ($absences as $absence) {
                $uid = $absence->getUser()?->getId();
                if ($uid !== null) {
                    $absencesByUser[$uid][] = $absence;
                }
            }
        }

        // Q3 — conflicting assigned missions for all candidates in the period
        $conflictsByUser = [];
        if ($minStartAt !== null && $maxEndAt !== null) {
            /** @var Mission[] $conflicts */
            $conflicts = $this->em->createQuery(
                'SELECT m FROM App\Entity\Mission m
                 WHERE m.instrumentist IN (:users)
                   AND m.startAt < :maxEndAt AND m.endAt > :minStartAt
                   AND m.status NOT IN (:excluded)'
            )
                ->setParameter('users', $candidates)
                ->setParameter('minStartAt', $minStartAt)
                ->setParameter('maxEndAt', $maxEndAt)
                ->setParameter('excluded', [MissionStatus::CANCELLED, MissionStatus::REJECTED])
                ->getResult();

            foreach ($conflicts as $conflict) {
                $uid = $conflict->getInstrumentist()?->getId();
                if ($uid !== null) {
                    $conflictsByUser[$uid][] = $conflict;
                }
            }
        }

        // Group open missions by site for per-site PHP evaluation
        $missionsBySiteId = [];
        foreach ($openMissions as $m) {
            $siteId = $m->getSite()?->getId();
            if ($siteId !== null) {
                $missionsBySiteId[$siteId][] = $m;
            }
        }

        // For each site, collect candidates eligible for at least one mission there
        $eligibleBySiteId = [];
        foreach ($siteById as $siteId => $_) {
            foreach (array_keys($usersBySiteId[$siteId] ?? []) as $userId) {
                $candidate    = $candidateById[$userId];
                $siteMissions = $missionsBySiteId[$siteId] ?? [];

                foreach ($siteMissions as $mission) {
                    if ($this->isEligibleForMission($candidate, $mission, $absencesByUser, $conflictsByUser)) {
                        $eligibleBySiteId[$siteId][] = $candidate;
                        break;
                    }
                }
            }
        }

        return $eligibleBySiteId;
    }

    /**
     * Evaluates all active site members as potential candidates for a single mission.
     * Uses exactly 3 DB queries (D-036). Designed for the manager endpoint.
     *
     * Reports ABSENT and SCHEDULE_CONFLICT reasons per candidate.
     * Does not report ALREADY_ASSIGNED or INCOMPATIBLE_STATUS — those are mission-level
     * concerns the caller can surface independently.
     *
     * @return EligibilityResult[]
     */
    public function evaluateAllCandidates(Mission $mission): array
    {
        $site = $mission->getSite();
        if ($site === null) {
            return [];
        }

        // Q1 — all active ROLE_INSTRUMENTIST at the mission's site
        /** @var User[] $candidates */
        $candidates = $this->em->createQuery(
            'SELECT u FROM App\Entity\User u
             JOIN u.siteMemberships sm
             WHERE sm.site = :site AND u.active = true AND u.roles LIKE :role'
        )
            ->setParameter('site', $site)
            ->setParameter('role', '%ROLE_INSTRUMENTIST%')
            ->getResult();

        if (empty($candidates)) {
            return [];
        }

        $startAt = $mission->getStartAt();
        $endAt   = $mission->getEndAt();

        // Q2 — absences on the mission date
        $absencesByUser = [];
        if ($startAt !== null) {
            $day = new \DateTimeImmutable($startAt->format('Y-m-d'));
            /** @var Absence[] $absences */
            $absences = $this->em->createQuery(
                'SELECT a FROM App\Entity\Absence a
                 WHERE a.user IN (:users)
                   AND a.dateStart <= :day AND a.dateEnd >= :day'
            )
                ->setParameter('users', $candidates)
                ->setParameter('day', $day)
                ->getResult();

            foreach ($absences as $absence) {
                $uid = $absence->getUser()?->getId();
                if ($uid !== null) {
                    $absencesByUser[$uid][] = $absence;
                }
            }
        }

        // Q3 — overlapping assigned missions
        $conflictsByUser = [];
        if ($startAt !== null && $endAt !== null) {
            /** @var Mission[] $conflicts */
            $conflicts = $this->em->createQuery(
                'SELECT m FROM App\Entity\Mission m
                 WHERE m.instrumentist IN (:users)
                   AND m.id <> :missionId
                   AND m.startAt < :endAt AND m.endAt > :startAt
                   AND m.status NOT IN (:excluded)'
            )
                ->setParameter('users', $candidates)
                ->setParameter('missionId', $mission->getId() ?? 0)
                ->setParameter('startAt', $startAt)
                ->setParameter('endAt', $endAt)
                ->setParameter('excluded', [MissionStatus::CANCELLED, MissionStatus::REJECTED])
                ->getResult();

            foreach ($conflicts as $conflict) {
                $uid = $conflict->getInstrumentist()?->getId();
                if ($uid !== null) {
                    $conflictsByUser[$uid][] = $conflict;
                }
            }
        }

        $missionDay = $startAt !== null
            ? new \DateTimeImmutable($startAt->format('Y-m-d'))
            : null;

        $results = [];
        foreach ($candidates as $candidate) {
            $uid     = $candidate->getId();
            $reasons = [];

            if ($missionDay !== null) {
                foreach ($absencesByUser[$uid] ?? [] as $absence) {
                    if ($absence->getDateStart() <= $missionDay && $absence->getDateEnd() >= $missionDay) {
                        $reasons[] = EligibilityReason::ABSENT;
                        break;
                    }
                }
            }

            if ($startAt !== null && $endAt !== null) {
                foreach ($conflictsByUser[$uid] ?? [] as $conflict) {
                    if ($conflict->getStartAt() < $endAt && $conflict->getEndAt() > $startAt) {
                        $reasons[] = EligibilityReason::SCHEDULE_CONFLICT;
                        break;
                    }
                }
            }

            $results[] = new EligibilityResult($candidate, $reasons);
        }

        return $results;
    }

    private function isEligibleForMission(
        User    $candidate,
        Mission $mission,
        array   $absencesByUser,
        array   $conflictsByUser,
    ): bool {
        $userId  = $candidate->getId();
        $startAt = $mission->getStartAt();
        $endAt   = $mission->getEndAt();

        if ($startAt === null || $endAt === null) {
            return false;
        }

        $missionDay = new \DateTimeImmutable($startAt->format('Y-m-d'));

        foreach ($absencesByUser[$userId] ?? [] as $absence) {
            if ($absence->getDateStart() <= $missionDay && $absence->getDateEnd() >= $missionDay) {
                return false;
            }
        }

        foreach ($conflictsByUser[$userId] ?? [] as $conflict) {
            if ($conflict->getStartAt() < $endAt && $conflict->getEndAt() > $startAt) {
                return false;
            }
        }

        return true;
    }
}
