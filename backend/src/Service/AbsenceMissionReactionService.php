<?php

namespace App\Service;

use App\Entity\Absence;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\EligibilityEnforcementPolicy;
use App\Enum\MissionChangeType;
use App\Enum\MissionStatus;
use App\Exception\InstrumentistIneligibleException;
use App\Message\AbsenceMissionsReactedMessage;
use App\Message\MissionLifecycleChangedMessage;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Reacts to an absence (surgeon or instrumentist) by auto-mutating already-generated,
 * operational Mission occurrences that overlap the absence period — never the recurring
 * SurgeonSchedulePost definition (that describes future organization; an absence only
 * concerns the concrete occurrences already materialized for this period).
 *
 * Deliberately a separate collaborator from AbsenceImpactService, which keeps its own
 * documented "never mutates a Mission" contract completely unchanged — AbsenceController
 * calls this service IN ADDITION, for exactly the subset of missions safe to auto-correct
 * without a manager decision.
 *
 * Scope (see docs/decisions.md for the full status-by-status rationale):
 *   - Instrumentist absence: ASSIGNED missions where this user is the instrumentist
 *     → MissionPostDeployService::release() (ASSIGNED → OPEN, instrumentist cleared).
 *   - Surgeon absence: OPEN|ASSIGNED missions where this user is the surgeon
 *     → MissionPostDeployService::cancel() (→ CANCELLED, instrumentist cleared if any).
 *   - DRAFT (not yet part of a deployed/published plan), SUBMITTED, VALIDATED, IN_PROGRESS,
 *     DECLARED missions are deliberately NEVER touched here — a mission already declared,
 *     validated, in progress, or not yet deployed represents a business record (or a plan
 *     not yet live) that must not be silently mutated. AbsenceImpactService already raises
 *     a PlanningAlert for the alertable subset of these (DRAFT/SUBMITTED/VALIDATED/
 *     IN_PROGRESS) so a manager can decide by hand — nothing new needed there. CLOSED,
 *     REJECTED, CANCELLED are terminal and excluded by definition (not in either actionable
 *     list below).
 *
 * Ordering requirement: AbsenceController MUST call this service BEFORE
 * AbsenceImpactService::onAbsenceCreated()/onAbsenceUpdated(). This is not just a style
 * choice — AbsenceImpactService's own overlap query matches
 * "(m.surgeon = :user OR m.instrumentist = :user) AND m.status IN (alertable statuses)".
 * Once this service releases/cancels a mission, that mission naturally falls OUT of that
 * query (instrumentist is now null, or status is now CANCELLED — never one of the alertable
 * statuses) — so AbsenceImpactService, completely unmodified, never raises a
 * REASSIGNMENT_REQUIRED/SURGEON_ABSENCE alert for a mission this service already handled.
 * No stale-alert logic had to be written anywhere; the two services simply compose
 * correctly given the right call order.
 *
 * Idempotency: every mutation is gated by MissionPostDeployService's own status guard
 * (release() requires ASSIGNED, cancel() requires OPEN|ASSIGNED) AND by this service's own
 * overlap query only ever matching missions still in a mutable state for this user/absence.
 * Once mutated, a mission's FK/status no longer matches the query, so re-running
 * onAbsenceCreated()/onAbsenceUpdated() — for the same absence, an updated one, or a second
 * absence for the same person — naturally finds nothing left to redo for it. No explicit
 * "already processed" tracking table is needed. This is also why period reduction/shift on
 * update never needs to "undo" anything: a mission that no longer overlaps the new range
 * simply isn't in the query result, and this service has no reversal code path at all.
 *
 * Concurrency: handled the same way MissionPostDeployService::claim() already does (the one
 * existing high-contention case in this codebase) — a pessimistic write lock acquired inside
 * a transaction, the status guard re-checked under that lock, and MissionLifecycleChangedMessage
 * dispatched only AFTER the transaction commits (never from inside it — dispatching while the
 * transaction is still open would let an async worker observe the message before the mutation
 * is durably visible to other connections).
 *
 * onAbsenceDeleted() is deliberately a true no-op here (never restores a released/cancelled
 * mission, and — since Lot 5, D-105 — no longer posts a generic manager notice either). That
 * notice used to fire unconditionally on every delete regardless of real impact; it has been
 * replaced by AbsenceImpactSummaryService's consolidated summary, which only ever notifies
 * when AbsenceImpactReconciliationService actually restored something (see AbsenceController::
 * delete()). Restoration itself is AbsenceImpactReconciliationService's job (Lot 4).
 *
 * CAS B (D-118) — a surgeon-absence cancellation no longer just detaches the instrumentist.
 * Once cancel() commits, processSurgeonAbsence() searches for a same-day, same-site OPEN
 * mission the just-freed instrumentist can cover instead (findAndReassignFreedInstrumentist()),
 * and — if found — reassigns them via MissionPostDeployService::assign() (never a second
 * mutation implementation), one real DB transaction and one fresh query per candidate
 * attempt, so a concurrent claim/absence never produces a double-booking. Deliberately
 * restricted to the SAME site as the cancelled mission (no cross-site auto-reassignment in
 * this lot) and to real OPEN missions only (never touches SurgeonSchedulePost/generate()).
 * Eligibility is exactly MissionEligibilityService::evaluateForReassignment() under
 * EligibilityEnforcementPolicy::STRICT_ASSIGNMENT (ABSENT/SCHEDULE_CONFLICT/INACTIVE all
 * blocking — no exception for an automated, unsupervised decision) — the exact same policy
 * MissionPostDeployService::assign() already defaults to. A freed instrumentist can cover
 * several successive non-overlapping OPEN missions the same day (never a double booking:
 * each successful assign() immediately makes that slot occupied, so the next candidate query,
 * run fresh, can no longer return it, and any mission that would genuinely overlap it is
 * excluded by evaluateForReassignment()'s own SCHEDULE_CONFLICT check). Only when no more
 * compatible target exists does the instrumentist count as genuinely released — the
 * pre-existing ABSENCE_MISSION_CANCELLED recap (AbsenceMissionsReactedMessageHandler) already
 * covers that notification correctly; it is only suppressed (never sent) for an instrumentist
 * this pass actually reassigned, replaced by ABSENCE_INSTRUMENTIST_REASSIGNED instead — never
 * both for the same mission (see buildMissionSummary()'s 'reassignedTo' key).
 *
 * ReleasedOperatingRoomSlot is untouched by any of this — it represents the operating
 * room/block slot freed by the SURGEON's absence (ReleasedOperatingRoomSlotService, driven by
 * SurgeonSchedulePost occurrences), never the instrumentist's availability. There is no
 * "instrumentist availability" entity to split or reconcile here at all.
 */
class AbsenceMissionReactionService
{
    /** Instrumentist absence is only ever actionable against a mission that is ASSIGNED. */
    private const INSTRUMENTIST_ACTIONABLE_STATUSES = [MissionStatus::ASSIGNED];

    /** Surgeon absence is actionable against a mission that is OPEN or already ASSIGNED. */
    private const SURGEON_ACTIONABLE_STATUSES = [MissionStatus::OPEN, MissionStatus::ASSIGNED];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MissionPostDeployService $missionPostDeployService,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /** @return array<int, array<string, mixed>> mission summaries — see buildMissionSummary() */
    public function onAbsenceCreated(Absence $absence, User $actor): array
    {
        return $this->react($absence, $actor);
    }

    /** @return array<int, array<string, mixed>> mission summaries — see buildMissionSummary() */
    public function onAbsenceUpdated(Absence $absence, User $actor): array
    {
        return $this->react($absence, $actor);
    }

    /**
     * True no-op since Lot 5 (D-105) — see class docblock. Kept as an explicit method (rather
     * than removed outright) so AbsenceController/SelfAbsenceController's call sites document
     * the two-phase deletion ordering unchanged from Lot 4, even though this particular step
     * no longer does anything itself.
     */
    public function onAbsenceDeleted(Absence $absence, User $actor): void
    {
    }

    /**
     * Lot 6 (D-106) — mission-first entry point for PlanningVersionAuditService's manual
     * "verify conflicts" scan. Every other public method here starts FROM an Absence and
     * finds the missions it affects; this one starts FROM an arbitrary Mission and asks "is
     * either of its two people currently absent, right now?" — reusing the exact same
     * mutation primitives (processInstrumentistAbsence()/processSurgeonAbsence()) once the
     * covering Absence row is found, so the correction (and its audit trail/notifications)
     * is byte-for-byte identical to what would have happened had the absence reaction run
     * at creation time. Never called by react() itself — only by the manual audit.
     *
     * Surgeon absence is checked first and returns immediately: cancel() clears the
     * instrumentist too, making a separate instrumentist-absence check on the same mission
     * moot for this pass. Returns null if neither person is currently absent, or if the
     * mission's own status makes it not (or no longer) actionable.
     *
     * @return array<string, mixed>|null mission summary — see buildMissionSummary()
     */
    public function reconcileMissionAgainstCurrentAbsences(Mission $mission, User $actor): ?array
    {
        $surgeon = $mission->getSurgeon();
        if ($surgeon !== null && in_array($mission->getStatus(), self::SURGEON_ACTIONABLE_STATUSES, true)) {
            $absence = $this->findCoveringAbsence($surgeon, $mission->getStartAt());
            if ($absence !== null) {
                return $this->processSurgeonAbsence($mission, $actor, $absence);
            }
        }

        if ($mission->getStatus() === MissionStatus::ASSIGNED) {
            $instrumentist = $mission->getInstrumentist();
            if ($instrumentist !== null) {
                $absence = $this->findCoveringAbsence($instrumentist, $mission->getStartAt());
                if ($absence !== null) {
                    return $this->processInstrumentistAbsence($mission, $actor, $absence);
                }
            }
        }

        return null;
    }

    /** Earliest-starting Absence row currently covering this user on this date, if any. */
    private function findCoveringAbsence(User $user, \DateTimeImmutable $date): ?Absence
    {
        return $this->em->createQuery(
            'SELECT a FROM App\Entity\Absence a
             WHERE a.user = :user AND a.dateStart <= :date AND a.dateEnd >= :date
             ORDER BY a.dateStart ASC'
        )
            ->setParameter('user', $user)
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    /** @return array<int, array<string, mixed>> mission summaries — see buildMissionSummary() */
    private function react(Absence $absence, User $actor): array
    {
        $user = $absence->getUser();
        if ($user === null) {
            return [];
        }

        $role = self::roleOf($user);
        if ($role === null) {
            return []; // absences only ever concern surgeons/instrumentists in practice
        }

        $missions = $role === 'INSTRUMENTIST'
            ? $this->findOverlapping($user, $absence, true, self::INSTRUMENTIST_ACTIONABLE_STATUSES)
            : $this->findOverlapping($user, $absence, false, self::SURGEON_ACTIONABLE_STATUSES);

        if (empty($missions)) {
            return [];
        }

        $summaries = [];
        foreach ($missions as $mission) {
            $summary = $role === 'INSTRUMENTIST'
                ? $this->processInstrumentistAbsence($mission, $actor, $absence)
                : $this->processSurgeonAbsence($mission, $actor, $absence);

            if ($summary !== null) {
                $summaries[] = $summary;
            }
        }

        if (empty($summaries)) {
            // Every candidate mission had already moved out of the actionable status
            // between the query above and processing (concurrent claim/reassign/cancel) —
            // nothing left to report.
            return [];
        }

        $this->bus->dispatch(new AbsenceMissionsReactedMessage(
            absenceId: $absence->getId(),
            absentUserId: $user->getId(),
            absentUserRole: $role,
            actorId: $actor->getId(),
            missions: $summaries,
            occurredAt: new \DateTimeImmutable(),
        ));

        return $summaries;
    }

    /** @return array<string, mixed>|null null if the mission was no longer ASSIGNED under lock */
    private function processInstrumentistAbsence(Mission $mission, User $actor, Absence $absence): ?array
    {
        $reason = sprintf(
            'Absence instrumentiste enregistrée (%s → %s)',
            $absence->getDateStart()->format('d/m/Y'),
            $absence->getDateEnd()->format('d/m/Y'),
        );

        $captured = null;
        $summary  = null;

        $this->em->wrapInTransaction(function () use ($mission, $actor, $reason, $absence, &$captured, &$summary): void {
            $this->em->lock($mission, LockMode::PESSIMISTIC_WRITE);

            if ($mission->getStatus() !== MissionStatus::ASSIGNED) {
                return; // handled concurrently between the overlap query and now
            }

            $instrumentist = $mission->getInstrumentist();
            $captured = [
                'fromInstrumentistId'   => $instrumentist?->getId(),
                'fromInstrumentistName' => $instrumentist !== null ? self::displayName($instrumentist) : null,
            ];
            $summary = $this->buildMissionSummary($mission, 'RELEASED');

            $this->missionPostDeployService->release($mission, $actor, notify: false, reason: $reason, causedByAbsenceId: $absence->getId());
        });

        if ($summary === null) {
            return null;
        }

        // Dispatched here, AFTER the transaction above committed — mirrors what
        // MissionPostDeployService::release() would itself have dispatched with notify=true,
        // just correctly timed. Preserves the existing free notification pipeline
        // (SURGEON_POST_UNCOVERED in-app+push to the surgeon, OPEN_MISSION_AVAILABLE fan-out
        // to eligible instrumentists) — this service never re-implements that, only adds the
        // absence-specific recap email on top via AbsenceMissionsReactedMessage.
        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId: $mission->getId(),
            changeType: MissionChangeType::RELEASED,
            actorId: $actor->getId(),
            payload: [
                'fromInstrumentistId'   => $captured['fromInstrumentistId'],
                'fromInstrumentistName' => $captured['fromInstrumentistName'],
                'reason'                => $reason,
                'actorId'               => $actor->getId(),
                'actorName'             => self::displayName($actor),
            ],
            occurredAt: new \DateTimeImmutable(),
        ));

        return $summary;
    }

    /** @return array<string, mixed>|null null if the mission was no longer OPEN|ASSIGNED under lock */
    private function processSurgeonAbsence(Mission $mission, User $actor, Absence $absence): ?array
    {
        $reason = sprintf(
            'Absence chirurgien enregistrée (%s → %s)',
            $absence->getDateStart()->format('d/m/Y'),
            $absence->getDateEnd()->format('d/m/Y'),
        );

        $captured = null;
        $summary  = null;

        $this->em->wrapInTransaction(function () use ($mission, $actor, $reason, $absence, &$captured, &$summary): void {
            $this->em->lock($mission, LockMode::PESSIMISTIC_WRITE);

            if (!in_array($mission->getStatus(), self::SURGEON_ACTIONABLE_STATUSES, true)) {
                return; // handled concurrently between the overlap query and now
            }

            $instrumentist = $mission->getInstrumentist();
            $captured = [
                'instrumentist'         => $instrumentist,
                'fromInstrumentistId'   => $instrumentist?->getId(),
                'fromInstrumentistName' => $instrumentist !== null ? self::displayName($instrumentist) : null,
            ];
            $summary = $this->buildMissionSummary($mission, 'CANCELLED');

            $this->missionPostDeployService->cancel($mission, $actor, reason: $reason, notify: false, causedByAbsenceId: $absence->getId());
        });

        if ($summary === null) {
            return null;
        }

        // See processInstrumentistAbsence() — same reasoning, dispatched after commit.
        // Preserves PLANNING_MISSION_CANCELLED in-app+push to the surgeon (existing free
        // pipeline); the instrumentist-facing "defensive" branch in
        // MissionLifecycleChangedMessageHandler stays a no-op since cancel() already cleared
        // the instrumentist — AbsenceMissionsReactedMessage covers that recipient instead.
        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId: $mission->getId(),
            changeType: MissionChangeType::CANCELLED,
            actorId: $actor->getId(),
            payload: [
                'reason'                => $reason,
                'fromInstrumentistId'   => $captured['fromInstrumentistId'],
                'fromInstrumentistName' => $captured['fromInstrumentistName'],
                'actorId'               => $actor->getId(),
                'actorName'             => self::displayName($actor),
            ],
            occurredAt: new \DateTimeImmutable(),
        ));

        // CAS B (D-118) — before letting this instrumentist count as genuinely released, look
        // for a same-day/same-site OPEN mission to move them onto instead. Only ever attempted
        // when the cancelled mission actually had an instrumentist to move.
        $freedInstrumentist = $captured['instrumentist'];
        if ($freedInstrumentist !== null) {
            $summary['reassignedTo'] = $this->findAndReassignFreedInstrumentist($mission, $freedInstrumentist, $actor, $absence);
        }

        return $summary;
    }

    // ── CAS B (D-118) — automatic reassignment of a freed instrumentist ──────────

    /**
     * Repeatedly finds the best same-day/same-site OPEN mission for $instrumentist and
     * reassigns them (MissionPostDeployService::assign(), STRICT_ASSIGNMENT) until either no
     * more compatible candidate exists or $maxAttempts is hit (a defensive bound only — each
     * successful attempt consumes one real, distinct OPEN mission, so the loop is naturally
     * finite; the bound exists purely so a future bug elsewhere can never spin this forever).
     *
     * Each attempt re-queries the candidate list fresh (never a prefetched/stale list) —
     * required for two reasons: (1) a mission just assigned in a previous iteration of this
     * same loop must never be reconsidered, and (2) a concurrent absence reaction processing a
     * DIFFERENT freed instrumentist the same moment must never race this one onto the same
     * mission (the per-candidate pessimistic lock inside tryAssignCandidate() is the actual
     * safety net; the fresh query is what makes the common, non-racing case pick the right
     * next-best candidate instead of an already-consumed one).
     *
     * @return list<array<string, mixed>> one entry per successful reassignment, see buildTargetSummary()
     */
    private function findAndReassignFreedInstrumentist(Mission $originalMission, User $instrumentist, User $actor, Absence $absence): array
    {
        $site       = $originalMission->getSite();
        $freedStart = $originalMission->getStartAt();
        $freedEnd   = $originalMission->getEndAt();
        if ($site === null || $freedStart === null || $freedEnd === null) {
            return [];
        }

        $reassignments = [];
        $excludedIds   = [];
        $maxAttempts   = 50;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $candidate = $this->findBestOpenCandidate($site, $freedStart, $freedEnd, $excludedIds);
            if ($candidate === null) {
                break;
            }

            $target = $this->tryAssignCandidate($candidate, $instrumentist, $actor, $absence, $originalMission);
            if ($target === null) {
                // Ineligible (ABSENT/SCHEDULE_CONFLICT/INACTIVE) or lost a concurrent race —
                // never retry this exact candidate; exclude it so the next query considers
                // the next-best one instead of looping on the same rejection forever.
                $excludedIds[] = $candidate->getId();
                continue;
            }

            $reassignments[] = $target;
        }

        return $reassignments;
    }

    /**
     * Best same-day, same-site, genuinely uncovered OPEN mission, ranked per the documented
     * CAS B matching order: largest overlap with the freed slot first, then smallest gap to
     * it, then earliest start time, then id as the final stable tie-break. No existing
     * scoring engine in this codebase does this (MissionEligibilityService::findEligible() is
     * a fan-out — candidates per mission — never "best mission for one already-known
     * candidate"), so this is deliberately new, minimal, and scoped to exactly this need.
     *
     * Same-day is computed on the freed mission's own (Brussels-labeled, D-066)
     * start-of-day/end-of-day bounds — identical convention to findOverlapping() above and
     * AbsenceImpactService's own day-bounded queries.
     *
     * @param list<int> $excludedIds mission ids already tried and rejected/consumed this pass
     */
    private function findBestOpenCandidate(Hospital $site, \DateTimeImmutable $freedStart, \DateTimeImmutable $freedEnd, array $excludedIds): ?Mission
    {
        $dayStart = $freedStart->setTime(0, 0, 0);
        $dayEnd   = $freedStart->setTime(23, 59, 59);

        $qb = $this->em->createQueryBuilder()
            ->select('m')->from(Mission::class, 'm')
            ->where('m.site = :site')
            ->andWhere('m.status = :open')
            ->andWhere('m.instrumentist IS NULL')
            ->andWhere('m.startAt >= :dayStart')
            ->andWhere('m.startAt <= :dayEnd')
            ->setParameter('site', $site)
            ->setParameter('open', MissionStatus::OPEN)
            ->setParameter('dayStart', $dayStart, Types::DATETIME_IMMUTABLE)
            ->setParameter('dayEnd', $dayEnd, Types::DATETIME_IMMUTABLE);

        if (!empty($excludedIds)) {
            $qb->andWhere('m.id NOT IN (:excluded)')->setParameter('excluded', $excludedIds);
        }

        /** @var Mission[] $candidates */
        $candidates = $qb->getQuery()->getResult();
        if (empty($candidates)) {
            return null;
        }

        usort($candidates, fn (Mission $a, Mission $b) => self::candidateScore($a, $freedStart, $freedEnd) <=> self::candidateScore($b, $freedStart, $freedEnd));

        return $candidates[0];
    }

    /**
     * Ranking tuple, compared element-by-element (PHP's <=> on equal-length arrays is
     * lexicographic): [-overlapSeconds, gapSeconds, startTimestamp, id]. Ascending sort on
     * this tuple gives exactly the documented priority order — largest overlap with the
     * freed window first (negated so ascending sort favors it), then smallest gap to it,
     * then earliest start, then id as the final stable tie-break.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private static function candidateScore(Mission $m, \DateTimeImmutable $freedStart, \DateTimeImmutable $freedEnd): array
    {
        $start = $m->getStartAt();
        $end   = $m->getEndAt();

        $overlapStart   = max($start, $freedStart);
        $overlapEnd     = min($end, $freedEnd);
        $overlapSeconds = max(0, $overlapEnd->getTimestamp() - $overlapStart->getTimestamp());

        if ($end <= $freedStart) {
            $gapSeconds = $freedStart->getTimestamp() - $end->getTimestamp();
        } elseif ($start >= $freedEnd) {
            $gapSeconds = $start->getTimestamp() - $freedEnd->getTimestamp();
        } else {
            $gapSeconds = 0;
        }

        return [-$overlapSeconds, $gapSeconds, $start->getTimestamp(), $m->getId()];
    }

    /**
     * One transaction, one pessimistic lock, one eligibility-gated assignment attempt.
     * Returns null (never throws) on any rejection — ineligible candidate (ABSENT/
     * SCHEDULE_CONFLICT/INACTIVE, STRICT_ASSIGNMENT) or a lost concurrent race (status/
     * instrumentist no longer OPEN/null under lock) — so the caller's loop can simply try the
     * next-best candidate instead of aborting the whole reassignment search.
     *
     * MissionLifecycleChangedMessage is dispatched here (after commit — R-07), mirroring
     * exactly what MissionPostDeployService::assign(notify: true) would itself have
     * dispatched — this is deliberate: MissionLifecycleChangedMessageHandler::
     * handleReassigned() already sends SURGEON_POST_COVERED to the target mission's surgeon
     * for free (fromInstrumentistId === null, i.e. this was OPEN→ASSIGNED) — reused as-is, no
     * new notification type needed for that recipient (audited: the existing rendering, once
     * enriched with missionDate alongside dayLabel/periodLabel/instrumentistName, is
     * sufficient — see notificationFormat.ts). The instrumentist-facing half of that same
     * handler branch is deliberately suppressed there when causedByAbsenceId is present in
     * the payload — AbsenceMissionsReactedMessageHandler sends this instrumentist the richer,
     * combined-context ABSENCE_INSTRUMENTIST_REASSIGNED instead; sending both would be two
     * notifications about the exact same event.
     */
    private function tryAssignCandidate(Mission $candidate, User $instrumentist, User $actor, Absence $absence, Mission $originalMission): ?array
    {
        // Deliberately NOT $this->em->wrapInTransaction(): on ANY exception thrown from the
        // callback, Doctrine's wrapInTransaction() calls $em->close() before rolling back —
        // permanently closing the EntityManager for the rest of the request. Rejecting an
        // ineligible candidate (ABSENT/SCHEDULE_CONFLICT/INACTIVE) via
        // InstrumentistIneligibleException is expected, routine control flow in the caller's
        // loop, not a fatal error — it must not poison every later Doctrine operation in this
        // request (this was the true cause of the B3/B5/B7/B8 500s: the very first ineligible
        // candidate closed the EM, and the next unrelated query in the same request then failed
        // with EntityManagerClosed). guardEligibility() inside assign() always throws before any
        // mutation/flush, so a plain connection-level rollback here is safe and sufficient.
        $captured   = null;
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $this->em->lock($candidate, LockMode::PESSIMISTIC_WRITE);

            if ($candidate->getStatus() !== MissionStatus::OPEN || $candidate->getInstrumentist() !== null) {
                $connection->commit(); // lost a concurrent race between the search query and this lock
                return null;
            }

            $this->missionPostDeployService->assign(
                $candidate,
                $actor,
                $instrumentist->getId(),
                notify: false,
                policy: EligibilityEnforcementPolicy::STRICT_ASSIGNMENT,
                causedByAbsenceId: $absence->getId(),
                reassignedFromMissionId: $originalMission->getId(),
            );

            $captured = $this->buildTargetSummary($candidate);
            $connection->commit();
        } catch (InstrumentistIneligibleException|ConflictHttpException) {
            $connection->rollBack();
            return null;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        if ($captured === null) {
            return null; // lost the race — status guard above returned early, nothing to dispatch
        }

        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId: $candidate->getId(),
            changeType: MissionChangeType::REASSIGNED,
            actorId: $actor->getId(),
            payload: [
                'fromInstrumentistId'     => null,
                'fromInstrumentistName'   => null,
                'toInstrumentistId'       => $instrumentist->getId(),
                'toInstrumentistName'     => self::displayName($instrumentist),
                'causedByAbsenceId'       => $absence->getId(),
                'reassignedFromMissionId' => $originalMission->getId(),
                'actorId'                 => $actor->getId(),
                'actorName'               => self::displayName($actor),
            ],
            occurredAt: new \DateTimeImmutable(),
        ));

        return $captured;
    }

    /** Snapshot of the target mission for the freed instrumentist's combined-context recap email. */
    private function buildTargetSummary(Mission $mission): array
    {
        $surgeon = $mission->getSurgeon();
        $startAt = $mission->getStartAt();
        $endAt   = $mission->getEndAt();

        return [
            'missionId'   => $mission->getId(),
            'date'        => $startAt?->format('d/m/Y') ?? '',
            'moment'      => $startAt !== null ? (((int) $startAt->format('G')) < 12 ? 'Matin' : 'Après-midi') : null,
            'horaire'     => $startAt !== null && $endAt !== null
                ? $startAt->format('H:i') . '–' . $endAt->format('H:i')
                : null,
            'siteName'    => $mission->getSite()?->getName(),
            'surgeonId'   => $surgeon?->getId(),
            'surgeonName' => $surgeon !== null ? self::displayName($surgeon) : null,
        ];
    }

    /**
     * Snapshot for the batched absence recap email — captured BEFORE the mutation (still
     * has the real instrumentist/surgeon on the mission), never re-derived from a FK at
     * handler read-time later (R-12-style discipline, consistent with the rest of the app).
     *
     * 'reassignedTo' (CAS B, D-118) — list<array<string,mixed>> of buildTargetSummary()
     * entries, always present (defaults to an empty array here so every summary has a
     * uniform shape regardless of changeType), overwritten by processSurgeonAbsence() with
     * the real result of findAndReassignFreedInstrumentist() when applicable. Never
     * populated for 'RELEASED' summaries (instrumentist-absence path — CAS B only concerns
     * the surgeon-absence/CANCELLED path). AbsenceMissionsReactedMessageHandler uses this to
     * decide, per mission, whether to send ABSENCE_INSTRUMENTIST_REASSIGNED (non-empty) or
     * the pre-existing ABSENCE_MISSION_CANCELLED (empty) — never both.
     */
    private function buildMissionSummary(Mission $mission, string $changeType): array
    {
        $surgeon       = $mission->getSurgeon();
        $instrumentist = $mission->getInstrumentist();
        $startAt       = $mission->getStartAt();
        $endAt         = $mission->getEndAt();

        return [
            'missionId'         => $mission->getId(),
            'changeType'        => $changeType,
            'date'              => $startAt?->format('d/m/Y') ?? '',
            'moment'            => $startAt !== null ? (((int) $startAt->format('G')) < 12 ? 'Matin' : 'Après-midi') : null,
            'horaire'           => $startAt !== null && $endAt !== null
                ? $startAt->format('H:i') . '–' . $endAt->format('H:i')
                : null,
            'siteName'          => $mission->getSite()?->getName(),
            'surgeonId'         => $surgeon?->getId(),
            'surgeonName'       => $surgeon !== null ? self::displayName($surgeon) : null,
            'instrumentistId'   => $instrumentist?->getId(),
            'instrumentistName' => $instrumentist !== null ? self::displayName($instrumentist) : null,
            'reassignedTo'      => [],
        ];
    }

    /** @return Mission[] */
    private function findOverlapping(User $user, Absence $absence, bool $byInstrumentist, array $statuses): array
    {
        $absenceStart = $absence->getDateStart()->setTime(0, 0, 0);
        $absenceEnd   = $absence->getDateEnd()->setTime(23, 59, 59);
        $field        = $byInstrumentist ? 'm.instrumentist' : 'm.surgeon';

        // CAS B (D-118) — explicit ORDER BY: with no ordering, several ASSIGNED/OPEN missions
        // overlapping one absence (e.g. a multi-day absence covering several occurrences) were
        // processed in whatever order MySQL happened to return them, making the automatic
        // reassignment search below non-deterministic when several instrumentists get freed by
        // the same absence-processing run. startAt then id gives a stable, predictable order
        // with no behavioral change for the (already idempotent) release()/cancel() calls
        // themselves — only the processing order was ever unspecified.
        return $this->em->createQuery(
            "SELECT m FROM App\Entity\Mission m
             WHERE {$field} = :user
               AND m.startAt <= :absenceEnd
               AND m.endAt >= :absenceStart
               AND m.status IN (:statuses)
             ORDER BY m.startAt ASC, m.id ASC"
        )
            ->setParameter('user', $user)
            ->setParameter('absenceStart', $absenceStart, Types::DATETIME_IMMUTABLE)
            ->setParameter('absenceEnd', $absenceEnd, Types::DATETIME_IMMUTABLE)
            ->setParameter('statuses', $statuses)
            ->getResult();
    }

    private static function roleOf(User $user): ?string
    {
        $roles = $user->getRoles();
        if (in_array('ROLE_INSTRUMENTIST', $roles, true)) {
            return 'INSTRUMENTIST';
        }
        if (in_array('ROLE_SURGEON', $roles, true)) {
            return 'SURGEON';
        }
        return null;
    }

    private static function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
