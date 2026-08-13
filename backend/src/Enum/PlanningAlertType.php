<?php

namespace App\Enum;

enum PlanningAlertType: string
{
    case SURGEON_ABSENCE        = 'SURGEON_ABSENCE';
    case INSTRUMENTIST_ABSENCE  = 'INSTRUMENTIST_ABSENCE';
    case SURGEON_CONFLICT       = 'SURGEON_CONFLICT';
    case INSTRUMENTIST_CONFLICT = 'INSTRUMENTIST_CONFLICT';
    case REASSIGNMENT_REQUIRED  = 'REASSIGNMENT_REQUIRED';
    case OCCURRENCE_CANCELLED   = 'OCCURRENCE_CANCELLED';

    /**
     * Lot 6 (D-106) — a currently ASSIGNED mission whose instrumentist has since become
     * inactive. Deliberately alert-only, never auto-corrected: unlike ABSENT (a temporal
     * fact backed by the Absence entity, with a full create/delete/restore lifecycle),
     * "inactive" has no reactivation counterpart in this codebase — releasing the mission
     * automatically would have no symmetric restoration path if the account is reactivated
     * later. The manager decides (reassign or open as available).
     */
    case INSTRUMENTIST_INACTIVE = 'INSTRUMENTIST_INACTIVE';
}
