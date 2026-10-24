<?php

namespace App\Service;

use App\Dto\EligibilityResult;
use App\Dto\ProposedSlot;
use App\Dto\ScheduleConflictDetail;
use App\Entity\Absence;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\EligibilityEnforcementPolicy;
use App\Enum\EligibilityReason;
use App\Enum\EmploymentType;
use App\Enum\MissionAssignmentEndReason;
use App\Enum\MissionStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Clock;

/**
 * Single source of truth for mission eligibility decisions (ADR D-057).
 *
 * All eligibility checks MUST go through this service — never duplicate
 * rules in controllers, voters, notification handlers, or anywhere else.
 *
 * Performance contract (D-036): a constant number of DB queries for the batch methods
 * (findEligibleByMission, evaluateAllCandidates), regardless of candidate or mission count —
 * 3 DQL queries plus, since D-151/D-152, one DBAL UNION query for the surgeon's exclusions;
 * never one per candidate or per mission.
 *
 * D-151 / D-152 — three more reasons: REFUSED_FOR_MISSION (the mission's surgeon refused this
 * person's affectation to THIS mission — MissionAssignment.endReason) and
 * SURGEON_INCOMPATIBILITY (an active incompatibility with the mission's surgeon), both on every
 * path (claim, offer, assignment, pool solicitation, manager pickers, planning roster) and
 * blocking under every policy; MISSION_ALREADY_STARTED (claim and pool solicitation only: a
 * started mission is never taken from the pool, while a manager can still assign or correct it).
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
        if (self::hasStarted($mission, Clock::get()->now())) {
            $reasons[] = EligibilityReason::MISSION_ALREADY_STARTED;
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

        // D-151 / D-152 — refused on this very mission, or incompatible with its surgeon.
        array_push($reasons, ...$this->exclusionReasons($mission, $candidate));

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
     * to the candidate-listing methods (evaluateAllCandidates()/findEligibleByMission()) only — a
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

        // D-151 / D-152 — never bypassed by a manager assignment, a reassignment or an offer.
        array_push($reasons, ...$this->exclusionReasons($mission, $candidate));

        return new EligibilityResult($candidate, $reasons);
    }

    /**
     * D-125 — "can this instrumentist be sent a nominative request (TARGETED publication)
     * for this mission's slot?". The offer only makes sense if its target will be able to
     * accept it: accepting IS the existing claim(), gated by evaluate() — so this checks the
     * same person-level reasons evaluate() does (INACTIVE, NO_SITE_MEMBERSHIP, ABSENT,
     * SCHEDULE_CONFLICT), minus evaluate()'s mission-state reasons (INCOMPATIBLE_STATUS/
     * ALREADY_ASSIGNED — the mission is typically still DRAFT at offer time). Reuses
     * evaluateForReassignment() for INACTIVE/ABSENT/SCHEDULE_CONFLICT — no second
     * implementation of any check.
     */
    public function evaluateForOffer(Mission $mission, User $candidate): EligibilityResult
    {
        $reasons = $this->evaluateForReassignment($mission, $candidate)->reasons;

        $site = $mission->getSite();
        if ($site !== null && $this->lacksSiteMembership($candidate, $site)) {
            $reasons[] = EligibilityReason::NO_SITE_MEMBERSHIP;
        }

        return new EligibilityResult($candidate, $reasons);
    }

    /**
     * D-151 / D-152 — why this candidate is excluded from this very mission by its surgeon:
     * REFUSED_FOR_MISSION (her affectation to it was refused) and/or SURGEON_INCOMPATIBILITY
     * (an active incompatibility with its surgeon). One query. The incompatibility is read by
     * surgeon, so it applies to a mission not persisted yet too (new planning line, post-deploy
     * addition); a refusal only exists on a persisted mission (D-150).
     *
     * @return EligibilityReason[]
     */
    public function exclusionReasons(Mission $mission, User $candidate): array
    {
        $surgeonId = $mission->getSurgeon()?->getId();
        if ($candidate->getId() === null || ($mission->getId() === null && $surgeonId === null)) {
            return [];
        }

        $reasons = $this->em->getConnection()->fetchFirstColumn(
            "SELECT 'REFUSED_FOR_MISSION' FROM mission_assignment
              WHERE mission_id = ? AND instrumentist_id = ? AND end_reason = ?
             UNION ALL
             SELECT 'SURGEON_INCOMPATIBILITY' FROM surgeon_instrumentist_incompatibility
              WHERE surgeon_id = ? AND instrumentist_id = ? AND lifted_at IS NULL",
            [$mission->getId() ?? 0, $candidate->getId(), MissionAssignmentEndReason::REFUSED_BY_SURGEON->value, $surgeonId ?? 0, $candidate->getId()],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return array_values(array_unique(array_map(static fn ($r) => EligibilityReason::from((string) $r), $reasons), SORT_REGULAR));
    }

    /** D-152 — an active incompatibility between this surgeon and this instrumentist. */
    public function isIncompatible(User $surgeon, User $instrumentist): bool
    {
        if ($surgeon->getId() === null || $instrumentist->getId() === null) {
            return false;
        }

        return (bool) $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM surgeon_instrumentist_incompatibility WHERE surgeon_id = ? AND instrumentist_id = ? AND lifted_at IS NULL',
            [$surgeon->getId(), $instrumentist->getId()],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );
    }

    /**
     * D-152 — every active incompatible pair of these surgeons, in one query, as
     * "surgeonId-instrumentistId" keys (the table's active_pair_key). For bulk readers that
     * must not query per line (planning V2 preview, recurring posts list).
     *
     * @param int[] $surgeonIds
     * @return array<string, true>
     */
    public function activeIncompatiblePairs(array $surgeonIds): array
    {
        $surgeonIds = array_values(array_unique(array_map('intval', array_filter($surgeonIds))));
        if ($surgeonIds === []) {
            return [];
        }

        $keys = $this->em->getConnection()->fetchFirstColumn(
            'SELECT CONCAT(surgeon_id, \'-\', instrumentist_id) FROM surgeon_instrumentist_incompatibility
              WHERE lifted_at IS NULL AND surgeon_id IN (?)',
            [$surgeonIds],
            [ArrayParameterType::INTEGER],
        );

        return array_fill_keys(array_map('strval', $keys), true);
    }

    /**
     * D-152 — same question as isIncompatible(), as a LOCKING read (FOR SHARE) on the
     * (surgeon, instrumentist) index range, for callers inside a transaction that are about to
     * affect this instrumentist (claim, assignments). A concurrent creation of the
     * incompatibility either commits first (seen here → refused) or waits for this transaction
     * (and then finds the new affectation as a conflict to raise): never a silent overlap.
     */
    public function isIncompatibleLocked(User $surgeon, User $instrumentist): bool
    {
        if ($surgeon->getId() === null || $instrumentist->getId() === null) {
            return false;
        }

        return (bool) $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM surgeon_instrumentist_incompatibility WHERE surgeon_id = ? AND instrumentist_id = ? AND lifted_at IS NULL FOR SHARE',
            [$surgeon->getId(), $instrumentist->getId()],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );
    }

    /**
     * D-151 / D-152 — one query for any number of persisted missions:
     * missionId → userId → EligibilityReason[] (refused on that mission, incompatible with its surgeon).
     *
     * @param Mission[] $missions
     * @return array<int, array<int, EligibilityReason[]>>
     */
    private function exclusionsByMission(array $missions): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (Mission $m) => $m->getId(), $missions))));
        if ($ids === []) {
            return [];
        }

        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT a.mission_id AS mission_id, a.instrumentist_id AS user_id, 'REFUSED_FOR_MISSION' AS reason
               FROM mission_assignment a
              WHERE a.mission_id IN (?) AND a.end_reason = ?
             UNION ALL
             SELECT m.id, i.instrumentist_id, 'SURGEON_INCOMPATIBILITY'
               FROM surgeon_instrumentist_incompatibility i
               JOIN mission m ON m.surgeon_id = i.surgeon_id
              WHERE m.id IN (?) AND i.lifted_at IS NULL",
            [$ids, MissionAssignmentEndReason::REFUSED_BY_SURGEON->value, $ids],
            [ArrayParameterType::INTEGER, ParameterType::STRING, ArrayParameterType::INTEGER],
        );

        $exclusions = [];
        foreach ($rows as $row) {
            $exclusions[(int) $row['mission_id']][(int) $row['user_id']][] = EligibilityReason::from((string) $row['reason']);
        }

        return $exclusions;
    }

    /**
     * D-152 — instrumentists actively incompatible with $surgeon: userId → true. One query.
     *
     * @return array<int, true>
     */
    private function incompatibleInstrumentistsOf(User $surgeon): array
    {
        if ($surgeon->getId() === null) {
            return [];
        }

        $ids = $this->em->getConnection()->fetchFirstColumn(
            'SELECT instrumentist_id FROM surgeon_instrumentist_incompatibility WHERE surgeon_id = ? AND lifted_at IS NULL',
            [$surgeon->getId()],
            [ParameterType::INTEGER],
        );

        return array_fill_keys(array_map('intval', $ids), true);
    }

    /** D-151 — a mission whose start time has passed can no longer be claimed from the pool. */
    public static function hasStarted(Mission $mission, \DateTimeImmutable $now): bool
    {
        $startAt = $mission->getStartAt();

        return $startAt !== null && $startAt <= $now;
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
     * BUG A (2026-09-09) — same query as isAbsentOn(), but returning the actual Absence row
     * instead of a boolean, so a caller building a structured error response (which day
     * exactly, which absence id) never has to re-derive or duplicate this matching rule.
     * Absence periods for one user are not expected to overlap in practice; if they somehow
     * do, the earliest (lowest id) is returned — an arbitrary but stable and harmless choice,
     * since it only feeds a UI message, never a business decision.
     */
    public function findBlockingAbsence(User $candidate, \DateTimeImmutable $startAt): ?Absence
    {
        $day = new \DateTimeImmutable($startAt->format('Y-m-d'));

        return $this->em->createQuery(
            'SELECT a FROM App\Entity\Absence a
             WHERE a.user = :user
               AND a.dateStart <= :day AND a.dateEnd >= :day
             ORDER BY a.id ASC'
        )
            ->setParameter('user', $candidate)
            ->setParameter('day', $day)
            ->setMaxResults(1)
            ->getOneOrNullResult();
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
     * H3 (D-129) — eligible candidates PER MISSION for pool solicitation: exactly the people
     * who could take THAT mission (missionId → User[]), never a per-site union. This is the
     * only candidate list OpenMissionPoolNotifier may use — the former per-site findEligible()
     * (which let a person receive missions she was in conflict with) is gone.
     *
     * Same rules as evaluate() / claim()'s pre-lock gate (D-057): active ROLE_INSTRUMENTIST,
     * site membership or FREELANCER, not absent that day, no overlapping non-cancelled
     * mission. A mission that is not OPEN, or already has an instrumentist, has no candidates
     * (mission-level ALREADY_ASSIGNED / INCOMPATIBLE_STATUS — never solicit for it).
     *
     * Exactly 3 DB queries whatever the number of missions and candidates (D-036): no N+1.
     *
     * @param  Mission[] $missions
     * @return array<int, User[]>  missionId → eligible User[] (every input mission id is a key)
     */
    public function findEligibleByMission(array $missions): array
    {
        $now    = Clock::get()->now();
        $result = [];
        $openMissions = [];
        foreach ($missions as $m) {
            if ($m->getId() === null) {
                continue;
            }
            $result[$m->getId()] = [];
            // D-151 — a started mission is never offered to the pool any more.
            if ($m->getStatus() === MissionStatus::OPEN && $m->getInstrumentist() === null && !self::hasStarted($m, $now)) {
                $openMissions[] = $m;
            }
        }
        if (empty($openMissions)) {
            return $result;
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
            return $result;
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
            return $result;
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

        // Q4 (D-151 / D-152) — people refused on each mission, or incompatible with its surgeon.
        $exclusions = $this->exclusionsByMission($openMissions);

        // Per-mission PHP evaluation over the preloaded data — no further query.
        foreach ($openMissions as $mission) {
            $siteId = $mission->getSite()?->getId();
            if ($siteId === null) {
                continue;
            }
            foreach (array_keys($usersBySiteId[$siteId] ?? []) as $userId) {
                if (isset($exclusions[(int) $mission->getId()][$userId])) {
                    continue;
                }
                $candidate = $candidateById[$userId];
                if ($this->isEligibleForMission($candidate, $mission, $absencesByUser, $conflictsByUser)) {
                    $result[$mission->getId()][] = $candidate;
                }
            }
        }

        return $result;
    }

    /**
     * Evaluates all site members as potential candidates for a single mission — the
     * mission-scoped candidate LIST used by manager-facing pickers (D-102, Lot 2).
     * Uses exactly 3 DB queries (D-036). Designed for the manager endpoint.
     *
     * Reports INACTIVE, ABSENT and SCHEDULE_CONFLICT reasons per candidate — deliberately
     * includes inactive site members in the pool (rather than excluding them from the
     * query, as before Lot 2) so a deactivated account can be shown as a disabled ghost
     * ("Compte inactif") instead of silently disappearing. Never reports
     * NO_SITE_MEMBERSHIP: this method's candidate pool is site-membership-scoped by
     * construction (the JOIN below), so every candidate it can ever return already has
     * one — structurally impossible to be the reason here. Does not report
     * ALREADY_ASSIGNED or INCOMPATIBLE_STATUS — those are mission-level concerns the
     * caller can surface independently.
     *
     * @return EligibilityResult[]
     */
    public function evaluateAllCandidates(Mission $mission): array
    {
        $site = $mission->getSite();
        if ($site === null) {
            return [];
        }

        // Q1 — all ROLE_INSTRUMENTIST at the mission's site (active or not — Lot 2).
        /** @var User[] $candidates */
        $candidates = $this->em->createQuery(
            'SELECT u FROM App\Entity\User u
             JOIN u.siteMemberships sm
             WHERE sm.site = :site AND u.roles LIKE :role'
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

        // Q4 (D-151 / D-152) — people refused on this mission, or incompatible with its surgeon.
        $exclusions = $mission->getId() !== null
            ? ($this->exclusionsByMission([$mission])[(int) $mission->getId()] ?? [])
            : array_map(static fn () => [EligibilityReason::SURGEON_INCOMPATIBILITY],
                $mission->getSurgeon() !== null ? $this->incompatibleInstrumentistsOf($mission->getSurgeon()) : []);

        $results = [];
        foreach ($candidates as $candidate) {
            $uid     = $candidate->getId();
            $reasons = [];
            $matchedAbsence  = null;
            $matchedConflict = null;

            if (!$candidate->isActive()) {
                $reasons[] = EligibilityReason::INACTIVE;
            }
            array_push($reasons, ...($exclusions[$uid] ?? []));

            if ($missionDay !== null) {
                foreach ($absencesByUser[$uid] ?? [] as $absence) {
                    if ($absence->getDateStart() <= $missionDay && $absence->getDateEnd() >= $missionDay) {
                        $reasons[] = EligibilityReason::ABSENT;
                        $matchedAbsence = $absence;
                        break;
                    }
                }
            }

            if ($startAt !== null && $endAt !== null) {
                foreach ($conflictsByUser[$uid] ?? [] as $conflict) {
                    if ($conflict->getStartAt() < $endAt && $conflict->getEndAt() > $startAt) {
                        $reasons[] = EligibilityReason::SCHEDULE_CONFLICT;
                        $matchedConflict = $conflict;
                        break;
                    }
                }
            }

            $results[] = new EligibilityResult($candidate, $reasons, $matchedAbsence, $matchedConflict);
        }

        return $results;
    }

    /**
     * D-102 (Lot 2) — evaluates the FULL active-instrumentist roster (not site-scoped —
     * matches the existing, historically unscoped `GET /api/instrumentists` picker used
     * by the Preview Editor and Mode Modification) against a HYPOTHETICAL slot that may
     * not have a persisted Mission yet (a new preview line, a bulk-assign target, a
     * Mode Modification "add mission" draft). Reports INACTIVE (roster is
     * already active-only, so structurally never fires — kept for interface symmetry
     * with evaluateAllCandidates()/EligibilityReason completeness), NO_SITE_MEMBERSHIP,
     * ABSENT, SCHEDULE_CONFLICT. 4 DB queries (roster, membership, absence, conflict) —
     * the same batch-query discipline as findEligibleByMission()/evaluateAllCandidates() (D-036),
     * one more query than those because this method is not pre-scoped to a single site
     * by its JOIN the way evaluateAllCandidates() is.
     *
     * $excludeMissionId — the mission being edited, if any (so it never conflicts
     * against its own current slot). Null for a genuinely new line/mission.
     *
     * D-165 — $proposals: the editor's other unsaved lines of that day. An edited existing
     * mission is evaluated at its PROPOSED slot/instrumentist (its persisted row no longer
     * counts; a cancelled one no longer counts at all), and a new line with an instrumentist
     * counts like a mission. Same half-open overlap rule [start, end) as everywhere
     * (A.start < B.end AND B.start < A.end): 07:45–14:00 and 14:00–18:00 never conflict. A
     * conflict with a persisted mission is reported first (it is real); otherwise the
     * proposed line is reported with source PROPOSED. At most 3 more queries (names, statuses).
     *
     * @param ProposedSlot[] $proposals
     * @return EligibilityResult[]
     */
    public function evaluateRoster(
        ?Hospital $site,
        \DateTimeImmutable $startAt,
        \DateTimeImmutable $endAt,
        ?int $excludeMissionId = null,
        // D-152 — the slot's surgeon, when known: his incompatible instrumentists are reported
        // (SURGEON_INCOMPATIBILITY, blocking) — one more query.
        ?User $surgeon = null,
        array $proposals = [],
    ): array {
        // Q1 — full active ROLE_INSTRUMENTIST roster (matches GET /api/instrumentists?active=true).
        /** @var User[] $candidates */
        $candidates = $this->em->createQuery(
            'SELECT u FROM App\Entity\User u
             WHERE u.active = true AND u.roles LIKE :role'
        )
            ->setParameter('role', '%ROLE_INSTRUMENTIST%')
            ->getResult();

        if (empty($candidates)) {
            return [];
        }

        // Q2 — site membership, only for non-FREELANCER candidates (D-057) when a site is given.
        $membershipUserIds = [];
        if ($site !== null) {
            $nonFreelancers = array_values(array_filter(
                $candidates,
                static fn (User $u) => $u->getEmploymentType() !== EmploymentType::FREELANCER,
            ));
            if (!empty($nonFreelancers)) {
                $rows = $this->em->createQuery(
                    'SELECT IDENTITY(sm.user) AS userId FROM App\Entity\SiteMembership sm
                     WHERE sm.user IN (:users) AND sm.site = :site'
                )
                    ->setParameter('users', $nonFreelancers)
                    ->setParameter('site', $site)
                    ->getResult();
                foreach ($rows as $row) {
                    $membershipUserIds[(int) $row['userId']] = true;
                }
            }
        }

        $day = new \DateTimeImmutable($startAt->format('Y-m-d'));

        // Q3 — absences covering that day
        /** @var Absence[] $absences */
        $absences = $this->em->createQuery(
            'SELECT a FROM App\Entity\Absence a
             WHERE a.user IN (:users) AND a.dateStart <= :day AND a.dateEnd >= :day'
        )
            ->setParameter('users', $candidates)
            ->setParameter('day', $day)
            ->getResult();
        $absencesByUser = [];
        foreach ($absences as $absence) {
            $uid = $absence->getUser()?->getId();
            if ($uid !== null) {
                $absencesByUser[$uid][] = $absence;
            }
        }

        // Q4 — overlapping active missions (D-165: minus those the editor is re-proposing)
        $overriddenIds = array_values(array_unique(array_filter(
            array_map(static fn (ProposedSlot $p) => $p->missionId, $proposals),
            static fn (?int $id) => $id !== null,
        )));
        /** @var Mission[] $conflicts */
        $conflicts = $this->em->createQuery(
            'SELECT m FROM App\Entity\Mission m
             WHERE m.instrumentist IN (:users)
               AND m.id <> :missionId
               AND m.startAt < :endAt AND m.endAt > :startAt
               AND m.status NOT IN (:excluded)'
            . ($overriddenIds !== [] ? ' AND m.id NOT IN (:overridden)' : '')
        )
            ->setParameter('users', $candidates)
            ->setParameter('missionId', $excludeMissionId ?? 0)
            ->setParameter('startAt', $startAt)
            ->setParameter('endAt', $endAt)
            ->setParameter('excluded', [MissionStatus::CANCELLED, MissionStatus::REJECTED]);
        if ($overriddenIds !== []) {
            $conflicts->setParameter('overridden', $overriddenIds);
        }
        $conflicts = $conflicts->getResult();
        $conflictsByUser = [];
        foreach ($conflicts as $conflict) {
            $uid = $conflict->getInstrumentist()?->getId();
            if ($uid !== null) {
                $conflictsByUser[$uid][] = $conflict;
            }
        }

        $proposedConflictsByUser = $this->proposedConflicts($proposals, $startAt, $endAt, $excludeMissionId);

        $incompatible = $surgeon !== null ? $this->incompatibleInstrumentistsOf($surgeon) : [];

        $results = [];
        foreach ($candidates as $candidate) {
            $uid     = $candidate->getId();
            $reasons = [];
            $matchedAbsence  = null;
            $matchedConflict = null;

            if ($site !== null
                && $candidate->getEmploymentType() !== EmploymentType::FREELANCER
                && !isset($membershipUserIds[$uid])
            ) {
                $reasons[] = EligibilityReason::NO_SITE_MEMBERSHIP;
            }

            if (isset($incompatible[$uid])) {
                $reasons[] = EligibilityReason::SURGEON_INCOMPATIBILITY;
            }

            foreach ($absencesByUser[$uid] ?? [] as $absence) {
                if ($absence->getDateStart() <= $day && $absence->getDateEnd() >= $day) {
                    $reasons[] = EligibilityReason::ABSENT;
                    $matchedAbsence = $absence;
                    break;
                }
            }

            foreach ($conflictsByUser[$uid] ?? [] as $conflict) {
                if ($conflict->getStartAt() < $endAt && $conflict->getEndAt() > $startAt) {
                    $reasons[] = EligibilityReason::SCHEDULE_CONFLICT;
                    $matchedConflict = $conflict;
                    break;
                }
            }

            $proposedConflict = null;
            if ($matchedConflict === null && isset($proposedConflictsByUser[$uid])) {
                $reasons[] = EligibilityReason::SCHEDULE_CONFLICT;
                $proposedConflict = $proposedConflictsByUser[$uid];
            }

            $results[] = new EligibilityResult($candidate, $reasons, $matchedAbsence, $matchedConflict, $proposedConflict);
        }

        return $results;
    }

    /**
     * D-165 — the first proposed line overlapping [$startAt, $endAt) per instrumentist,
     * resolved to a displayable detail (surgeon/site names: one query each, only if needed).
     *
     * @param ProposedSlot[] $proposals
     * @return array<int, ScheduleConflictDetail>
     */
    private function proposedConflicts(array $proposals, \DateTimeImmutable $startAt, \DateTimeImmutable $endAt, ?int $excludeMissionId): array
    {
        /** @var array<int, ProposedSlot> $byUser */
        $byUser = [];
        foreach ($proposals as $p) {
            if ($p->cancelled || $p->instrumentistId === null || isset($byUser[$p->instrumentistId])
                || ($p->missionId !== null && $p->missionId === $excludeMissionId)) {
                continue;
            }
            if ($p->startAt < $endAt && $startAt < $p->endAt) {
                $byUser[$p->instrumentistId] = $p;
            }
        }
        if ($byUser === []) {
            return [];
        }

        $names = function (string $sql, array $ids): array {
            $ids = array_values(array_unique(array_filter($ids)));
            if ($ids === []) {
                return [];
            }
            $rows = $this->em->getConnection()->fetchAllKeyValue($sql, [$ids], [ArrayParameterType::INTEGER]);

            return array_map(static fn ($n) => trim((string) $n), $rows);
        };
        $surgeons = $names("SELECT id, CONCAT(COALESCE(firstname, ''), ' ', COALESCE(lastname, '')) FROM user WHERE id IN (?)",
            array_map(static fn (ProposedSlot $p) => $p->surgeonId, $byUser));
        $sites = $names('SELECT id, name FROM hospital WHERE id IN (?)',
            array_map(static fn (ProposedSlot $p) => $p->siteId, $byUser));

        $missionStatuses = [];
        $missionIds = array_values(array_filter(array_map(static fn (ProposedSlot $p) => $p->missionId, $byUser)));
        if ($missionIds !== []) {
            $missionStatuses = $this->em->getConnection()->fetchAllKeyValue(
                'SELECT id, status FROM mission WHERE id IN (?)', [$missionIds], [ArrayParameterType::INTEGER],
            );
        }

        return array_map(static fn (ProposedSlot $p) => new ScheduleConflictDetail(
            $p->missionId,
            $p->missionId !== null ? ($missionStatuses[$p->missionId] ?? null) : null,
            $p->siteId !== null ? (($sites[$p->siteId] ?? '') ?: null) : null,
            $p->surgeonId !== null ? (($surgeons[$p->surgeonId] ?? '') ?: null) : null,
            $p->startAt,
            $p->endAt,
            ScheduleConflictDetail::SOURCE_PROPOSED,
        ), $byUser);
    }

    /**
     * D-102 (Lot 2) — single, canonical candidate serialization for every manager-facing
     * instrumentist picker (`GET /api/missions/{id}/eligible-instrumentists`,
     * `GET /api/planning/alerts/{id}/eligible-instrumentists`, the Preview Editor roster
     * endpoint). `eligible` is the raw, policy-agnostic fact (empty reasons); `selectable`
     * is contextualized under `$policy` (D-101's `EligibilityEnforcementPolicy` — the
     * SAME enum, never a frontend-side reimplementation of which reasons block where).
     * `reasons` always lists everything found, even when non-blocking under this policy,
     * so a UI can still show an informational badge (e.g. a non-blocking
     * SCHEDULE_CONFLICT warning under PLANNING_MODIFICATION).
     */
    public function serializeCandidate(EligibilityResult $result, EligibilityEnforcementPolicy $policy): array
    {
        $candidate = $result->candidate;
        $name      = trim(($candidate->getFirstname() ?? '') . ' ' . ($candidate->getLastname() ?? ''));

        $unavailability = null;
        if ($result->absence !== null) {
            $unavailability = [
                'type'      => 'ABSENCE',
                'dateStart' => $result->absence->getDateStart()->format('Y-m-d'),
                'dateEnd'   => $result->absence->getDateEnd()->format('Y-m-d'),
            ];
        }

        // D-165 — who the conflict is with (surgeon, times, status) and whether it is a saved
        // mission or a line still being proposed in the editor; missionId/siteName/startAt/
        // endAt keep their former meaning.
        $conflict = ($result->conflictDetail
            ?? ($result->conflictingMission !== null ? ScheduleConflictDetail::fromMission($result->conflictingMission) : null)
        )?->toArray();

        return [
            'id'             => $candidate->getId(),
            'name'           => $name !== '' ? $name : $candidate->getEmail(),
            'email'          => $candidate->getEmail(),
            'eligible'       => $result->eligible,
            'selectable'     => $result->selectableUnder($policy),
            'reasons'        => array_map(static fn (EligibilityReason $r) => $r->value, $result->reasons),
            'unavailability' => $unavailability,
            'conflict'       => $conflict,
        ];
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
