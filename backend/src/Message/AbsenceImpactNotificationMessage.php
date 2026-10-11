<?php

namespace App\Message;

use App\Absence\AbsenceImpact;

/**
 * H4 (D-130) — the N1 / N2 impacts of one absence-processing run (create / update), to be
 * notified asynchronously by AbsenceImpactNotifier. Carries plain snapshots (no entity), so
 * it survives the deletion of a DRAFT or of the absence before the worker runs.
 * Replaces SurgeonAbsenceOccurrencesNeutralizedMessage and
 * InstrumentistAbsenceOccurrenceImpactMessage, and the person-facing part of the absence
 * PlanningAlertRaisedMessage.
 */
final class AbsenceImpactNotificationMessage
{
    /**
     * @param list<array<string, mixed>> $impacts   AbsenceImpact::toArray()
     * @param list<array<string, mixed>> $recovered AbsenceImpact::toArray()
     */
    public function __construct(
        public readonly int $absenceId,
        public readonly string $absentName,
        public readonly string $dateStart,
        public readonly string $dateEnd,
        public readonly array $impacts,
        public readonly array $recovered = [],
    ) {
    }

    /**
     * @param list<AbsenceImpact> $impacts
     * @param list<AbsenceImpact> $recovered
     */
    public static function of(int $absenceId, string $absentName, string $dateStart, string $dateEnd, array $impacts, array $recovered = []): self
    {
        $toArray = static fn (AbsenceImpact $i) => $i->toArray();

        return new self($absenceId, $absentName, $dateStart, $dateEnd, array_map($toArray, $impacts), array_map($toArray, $recovered));
    }
}
