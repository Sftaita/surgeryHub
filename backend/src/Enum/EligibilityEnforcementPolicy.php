<?php

namespace App\Enum;

/**
 * D-101 — named eligibility enforcement policies. `MissionEligibilityService::
 * evaluateForReassignment()` always computes every applicable reason (single source of
 * truth, unchanged); the POLICY decides which of those reasons actually blocks the
 * mutation in a given caller's context. Deliberately a fixed, reviewed set of named
 * policies — never a free-form reasons array passed by each call site, which would let
 * every caller invent its own ad-hoc rule.
 */
enum EligibilityEnforcementPolicy: string
{
    /**
     * Direct manager actions: `assign()`, `reassign()`, `updateSchedule()`,
     * `createPostDeploy()` called outside Mode Modification, `assignInstrumentistDraft()`,
     * generation (`PlanningGeneratorServiceV2::generate()`). Every reason blocks — this is
     * what closes the real Sophie Collette incident.
     */
    case STRICT_ASSIGNMENT = 'STRICT_ASSIGNMENT';

    /**
     * `PlanningModificationService::apply()` (Mode Modification / planning vivant,
     * `POST /api/planning/versions/{id}/apply-modifications`). `ABSENT`/`INACTIVE` block —
     * no legitimate scenario exists anywhere in this codebase for a manager to
     * consciously override someone's declared absence or a deactivated account.
     * `SCHEDULE_CONFLICT` deliberately does NOT block — D-091/D-052 already established,
     * tested behavior: a cross-site double-booking may be a deliberate manager choice (e.g.
     * two short adjacent procedures); `PlanningModificationService::apply()` already calls
     * `PlanningConflictDetectionService::syncAlertsForMission()` after every touched
     * mission, which is the intended, non-blocking surface for this case.
     */
    case PLANNING_MODIFICATION = 'PLANNING_MODIFICATION';

    /** @return EligibilityReason[] */
    public function blockingReasons(): array
    {
        return match ($this) {
            self::STRICT_ASSIGNMENT => [
                EligibilityReason::ABSENT,
                EligibilityReason::SCHEDULE_CONFLICT,
                EligibilityReason::INACTIVE,
            ],
            self::PLANNING_MODIFICATION => [
                EligibilityReason::ABSENT,
                EligibilityReason::INACTIVE,
            ],
        };
    }
}
