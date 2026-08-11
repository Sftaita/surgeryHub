<?php

namespace App\Message;

/**
 * D-104 (Lot 4) — dispatched once per reconciliation run (absence deleted or shortened) by
 * AbsenceImpactReconciliationService, ONLY when at least one occurrence or mission was
 * actually restored — never dispatched for a no-op reconciliation (§20: no false
 * "restored" message when nothing safely could be). Combines both categories (occurrences
 * restored before generation, missions restored after) so the manager gets one summary
 * matching §21's example exactly, and each recipient gets one grouped notification.
 *
 * @phpstan-type RestoredOccurrence array{
 *   postId: int, occurrenceDate: string, siteId: int|null, siteName: string|null,
 *   surgeonName: string, instrumentistId: int|null, instrumentistName: string|null,
 *   startTime: string, endTime: string,
 * }
 * @phpstan-type RestoredMission array{
 *   missionId: int, date: string, siteId: int|null, siteName: string|null,
 *   surgeonId: int|null, surgeonName: string|null, restoredStatus: string,
 *   instrumentistId: int|null, instrumentistName: string|null,
 * }
 */
final class PlanningRestoredAfterAbsenceMessage
{
    /**
     * @param RestoredOccurrence[] $restoredOccurrences
     * @param RestoredMission[]    $restoredMissions
     * @param int[]                $recipientManagerIds every active manager/admin at dispatch time
     */
    public function __construct(
        public readonly int $absenceId,
        public readonly int $absentUserId,
        public readonly string $absentUserName,
        public readonly string $absentUserRole,
        public readonly int $actorId,
        public readonly array $restoredOccurrences,
        public readonly array $restoredMissions,
        public readonly array $recipientManagerIds,
        public readonly \DateTimeImmutable $occurredAt,
    ) {
    }
}
