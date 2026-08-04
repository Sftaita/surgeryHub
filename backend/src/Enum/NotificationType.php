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

    // ── Manual resend (D-090, anomalie fonctionnelle 1) ──────────────────────
    // A manager explicitly re-sending one person's currently-published plan on demand —
    // never diff-driven, never fanned out to anyone else. Not gated by
    // NotificationPreferenceResolver (an explicit one-off manager action always sends,
    // unlike the policy-driven categories above).
    case PLANNING_RESENT_MANUAL = 'PLANNING_RESENT_MANUAL';

    // ── Pre-deploy publish (Point 8, audit UX) ────────────────────────────────
    // A manager publishes a mission (DRAFT → OPEN) for a specific surgeon — distinct from
    // PLANNING_DEPLOYED_SURGEON (bulk deploy summary, no single Mission) and from
    // SURGEON_POST_COVERED/UNCOVERED (post-deploy claim/release). No patient data.
    case SURGEON_MISSION_OPEN_PUBLISHED = 'SURGEON_MISSION_OPEN_PUBLISHED';

    // ── Catalogue proposal outcome — instrumentist (D-093) ───────────────────
    // An instrumentist proposed a missing InterventionType or MaterialItem during
    // encoding (InterventionTypeRequest / MaterialItemRequest) and the manager just
    // processed it — one shared category for both request kinds (the requester's
    // channel preference is "tell me what happened to my catalogue proposals", not
    // separately for interventions vs material) ; which kind and label are carried in
    // the message/payload, never a second NotificationType.
    case CATALOGUE_REQUEST_RESOLVED = 'CATALOGUE_REQUEST_RESOLVED'; // accepted, now in the catalogue
    case CATALOGUE_REQUEST_IGNORED  = 'CATALOGUE_REQUEST_IGNORED';  // not retained

    // ── Catalogue proposal creation — manager/admin (follow-up to D-093) ─────
    // An instrumentist just submitted a new InterventionTypeRequest or
    // MaterialItemRequest — every active manager/admin (same targeting as
    // PLANNING_ALERT, see AbsenceImpactService::buildNotification()) needs to know
    // one is waiting for them. In-app + push only, deliberately never email (even as
    // a push-failure fallback) — a manager already checks the catalogue-requests
    // screen regularly, and a proposal isn't urgent the way a mission cancellation
    // is; adding email here would just be noise. One shared category for both
    // request kinds, same reasoning as CATALOGUE_REQUEST_RESOLVED/IGNORED above.
    case CATALOGUE_REQUEST_CREATED = 'CATALOGUE_REQUEST_CREATED';
}
