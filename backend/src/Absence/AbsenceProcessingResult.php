<?php

namespace App\Absence;

/** H4 (D-130) — what one absence-processing run did (returned by AbsenceImpactCoordinator). */
final class AbsenceProcessingResult
{
    /**
     * @param list<AbsenceImpact>                                                                              $impacts
     * @param array<int, array<string, mixed>>                                                                 $missionSummaries
     * @param array{restoredOccurrences: array<int, array<string, mixed>>, restoredMissions: array<int, array<string, mixed>>} $reconciliation
     */
    public function __construct(
        public readonly array $impacts = [],
        public readonly array $missionSummaries = [],
        public readonly int $alertsCreated = 0,
        public readonly int $occurrencesNeutralized = 0,
        public readonly int $usualNewlyImpacted = 0,
        public readonly int $usualNoLongerImpacted = 0,
        public readonly array $reconciliation = ['restoredOccurrences' => [], 'restoredMissions' => []],
    ) {
    }

    /** True when the run changed or impacted something a manager would learn about. */
    public function hasVisibleEffect(): bool
    {
        return $this->impacts !== [] || $this->missionSummaries !== [] || $this->alertsCreated > 0
            || $this->occurrencesNeutralized > 0 || $this->usualNewlyImpacted > 0 || $this->usualNoLongerImpacted > 0
            || ($this->reconciliation['restoredOccurrences'] ?? []) !== [] || ($this->reconciliation['restoredMissions'] ?? []) !== [];
    }
}
