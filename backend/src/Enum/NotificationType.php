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
    case ABSENCE_MISSION_CANCELLED      = 'ABSENCE_MISSION_CANCELLED';      // to the instrumentist, surgeon absence, no compatible target found

    // CAS B (D-117) — surgeon-absence reaction found a same-day/same-site OPEN mission for the
    // freed instrumentist and reassigned them automatically. Mutually exclusive with
    // ABSENCE_MISSION_CANCELLED for that same instrumentist/mission: never both (see
    // AbsenceMissionReactionService::react()).
    case ABSENCE_INSTRUMENTIST_REASSIGNED = 'ABSENCE_INSTRUMENTIST_REASSIGNED'; // to the reassigned instrumentist

    // ── Self-service absence, no mission impact (Lot 3, D-097) ───────────────
    // A surgeon/instrumentist declared their own absence (self-service, AbsenceController's
    // PLANNING_MANAGE-gated endpoint is manager-only and never triggers this) and it did NOT
    // overlap any actionable mission — so PLANNING_ALERT above never fires for it, and without
    // this category a manager would have zero visibility that a self-declared absence exists
    // at all. Deliberately in-app only, never email/push regardless of stored preference (see
    // AbsenceSelfDeclaredMessageHandler) — informational ("FYI, no action needed today"), not
    // urgent the way an alert-raising absence already is via PLANNING_ALERT.
    case ABSENCE_SELF_DECLARED = 'ABSENCE_SELF_DECLARED';

    // ── Surgeon absence, future Post occurrence with no Mission yet (Lot 3, D-103) ──
    // Distinct from the "post-deploy mission reactions" block above: this fires for a
    // theoretical SurgeonSchedulePost occurrence that falls inside a surgeon absence
    // window but has no generated Mission at all — nothing to release/cancel, just a
    // neutralization to record and surface before generation ever runs. Grouped by
    // absence + recipient (never one notification per occurrence — see
    // SurgeonAbsenceOccurrencesNeutralizedMessageHandler). Named ..._CANCELLED (not
    // ..._NEUTRALIZED) to fit the 32-char notification_preference.notification_type
    // column (VARCHAR(32), see DefaultNotificationPreferenceResolverTest) and to match
    // the underlying PlanningOccurrenceException type it mirrors (CANCELLED).
    case ABSENCE_OCCURRENCE_CANCELLED     = 'ABSENCE_OCCURRENCE_CANCELLED';     // to the post's default instrumentist
    case ABSENCE_OCCURRENCE_CANCELLED_MGR = 'ABSENCE_OCCURRENCE_CANCELLED_MGR'; // dead since Lot 5 (D-105) — kept, see below

    // Symmetric case: an INSTRUMENTIST absence covers a future Post occurrence with no
    // Mission generated yet (complementary lot to D-103 — see
    // InstrumentistAbsenceOccurrenceImpactService). One consolidated recap per surgeon per
    // absence-processing run, covering both newly-impacted and no-longer-impacted
    // occurrences in the same message (mirrors the Lot 5 consolidation philosophy instead of
    // adding a second _RESTORED-style case).
    case ABSENCE_OCCURRENCE_UNCOVERED = 'ABSENCE_OCCURRENCE_UNCOVERED'; // to the surgeon(s) concerned

    // Email fallback for the existing OPEN_MISSION_AVAILABLE push-only pool fan-out
    // (MissionLifecycleChangedMessageHandler::sendOpenMissionAvailableNotifications), scoped
    // to missions released by an instrumentist absence and restricted to eligible
    // instrumentists with NO push subscription at all — never a duplicate of the push that
    // already went out to everyone else. Grouped: one email per recipient per
    // absence-processing run, covering every mission they're eligible for in that run.
    case ABSENCE_POOL_MISSION_AVAILABLE = 'ABSENCE_POOL_MISSION_AVAILABLE'; // to eligible instrumentists without push

    // D-110 (J-14) — a Mission stayed OPEN into the 14-day-before-start window with no
    // escalation yet sent for the current episode. One per episode (never a daily repeat —
    // see mission.uncoveredEscalationSentAt), to the surgeon. 28 chars, fits length: 32.
    case MISSION_UNCOVERED_ESCALATION = 'MISSION_UNCOVERED_ESCALATION'; // to the surgeon

    // ── Reversal: absence deleted/shortened, restoration performed (Lot 4, D-104) ────
    // Only ever dispatched on a REAL, safe restoration — never a blanket "your absence was
    // deleted" notice (that stays ABSENCE_SELF_DECLARED-adjacent territory, see
    // AbsenceMissionReactionService::onAbsenceDeleted()'s generic manager-only fallback).
    case ABSENCE_OCCURRENCE_RESTORED     = 'ABSENCE_OCCURRENCE_RESTORED';     // to the post's default instrumentist
    case ABSENCE_OCCURRENCE_RESTORED_MGR = 'ABSENCE_OCCURRENCE_RESTORED_MGR'; // dead since Lot 5 (D-105) — kept, see below
    case MISSION_RESTORED                = 'MISSION_RESTORED';               // to the surgeon and/or the re-assigned instrumentist
    case MISSION_RESTORED_MGR            = 'MISSION_RESTORED_MGR';           // dead since Lot 5 (D-105) — kept, see below

    // ── Consolidated manager recap (Lot 5, D-105) ─────────────────────────────
    // Replaces ABSENCE_OCCURRENCE_CANCELLED_MGR and MISSION_RESTORED_MGR above as the ONLY
    // manager-facing notification for any absence create/update/delete — those two are kept
    // as enum cases (never dispatched again) purely so a previously-stored
    // NotificationPreference row referencing them doesn't throw a ValueError on read; they
    // are never referenced by any handler anymore. One category regardless of action
    // (CREATED/UPDATED/DELETED) or role (SURGEON/INSTRUMENTIST) carried in the payload —
    // a manager configures ONE preference for "absence impact", not four. In-app + email,
    // dispatched at most once per absence-processing run, and only when at least one of the
    // six impact buckets is non-empty (see AbsenceImpactSummaryMessage).
    case ABSENCE_IMPACT_SUMMARY = 'ABSENCE_IMPACT_SUMMARY';

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

    // ── Demande de mission chirurgien (Lot 5, D-099) ─────────────────────────
    // Un chirurgien demande au manager l'ajout d'une mission (SurgeonMissionRequest) —
    // même famille de raisonnement que CATALOGUE_REQUEST_CREATED/RESOLVED/IGNORED :
    // création → manager/admin, in-app + push uniquement, jamais email (pas urgent, le
    // manager la retrouve sur l'écran de revue) ; décision (accepted/rejected) →
    // chirurgien, actionnable/attendu, push d'abord puis repli email comme
    // CATALOGUE_REQUEST_RESOLVED/IGNORED.
    case SURGEON_MISSION_REQUEST_CREATED  = 'SURGEON_MISSION_REQUEST_CREATED';
    case SURGEON_MISSION_REQUEST_ACCEPTED = 'SURGEON_MISSION_REQUEST_ACCEPTED';
    case SURGEON_MISSION_REQUEST_REJECTED = 'SURGEON_MISSION_REQUEST_REJECTED';

    // ── Signalement d'anomalie d'encodage (Lot 6, D-100) ─────────────────────
    // Même famille de raisonnement que SURGEON_MISSION_REQUEST_CREATED/ACCEPTED : un
    // chirurgien signale une anomalie sur l'encodage de sa mission (EncodingAnomalyReport)
    // → managers/admins, in-app + push uniquement, jamais email (le manager la retrouve
    // sur le détail mission) ; résolution → chirurgien, actionnable/attendu, push puis
    // repli email.
    case ENCODING_ANOMALY_REPORTED = 'ENCODING_ANOMALY_REPORTED';
    case ENCODING_ANOMALY_RESOLVED = 'ENCODING_ANOMALY_RESOLVED';
}
