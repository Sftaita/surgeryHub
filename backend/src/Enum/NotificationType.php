<?php

namespace App\Enum;

/**
 * Notification categories a user can configure channel preferences for.
 * All backing values must be ≤ 32 chars (notification_preference.notification_type VARCHAR(32)).
 *
 * PLANNING_ALERT: original Batch 7 category — covers every PlanningAlert-driven notification.
 * Batch 15A adds 10 post-deploy and living-planning categories.
 */
enum NotificationType: string
{
    // ── Pre-existing (Batch 7) ────────────────────────────────────────────────
    case PLANNING_ALERT = 'PLANNING_ALERT';

    // ── Deploy notifications (Batch 15A / 15C) ───────────────────────────────
    case PLANNING_DEPLOYED_INSTRUMENTIST = 'PLANNING_DEPLOYED_INSTRUMENTIST'; // 32 chars exactly
    case PLANNING_DEPLOYED_SURGEON       = 'PLANNING_DEPLOYED_SURGEON';
    case PLANNING_DEPLOYED_MANAGER       = 'PLANNING_DEPLOYED_MANAGER';

    // ── Pool + coverage notifications (Batch 15A / 15D / 15E) ────────────────
    case OPEN_MISSION_AVAILABLE  = 'OPEN_MISSION_AVAILABLE';
    case SURGEON_POST_COVERED    = 'SURGEON_POST_COVERED';
    case SURGEON_POST_UNCOVERED  = 'SURGEON_POST_UNCOVERED';

    // ── Post-deploy lifecycle notifications (Batch 15A / future) ─────────────
    case PLANNING_MISSION_REASSIGNED = 'PLANNING_MISSION_REASSIGNED';
    case PLANNING_MISSION_CANCELLED  = 'PLANNING_MISSION_CANCELLED';
    case PLANNING_MISSION_ADDED      = 'PLANNING_MISSION_ADDED';
    case PLANNING_MISSION_UPDATED    = 'PLANNING_MISSION_UPDATED';
}
