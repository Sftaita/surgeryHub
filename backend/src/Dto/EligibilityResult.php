<?php

namespace App\Dto;

use App\Entity\Absence;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\EligibilityEnforcementPolicy;
use App\Enum\EligibilityReason;

final readonly class EligibilityResult
{
    public bool $eligible;

    /**
     * D-102 (Lot 2) — $absence/$conflictingMission are optional, display-only detail:
     * the specific Absence/Mission row that produced ABSENT/SCHEDULE_CONFLICT, so a
     * candidate-list UI can render "Absente — 01/08 → 16/08" instead of just a bare
     * reason code. Populated only by evaluateAllCandidates()/evaluateRoster() (display
     * methods); always null from evaluate()/evaluateForReassignment() (mutation-time
     * gates, which only need the boolean/reason, never the entity). Never a second
     * eligibility computation — same reasons array either way.
     *
     * @param EligibilityReason[] $reasons
     */
    public function __construct(
        public User $candidate,
        public array $reasons,
        public ?Absence $absence = null,
        public ?Mission $conflictingMission = null,
    ) {
        $this->eligible = empty($reasons);
    }

    /**
     * D-102 — contextualized selectability: whether this candidate is selectable in the
     * given policy's context, e.g. under PLANNING_MODIFICATION a SCHEDULE_CONFLICT-only
     * result is still selectable (D-091/D-052, non-blocking + PlanningAlert) even though
     * $eligible (the raw fact) is false. Never recomputed frontend-side — the single
     * EligibilityEnforcementPolicy::blockingReasons() contract, reused verbatim.
     */
    public function selectableUnder(EligibilityEnforcementPolicy $policy): bool
    {
        foreach ($this->reasons as $reason) {
            if (in_array($reason, $policy->blockingReasons(), true)) {
                return false;
            }
        }
        return true;
    }
}
