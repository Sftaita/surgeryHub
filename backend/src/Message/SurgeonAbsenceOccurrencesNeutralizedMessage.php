<?php

namespace App\Message;

/**
 * D-103 (Lot 3) — dispatched once per absence-processing run by
 * SurgeonAbsenceOccurrenceImpactService, only when at least one occurrence was newly
 * neutralized (never dispatched for a no-op run — see the service's idempotency
 * contract). Never one message per occurrence — the handler groups by recipient.
 *
 * @phpstan-type OccurrenceSnapshot array{
 *   postId: int, occurrenceDate: string, siteId: int|null, siteName: string|null,
 *   surgeonId: int, surgeonName: string, instrumentistId: int|null,
 *   instrumentistName: string|null, startTime: string, endTime: string,
 * }
 */
final class SurgeonAbsenceOccurrencesNeutralizedMessage
{
    /**
     * @param OccurrenceSnapshot[] $occurrences
     * @param int[]                $recipientManagerIds every active manager/admin at dispatch time
     */
    public function __construct(
        public readonly int $absenceId,
        public readonly int $surgeonId,
        public readonly string $surgeonName,
        public readonly string $dateStart,
        public readonly string $dateEnd,
        public readonly int $actorId,
        public readonly array $occurrences,
        public readonly array $recipientManagerIds,
        public readonly \DateTimeImmutable $occurredAt,
    ) {
    }
}
