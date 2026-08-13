<?php

namespace App\Message;

/**
 * Complementary lot to SurgeonAbsenceOccurrencesNeutralizedMessage (Lot 3, D-103) — dispatched
 * once per absence-processing run by InstrumentistAbsenceOccurrenceImpactService, only when at
 * least one occurrence was newly impacted or newly recovered (never for a no-op run — see the
 * service's idempotency contract). Carries BOTH directions in one message so a single
 * absence-processing run (create, or an update that both grows some dates and shrinks others)
 * still produces exactly one consolidated recap per surgeon, never two.
 *
 * @phpstan-type OccurrenceSnapshot array{
 *   postId: int, occurrenceDate: string, siteId: int|null, siteName: string|null,
 *   surgeonId: int|null, surgeonName: string|null, instrumentistId: int,
 *   instrumentistName: string, startTime: string, endTime: string,
 * }
 */
final class InstrumentistAbsenceOccurrenceImpactMessage
{
    /**
     * @param OccurrenceSnapshot[] $newlyImpacted
     * @param OccurrenceSnapshot[] $noLongerImpacted
     */
    public function __construct(
        public readonly int $absenceId,
        public readonly int $instrumentistId,
        public readonly string $instrumentistName,
        public readonly string $dateStart,
        public readonly string $dateEnd,
        public readonly int $actorId,
        public readonly array $newlyImpacted,
        public readonly array $noLongerImpacted,
        public readonly \DateTimeImmutable $occurredAt,
    ) {
    }
}
