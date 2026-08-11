<?php

namespace App\Message;

/**
 * D-105 (Lot 5) — the single manager-facing message for an absence-processing run (create,
 * update, or delete). Replaces three previously-independent manager notification paths
 * (AbsenceMissionsReactedMessageHandler — which never actually notified managers,
 * SurgeonAbsenceOccurrencesNeutralizedMessageHandler, PlanningRestoredAfterAbsenceMessageHandler)
 * that could otherwise produce up to two separate manager emails for the same absence event.
 *
 * Dispatched exactly once per AbsenceController/SelfAbsenceController create()/update()/
 * delete() call, ONLY when at least one of the six impact buckets below is non-empty — never
 * for a no-op absence mutation (§4 of the Lot 5 spec: no email without real impact).
 *
 * Impact snapshots are passed through as-is from their originating service (each already
 * shaped for its own individual-recipient notification — AbsenceMissionReactionService::
 * buildMissionSummary(), AbsenceImpactReconciliationService::missionSnapshot()/occurrence
 * snapshots, SurgeonAbsenceOccurrenceImpactService's occurrence snapshots) — never re-derived
 * here, and never carrying anything beyond date/time/site/surgeon/instrumentist/status
 * (no patient data ever reaches a Mission-level array in this codebase).
 */
final class AbsenceImpactSummaryMessage
{
    /**
     * @param string $action                       CREATED|UPDATED|DELETED
     * @param string $absentUserRole                SURGEON|INSTRUMENTIST
     * @param array<int, array<string, mixed>> $missionsCancelled          surgeon absence — already-generated missions cancelled
     * @param array<int, array<string, mixed>> $missionsReleased           instrumentist absence — ASSIGNED missions released to OPEN
     * @param array<int, array<string, mixed>> $missionsRestoredAssigned   reconciliation — restored back to ASSIGNED
     * @param array<int, array<string, mixed>> $missionsRestoredOpen       reconciliation — restored but still OPEN (uncovered)
     * @param array<int, array<string, mixed>> $futureOccurrencesCancelled surgeon absence — Post occurrences neutralized, no Mission yet
     * @param array<int, array<string, mixed>> $futureOccurrencesRestored  reconciliation — neutralized occurrences reactivated
     * @param int[] $recipientManagerIds every active manager/admin at dispatch time
     */
    public function __construct(
        public readonly int $absenceId,
        public readonly int $absentUserId,
        public readonly string $absentUserName,
        public readonly string $absentUserRole,
        public readonly string $action,
        public readonly string $dateStart,
        public readonly string $dateEnd,
        public readonly int $actorId,
        public readonly array $missionsCancelled,
        public readonly array $missionsReleased,
        public readonly array $missionsRestoredAssigned,
        public readonly array $missionsRestoredOpen,
        public readonly array $futureOccurrencesCancelled,
        public readonly array $futureOccurrencesRestored,
        public readonly array $recipientManagerIds,
        public readonly \DateTimeImmutable $occurredAt,
    ) {
    }
}
