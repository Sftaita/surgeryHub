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

    // ── Absence-driven mission reactions (post-deploy absence auto-mutation) ─
    // None of the cases above carry "this happened because of an absence you/they just
    // declared" framing, and none are wired to email today (in-app/push only) — these three
    // are recipient-perspective-specific, mirroring the SURGEON_POST_COVERED vs
    // PLANNING_MISSION_REASSIGNED split above for the same underlying event.
    case ABSENCE_INSTRUMENTIST_RELEASED = 'ABSENCE_INSTRUMENTIST_RELEASED'; // to the removed instrumentist
    case ABSENCE_SURGEON_MISSION_OPENED = 'ABSENCE_SURGEON_MISSION_OPENED'; // to the surgeon, instrumentist absence
    case ABSENCE_MISSION_CANCELLED      = 'ABSENCE_MISSION_CANCELLED';      // to the instrumentist, surgeon absence
}
