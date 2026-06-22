<?php

namespace App\Enum;

/**
 * Coarse notification categories a user can set channel preferences for.
 * Only PLANNING_ALERT exists today (Batch 7) — covers every PlanningAlert-driven
 * notification regardless of its specific type (SURGEON_ABSENCE, REASSIGNMENT_REQUIRED,
 * etc). Splitting into finer-grained categories (e.g. one per PlanningAlertType) is a
 * future option once real usage shows it's needed — NotificationPreferenceResolver
 * doesn't need to change for that, only this enum gains cases.
 */
enum NotificationType: string
{
    case PLANNING_ALERT = 'PLANNING_ALERT';
}
