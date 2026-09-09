<?php

namespace App\Service;

use App\Entity\Absence;
use App\Entity\AuditEvent;
use App\Entity\Mission;
use App\Entity\PlanningOccurrenceException;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Enum\OccurrenceExceptionSource;
use App\Enum\PlanningAlertType;
use App\Exception\InstrumentistIneligibleException;
use App\Message\PlanningRestoredAfterAbsenceMessage;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-104 (Lot 4) — reconciles what a surgeon/instrumentist absence had previously mutated
 * once that absence is deleted or shortened: restores what can safely be restored, leaves
 * everything else untouched.
 *
 * "Safely" has a single, precise definition, applied consistently everywhere below:
 *
 *   - Future Post occurrence (PlanningOccurrenceException, source = SURGEON_ABSENCE, no
 *     Mission yet): restorable only if (a) the Post is still active and this date is still
 *     a theoretical occurrence of its CURRENT recurrence rule, AND (b) no OTHER Absence row
 *     (any absence, not just this one) still covers the surgeon on that date. Never touches
 *     an exception whose source = MANAGER — structurally impossible to conflate anyway,
 *     since the unique (post, occurrenceDate) constraint means at most one exception exists
 *     per occurrence, and this service only ever queries source = SURGEON_ABSENCE ones.
 *
 *   - Mission (already generated): restorable only if this Mission's MOST RECENT AuditEvent
 *     is exactly the absence-driven event that this absence caused (matched by
 *     `payload['causedByAbsenceId']`, see MissionPostDeployService::release()/cancel()).
 *     If a manager (or a different absence) has touched the mission since — any other
 *     AuditEventType, or a causedByAbsenceId that doesn't match — nothing is restored. This
 *     is the single mechanism that answers "is the mission's current state still exactly
 *     what the absence reaction produced?" (§13/§14 of the spec) without needing a separate
 *     snapshot entity — AuditEvent.payload (already a json column, no migration) carries
 *     everything needed: `previousStatus`, `fromInstrumentistId`, `causedByAbsenceId`.
 *
 *   - A restored Mission's previous instrumentist is NEVER blindly reapplied — always
 *     re-validated via MissionPostDeployService::assign()/restoreAfterCancellation(), which
 *     enforce MissionEligibilityService::evaluateForReassignment() (STRICT_ASSIGNMENT:
 *     ABSENT/SCHEDULE_CONFLICT/INACTIVE, per D-101). If no longer eligible, the mission is
 *     still restored (surgeon absence: CANCELLED → OPEN; instrumentist absence: stays OPEN,
 *     i.e. nothing to restore) rather than left in its neutralized state — never silently
 *     dropping the restoration just because the old assignee isn't available anymore (§10).
 *
 * Deletion is deliberately TWO-PHASE — the one real ordering subtlety in this whole
 * service. Occurrence restoration needs the Absence row's FK still queryable
 * (PlanningOccurrenceException.sourceAbsence is ON DELETE SET NULL), so it MUST run
 * BEFORE the caller removes/flushes the row (beginDeletion()). Mission restoration, by
 * contrast, re-validates eligibility via MissionEligibilityService — which queries the
 * live Absence table for ABSENT — so it MUST run AFTER the row is actually gone
 * (completeDeletion()), otherwise the very absence being deleted would still make its own
 * former assignee look "still absent" and eligibility would always fail. Mission lookup
 * itself never needs the Absence row to exist (it only compares a plain integer id stored
 * in AuditEvent.payload, not an FK), so this split is free — no data is at risk either way.
 * Update (shrink) has no such problem: the Absence row is never deleted, and by the time
 * reconciliation runs its NEW (already-flushed) range naturally excludes the dates that
 * fell out — a single-phase reconcileForUpdate() is correct and sufficient.
 *
 * PlanningAlert reconciliation needs NO new code here — AbsenceImpactService::
 * onAbsenceDeleted()/onAbsenceUpdated() (existing, untouched) already resolves exactly the
 * absence-driven alerts (SURGEON_ABSENCE/INSTRUMENTIST_ABSENCE/REASSIGNMENT_REQUIRED — the
 * only types ever carrying a non-null `absence` FK) and already leaves SCHEDULE_CONFLICT/
 * OCCURRENCE_CANCELLED-type alerts (absence always null on those) completely alone.
 */
class AbsenceImpactReconciliationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanningGeneratorServiceV2 $generator,
        private readonly MissionPostDeployService $missionPostDeployService,
        private readonly PlanningAlertService $alertService,
        private readonly AuditService $auditService,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    // ── Deletion — two-phase, see class docblock ──────────────────────────────

    /**
     * Phase 1 — call BEFORE $em->remove($absence). Restores future occurrences only
     * (needs the FK still intact) and returns their snapshots for completeDeletion() to
     * combine into a single notification later.
     *
     * @return array<int, array<string, mixed>>
     */
    public function beginDeletion(Absence $absence, User $actor): array
    {
        $user = $absence->getUser();
        if ($user === null || self::roleOf($user) !== 'SURGEON') {
            return [];
        }

        return $this->reconcileOccurrences($absence, $user, $absence->getDateStart(), $absence->getDateEnd(), $actor, isDeletion: true);
    }

    /**
     * Phase 2 — call AFTER $em->remove($absence) + $em->flush(). Restores missions (the
     * absence being physically gone now makes eligibility re-validation accurate) and
     * dispatches ONE combined notification covering both phases — never dispatched if
     * nothing was restored in either phase.
     *
     * $absenceId — captured by the caller BEFORE $em->remove($absence): once Doctrine
     * actually deletes the row, it resets the (auto-generated) identifier on the PHP
     * object to null, even though the object's other fields/associations remain readable.
     * Every AuditEvent/notification payload below needs a real int, never $absence->getId().
     *
     * @param array<int, array<string, mixed>> $restoredOccurrences from beginDeletion()
     * @return array{restoredOccurrences: array<int, array<string, mixed>>, restoredMissions: array<int, array<string, mixed>>}
     */
    public function completeDeletion(Absence $absence, int $absenceId, User $actor, array $restoredOccurrences): array
    {
        $user = $absence->getUser();
        if ($user === null) {
            return ['restoredOccurrences' => $restoredOccurrences, 'restoredMissions' => []];
        }

        $role = self::roleOf($user);
        if ($role === null) {
            return ['restoredOccurrences' => $restoredOccurrences, 'restoredMissions' => []];
        }

        $restoredMissions = $role === 'SURGEON'
            ? $this->reconcileSurgeonMissions($absence, $absenceId, $user, $absence->getDateStart(), $absence->getDateEnd(), $actor, isDeletion: true)
            : $this->reconcileInstrumentistMissions($absence, $absenceId, $user, $absence->getDateStart(), $absence->getDateEnd(), $actor, isDeletion: true);

        return $this->flushAndNotify($absenceId, $user, $role, $actor, $restoredOccurrences, $restoredMissions);
    }

    // ── Update (shrink) — single-phase, see class docblock ────────────────────

    /**
     * $previousDateStart/$previousDateEnd — the absence's date range BEFORE this update was
     * applied, captured by the caller before flushing the new range (AbsenceController::
     * update() flushes new dates first). Only the shrink direction needs reconciliation
     * here: expansion is already handled by SurgeonAbsenceOccurrenceImpactService::
     * onSurgeonAbsenceUpdated() (creates new exceptions for newly-covered occurrences) and
     * AbsenceMissionReactionService::onAbsenceUpdated() (mutates newly-overlapping
     * missions) — both already run against the (already-flushed) new range before this
     * method is called.
     *
     * Pre-D-117-hardening bug: reconcileSurgeonMissions()/reconcileInstrumentistMissions()
     * used to search the OLD range (correct) but never checked whether a candidate's date
     * was STILL covered by the absence's own CURRENT range — only whether some OTHER
     * absence covered it (surgeonStillAbsentForMission() excludes by id, not by range). A
     * genuine no-op PATCH (unchanged dates, e.g. only `reason` edited) or a pure expansion
     * would still find every previously-cancelled mission in the (old == or ⊆ new) range as
     * a "candidate" and — since no OTHER absence needed to exist — incorrectly restore it.
     * reconcileOccurrences() already had the right guard (`isDeletion` false skips a date
     * still inside `$absence`'s current range); both mission methods now take the same
     * `$absence` + `isDeletion` and apply the identical skip, so only dates that actually
     * fell OUT of coverage (front/back shrink) are ever restoration candidates — a no-op or
     * pure expansion always resolves to zero mission candidates. See
     * AbsenceImpactReconciliationTest for the shrink/expand/no-op matrix.
     *
     * @return array{restoredOccurrences: array<int, array<string, mixed>>, restoredMissions: array<int, array<string, mixed>>}
     */
    public function reconcileForUpdate(Absence $absence, \DateTimeImmutable $previousDateStart, \DateTimeImmutable $previousDateEnd, User $actor): array
    {
        $user = $absence->getUser();
        if ($user === null) {
            return ['restoredOccurrences' => [], 'restoredMissions' => []];
        }

        $role = self::roleOf($user);
        if ($role === null) {
            return ['restoredOccurrences' => [], 'restoredMissions' => []];
        }

        $restoredOccurrences = $role === 'SURGEON'
            ? $this->reconcileOccurrences($absence, $user, $previousDateStart, $previousDateEnd, $actor, isDeletion: false)
            : [];

        $restoredMissions = $role === 'SURGEON'
            ? $this->reconcileSurgeonMissions($absence, $absence->getId(), $user, $previousDateStart, $previousDateEnd, $actor, isDeletion: false)
            : $this->reconcileInstrumentistMissions($absence, $absence->getId(), $user, $previousDateStart, $previousDateEnd, $actor, isDeletion: false);

        return $this->flushAndNotify($absence->getId(), $user, $role, $actor, $restoredOccurrences, $restoredMissions);
    }

    // ── Shared: flush + combined notification ─────────────────────────────────

    /** @return array{restoredOccurrences: array<int, array<string, mixed>>, restoredMissions: array<int, array<string, mixed>>} */
    private function flushAndNotify(int $absenceId, User $user, string $role, User $actor, array $restoredOccurrences, array $restoredMissions): array
    {
        if (empty($restoredOccurrences) && empty($restoredMissions)) {
            return ['restoredOccurrences' => [], 'restoredMissions' => []];
        }

        $this->em->flush();

        $this->bus->dispatch(new PlanningRestoredAfterAbsenceMessage(
            absenceId: $absenceId,
            absentUserId: $user->getId(),
            absentUserName: self::displayName($user),
            absentUserRole: $role,
            actorId: $actor->getId(),
            restoredOccurrences: $restoredOccurrences,
            restoredMissions: $restoredMissions,
            occurredAt: new \DateTimeImmutable(),
        ));

        return ['restoredOccurrences' => $restoredOccurrences, 'restoredMissions' => $restoredMissions];
    }

    // ── Future occurrences (no Mission yet) ───────────────────────────────────

    /**
     * Candidates are scoped by SURGEON + date range + source, deliberately NOT by
     * `sourceAbsence = :absence` — the exception's FK only ever points at whichever
     * absence neutralized the occurrence FIRST (Lot 3's idempotent "never overwrite an
     * existing exception" rule, §5/§8). When two absences overlap the same date, deleting
     * the SECOND one (which never owned the FK) must still trigger re-examination — the
     * "is it still justified" check inside the loop already correctly looks at every OTHER
     * live absence, independent of which one the FK happens to reference.
     *
     * @return array<int, array<string, mixed>>
     */
    private function reconcileOccurrences(Absence $absence, User $surgeon, \DateTimeImmutable $searchStart, \DateTimeImmutable $searchEnd, User $actor, bool $isDeletion): array
    {
        $candidates = $this->em->createQueryBuilder()
            ->select('e')->from(PlanningOccurrenceException::class, 'e')
            ->join('e.post', 'p')
            ->where('p.surgeon = :surgeon')
            ->andWhere('e.source = :source')
            ->andWhere('e.occurrenceDate >= :start')
            ->andWhere('e.occurrenceDate <= :end')
            ->setParameter('surgeon', $surgeon)
            ->setParameter('source', OccurrenceExceptionSource::SURGEON_ABSENCE)
            ->setParameter('start', $searchStart, Types::DATE_IMMUTABLE)
            ->setParameter('end', $searchEnd, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getResult();

        $restored = [];
        foreach ($candidates as $exception) {
            $post = $exception->getPost();
            $date = $exception->getOccurrenceDate();

            // Update (shrink) only: a date still inside the absence's CURRENT (already
            // shortened, already-flushed) range is still justified by this very absence —
            // nothing to do. Not applicable to deletion: there is no "current range" left
            // to compare against once the whole absence is being removed.
            if (!$isDeletion && $date >= $absence->getDateStart() && $date <= $absence->getDateEnd()) {
                continue;
            }

            if ($this->occurrenceStillJustified($surgeon, $post, $date, $absence)) {
                continue;
            }

            $shift = $this->shiftTimes($post);
            $instrumentist = $post->getInstrumentist();
            $site = $post->getSite();

            $snapshot = [
                'postId'            => $post->getId(),
                'occurrenceDate'    => $date->format('Y-m-d'),
                'siteId'            => $site?->getId(),
                'siteName'          => $site?->getName(),
                'surgeonName'       => self::displayName($surgeon),
                'instrumentistId'   => $instrumentist?->getId(),
                'instrumentistName' => $instrumentist !== null ? self::displayName($instrumentist) : null,
                'startTime'         => $shift[0] ?? null,
                'endTime'           => $shift[1] ?? null,
            ];

            $this->auditService->recordGlobal(
                $actor,
                AuditEventType::PLANNING_OCCURRENCE_RESTORED_AFTER_ABSENCE,
                array_merge($snapshot, ['absenceId' => $absence->getId()]),
            );

            $this->em->remove($exception);
            $restored[] = $snapshot;
        }

        return $restored;
    }

    /** True = still justified, leave the exception alone. False = safe to restore. */
    private function occurrenceStillJustified(User $surgeon, SurgeonSchedulePost $post, \DateTimeImmutable $date, Absence $excluding): bool
    {
        if (!$post->isActive()) {
            return true;
        }
        if (empty($this->generator->theoreticalOccurrenceDates($post, $date, $date))) {
            return true; // recurrence rule no longer produces this date at all
        }

        $excludingId = $excluding->getId();
        $stillAbsentCount = $this->em->createQuery(
            'SELECT COUNT(a.id) FROM App\Entity\Absence a
             WHERE a.user = :user AND a.id != :excludingId
               AND a.dateStart <= :date AND a.dateEnd >= :date'
        )
            ->setParameter('user', $surgeon)
            ->setParameter('excludingId', $excludingId)
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->getSingleScalarResult();

        return ((int) $stillAbsentCount) > 0;
    }

    /**
     * True = another absence (excluding the one being deleted/shrunk) still covers this
     * surgeon on this mission's date — never restore over a still-active reason. Same
     * "exclude by id, check for any survivor" pattern as occurrenceStillJustified().
     */
    private function surgeonStillAbsentForMission(User $surgeon, Mission $mission, int $excludingAbsenceId): bool
    {
        $date = $mission->getStartAt();
        if ($date === null) {
            return false;
        }

        $stillAbsentCount = $this->em->createQuery(
            'SELECT COUNT(a.id) FROM App\Entity\Absence a
             WHERE a.user = :user AND a.id != :excludingId
               AND a.dateStart <= :date AND a.dateEnd >= :date'
        )
            ->setParameter('user', $surgeon)
            ->setParameter('excludingId', $excludingAbsenceId)
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->getSingleScalarResult();

        return ((int) $stillAbsentCount) > 0;
    }

    // ── Missions cancelled due to surgeon absence ─────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function reconcileSurgeonMissions(Absence $absence, int $absenceId, User $surgeon, \DateTimeImmutable $searchStart, \DateTimeImmutable $searchEnd, User $actor, bool $isDeletion): array
    {
        $dayStart = $searchStart->setTime(0, 0, 0);
        $dayEnd   = $searchEnd->setTime(23, 59, 59);

        $candidates = $this->em->createQuery(
            'SELECT m FROM App\Entity\Mission m
             WHERE m.surgeon = :surgeon AND m.status = :status
               AND m.startAt >= :dayStart AND m.startAt <= :dayEnd'
        )
            ->setParameter('surgeon', $surgeon)
            ->setParameter('status', MissionStatus::CANCELLED)
            ->setParameter('dayStart', $dayStart, Types::DATETIME_IMMUTABLE)
            ->setParameter('dayEnd', $dayEnd, Types::DATETIME_IMMUTABLE)
            ->getResult();

        $currentRangeStart = $absence->getDateStart()->setTime(0, 0, 0);
        $currentRangeEnd   = $absence->getDateEnd()->setTime(23, 59, 59);

        $restored = [];
        foreach ($candidates as $mission) {
            $latest = $this->latestAuditEvent($mission);
            if ($latest === null || $latest->getEventType() !== AuditEventType::MISSION_CANCELLED_POST_DEPLOY) {
                continue; // something else happened since — never overwrite it
            }
            $payload = $latest->getPayload() ?? [];
            if (($payload['causedByAbsenceId'] ?? null) === null) {
                continue; // a manager action caused the latest state — never touch it
            }
            // Deliberately NOT an exact match against $absenceId: like occurrences (see
            // reconcileOccurrences() docblock), the payload only ever records the FIRST
            // absence that caused the cancellation — a second, later, overlapping surgeon
            // absence never re-touches an already-CANCELLED mission (out of react()'s
            // ASSIGNED/OPEN scope), so it never appears here. Deleting THAT second absence
            // must still be able to trigger restoration once nothing else justifies it.

            // Update (shrink) only — mirrors reconcileOccurrences(): a mission date still
            // inside THIS absence's own (already-updated, current) range is still justified
            // by it; only dates that fell OUT of the range (front/back shrink) are
            // restoration candidates. Not applicable to deletion: there is no "current
            // range" left once the absence itself is gone. This is the fix for the
            // pre-existing bug documented on reconcileForUpdate() — without it, a no-op
            // PATCH (same dates) or a pure expansion found every previously-cancelled
            // mission in range as a "candidate" and restored it.
            if (!$isDeletion && $mission->getStartAt() !== null
                && $mission->getStartAt() >= $currentRangeStart
                && $mission->getStartAt() <= $currentRangeEnd) {
                continue;
            }

            // The instrumentist eligibility check inside restoreAfterCancellation() below
            // validates the CANDIDATE instrumentist only — it has no opinion on the
            // SURGEON's own continued absence. A second, still-active absence for this same
            // surgeon (overlapping this exact mission) must block restoration just as surely
            // as it blocks an occurrence restoration — mirrors occurrenceStillJustified().
            if ($this->surgeonStillAbsentForMission($surgeon, $mission, $absenceId)) {
                continue;
            }

            $oldInstrumentistId = $payload['fromInstrumentistId'] ?? null;
            $restoredToAssigned = false;

            if ($oldInstrumentistId !== null) {
                try {
                    $this->missionPostDeployService->restoreAfterCancellation($mission, $actor, $oldInstrumentistId, $absenceId);
                    $restoredToAssigned = true;
                } catch (InstrumentistIneligibleException) {
                    // Old instrumentist no longer eligible — fall through to OPEN restore below.
                }
            }

            if (!$restoredToAssigned) {
                $this->missionPostDeployService->restoreAfterCancellation($mission, $actor, null, $absenceId);

                $this->alertService->createIfNotDuplicate($mission, PlanningAlertType::REASSIGNMENT_REQUIRED, null, [
                    'missionId' => $mission->getId(),
                    'reason'    => 'Mission restaurée suite à la suppression/réduction d\'une absence chirurgien — l\'ancien instrumentiste n\'est plus disponible.',
                ]);
            }

            $restored[] = $this->missionSnapshot($mission, $restoredToAssigned ? 'ASSIGNED' : 'OPEN');
        }

        return $restored;
    }

    // ── Missions released due to instrumentist absence ────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function reconcileInstrumentistMissions(Absence $absence, int $absenceId, User $instrumentist, \DateTimeImmutable $searchStart, \DateTimeImmutable $searchEnd, User $actor, bool $isDeletion): array
    {
        $dayStart = $searchStart->setTime(0, 0, 0);
        $dayEnd   = $searchEnd->setTime(23, 59, 59);

        // instrumentist has already been cleared by release() — cannot filter by it directly,
        // so the candidate set is every currently-OPEN mission in the window, narrowed by the
        // per-mission AuditEvent check below (which does carry fromInstrumentistId).
        $candidates = $this->em->createQuery(
            'SELECT m FROM App\Entity\Mission m
             WHERE m.status = :status AND m.startAt >= :dayStart AND m.startAt <= :dayEnd'
        )
            ->setParameter('status', MissionStatus::OPEN)
            ->setParameter('dayStart', $dayStart, Types::DATETIME_IMMUTABLE)
            ->setParameter('dayEnd', $dayEnd, Types::DATETIME_IMMUTABLE)
            ->getResult();

        $currentRangeStart = $absence->getDateStart()->setTime(0, 0, 0);
        $currentRangeEnd   = $absence->getDateEnd()->setTime(23, 59, 59);

        $restored = [];
        foreach ($candidates as $mission) {
            $latest = $this->latestAuditEvent($mission);
            if ($latest === null || $latest->getEventType() !== AuditEventType::MISSION_RELEASED_TO_POOL) {
                continue;
            }
            $payload = $latest->getPayload() ?? [];
            if (($payload['fromInstrumentistId'] ?? null) !== $instrumentist->getId()) {
                continue;
            }
            if (($payload['causedByAbsenceId'] ?? null) === null) {
                continue; // a manager action released it — never touch it
            }

            // Update (shrink) only — see reconcileSurgeonMissions() for the full rationale
            // and the bug this fixes: a mission date still inside THIS absence's own
            // current range is still justified by it, never a restoration candidate.
            if (!$isDeletion && $mission->getStartAt() !== null
                && $mission->getStartAt() >= $currentRangeStart
                && $mission->getStartAt() <= $currentRangeEnd) {
                continue;
            }
            // Deliberately NOT an exact match against $absenceId — same reasoning as
            // reconcileSurgeonMissions(): a second, later, overlapping instrumentist
            // absence for the same person never re-touches an already-OPEN mission (out of
            // react()'s ASSIGNED scope), so only the first absence's id is ever recorded
            // here. The live eligibility check inside assign() below (evaluateForReassignment,
            // which queries the Absence table directly) is what actually re-validates
            // whether THIS instrumentist is still absent for any other reason/absence —
            // that's the real gate, not this payload comparison.

            try {
                $this->missionPostDeployService->assign($mission, $actor, $instrumentist->getId(), notify: false);
            } catch (InstrumentistIneligibleException) {
                // Stays OPEN — exactly what it already was, so no genuine change to report.
                continue;
            }

            $this->auditService->record($mission, $actor, AuditEventType::MISSION_ASSIGNMENT_RESTORED_AFTER_INSTRUMENTIST_ABSENCE, [
                'causedByAbsenceId'       => $absenceId,
                'restoredInstrumentistId' => $instrumentist->getId(),
            ]);

            $restored[] = $this->missionSnapshot($mission, 'ASSIGNED');
        }

        return $restored;
    }

    // ── Lot 6 (D-106) — single-item entry points for the manual "verify conflicts" scan ──
    //
    // Mirror the per-candidate bodies of reconcileSurgeonMissions()/
    // reconcileInstrumentistMissions()/reconcileOccurrences() exactly, but for an arbitrary
    // Mission/PlanningOccurrenceException found during a manual scan rather than one scoped
    // to a specific absence being deleted/updated. There is no absence "being excluded" in
    // this context (nothing is being deleted right now) — surgeonStillAbsentForMission() is
    // called with the sentinel id 0 (no real Absence row is ever id 0), which is equivalent
    // to "is ANY absence still covering this person on this date".

    /** @return array<string, mixed>|null mission snapshot — see missionSnapshot() */
    public function reconcileCancelledMissionIfNoLongerJustified(Mission $mission, User $actor): ?array
    {
        if ($mission->getStatus() !== MissionStatus::CANCELLED) {
            return null;
        }
        $surgeon = $mission->getSurgeon();
        if ($surgeon === null) {
            return null;
        }

        $latest = $this->latestAuditEvent($mission);
        if ($latest === null || $latest->getEventType() !== AuditEventType::MISSION_CANCELLED_POST_DEPLOY) {
            return null;
        }
        $payload = $latest->getPayload() ?? [];
        $causedByAbsenceId = $payload['causedByAbsenceId'] ?? null;
        if ($causedByAbsenceId === null) {
            return null; // manager-caused — never touch it
        }

        if ($this->surgeonStillAbsentForMission($surgeon, $mission, excludingAbsenceId: 0)) {
            return null; // still justified by a currently-active absence
        }

        $oldInstrumentistId = $payload['fromInstrumentistId'] ?? null;
        $restoredToAssigned = false;

        if ($oldInstrumentistId !== null) {
            try {
                $this->missionPostDeployService->restoreAfterCancellation($mission, $actor, $oldInstrumentistId, $causedByAbsenceId);
                $restoredToAssigned = true;
            } catch (InstrumentistIneligibleException) {
                // Old instrumentist no longer eligible — fall through to OPEN restore below.
            }
        }

        if (!$restoredToAssigned) {
            $this->missionPostDeployService->restoreAfterCancellation($mission, $actor, null, $causedByAbsenceId);

            $this->alertService->createIfNotDuplicate($mission, PlanningAlertType::REASSIGNMENT_REQUIRED, null, [
                'missionId' => $mission->getId(),
                'reason'    => "Mission restaurée par la vérification manuelle des conflits — l'ancien instrumentiste n'est plus disponible.",
            ]);
        }

        return $this->missionSnapshot($mission, $restoredToAssigned ? 'ASSIGNED' : 'OPEN');
    }

    /** @return array<string, mixed>|null mission snapshot — see missionSnapshot() */
    public function reconcileReleasedMissionIfNoLongerJustified(Mission $mission, User $actor): ?array
    {
        if ($mission->getStatus() !== MissionStatus::OPEN) {
            return null;
        }

        $latest = $this->latestAuditEvent($mission);
        if ($latest === null || $latest->getEventType() !== AuditEventType::MISSION_RELEASED_TO_POOL) {
            return null;
        }
        $payload = $latest->getPayload() ?? [];
        $causedByAbsenceId   = $payload['causedByAbsenceId'] ?? null;
        $fromInstrumentistId = $payload['fromInstrumentistId'] ?? null;
        if ($causedByAbsenceId === null || $fromInstrumentistId === null) {
            return null;
        }

        try {
            $this->missionPostDeployService->assign($mission, $actor, $fromInstrumentistId, notify: false);
        } catch (InstrumentistIneligibleException) {
            return null; // stays OPEN — no genuine change to report
        }

        $this->auditService->record($mission, $actor, AuditEventType::MISSION_ASSIGNMENT_RESTORED_AFTER_INSTRUMENTIST_ABSENCE, [
            'causedByAbsenceId'       => $causedByAbsenceId,
            'restoredInstrumentistId' => $fromInstrumentistId,
        ]);

        return $this->missionSnapshot($mission, 'ASSIGNED');
    }

    /** @return array<string, mixed>|null occurrence snapshot */
    public function reconcileOccurrenceIfNoLongerJustified(PlanningOccurrenceException $exception, User $actor): ?array
    {
        if ($exception->getSource() !== OccurrenceExceptionSource::SURGEON_ABSENCE) {
            return null; // never touch a MANAGER exception
        }

        $post    = $exception->getPost();
        $surgeon = $post->getSurgeon();
        $date    = $exception->getOccurrenceDate();

        if (!$post->isActive() || empty($this->generator->theoreticalOccurrenceDates($post, $date, $date))) {
            return null; // recurrence no longer produces this date — not this scan's concern
        }

        $stillAbsentCount = $this->em->createQuery(
            'SELECT COUNT(a.id) FROM App\Entity\Absence a
             WHERE a.user = :user AND a.dateStart <= :date AND a.dateEnd >= :date'
        )
            ->setParameter('user', $surgeon)
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->getSingleScalarResult();

        if (((int) $stillAbsentCount) > 0) {
            return null; // still justified
        }

        $shift         = $this->shiftTimes($post);
        $instrumentist = $post->getInstrumentist();
        $site          = $post->getSite();

        $snapshot = [
            'postId'            => $post->getId(),
            'occurrenceDate'    => $date->format('Y-m-d'),
            'siteId'            => $site?->getId(),
            'siteName'          => $site?->getName(),
            'surgeonName'       => self::displayName($surgeon),
            'instrumentistId'   => $instrumentist?->getId(),
            'instrumentistName' => $instrumentist !== null ? self::displayName($instrumentist) : null,
            'startTime'         => $shift[0] ?? null,
            'endTime'           => $shift[1] ?? null,
        ];

        $this->auditService->recordGlobal(
            $actor,
            AuditEventType::PLANNING_OCCURRENCE_RESTORED_AFTER_ABSENCE,
            array_merge($snapshot, ['absenceId' => $exception->getSourceAbsence()?->getId()]),
        );

        $this->em->remove($exception);
        $this->em->flush();

        return $snapshot;
    }

    // ── Shared helpers ─────────────────────────────────────────────────────────

    private function latestAuditEvent(Mission $mission): ?AuditEvent
    {
        return $this->em->createQueryBuilder()
            ->select('e')->from(AuditEvent::class, 'e')
            ->where('e.mission = :m')
            ->setParameter('m', $mission)
            ->orderBy('e.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function missionSnapshot(Mission $mission, string $restoredStatus): array
    {
        $surgeon       = $mission->getSurgeon();
        $instrumentist = $mission->getInstrumentist();

        return [
            'missionId'         => $mission->getId(),
            'date'              => $mission->getStartAt()?->format('d/m/Y') ?? '',
            'siteId'            => $mission->getSite()?->getId(),
            'siteName'          => $mission->getSite()?->getName(),
            'surgeonId'         => $surgeon?->getId(),
            'surgeonName'       => $surgeon !== null ? self::displayName($surgeon) : null,
            'restoredStatus'    => $restoredStatus,
            'instrumentistId'   => $instrumentist?->getId(),
            'instrumentistName' => $instrumentist !== null ? self::displayName($instrumentist) : null,
        ];
    }

    /** @return array{0: string, 1: string}|null [startTime "H:i", endTime "H:i"] */
    private function shiftTimes(SurgeonSchedulePost $post): ?array
    {
        $config = $this->em->getRepository(ShiftPeriodConfig::class)->findOneBy([
            'site'   => $post->getSite(),
            'period' => $post->getPeriod(),
            'active' => true,
        ]);

        if ($config === null) {
            return null;
        }

        return [$config->getStartTime()->format('H:i'), $config->getEndTime()->format('H:i')];
    }

    private static function roleOf(User $user): ?string
    {
        $roles = $user->getRoles();
        if (in_array('ROLE_SURGEON', $roles, true)) {
            return 'SURGEON';
        }
        if (in_array('ROLE_INSTRUMENTIST', $roles, true)) {
            return 'INSTRUMENTIST';
        }
        return null;
    }

    private static function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
