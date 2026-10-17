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
    // H2 (D-127/D-128) — to the instrumentist a manager deliberately removed from a
    // published mission (ASSIGNED → OPEN): her planning changed. Distinct from
    // PLANNING_MISSION_REASSIGNED (someone else was put in her place) and from
    // OPEN_MISSION_AVAILABLE (a pool offer, which she must NOT receive for this episode).
    case PLANNING_MISSION_RELEASED   = 'PLANNING_MISSION_RELEASED';
    // H3 (D-129) — personalized recap of a Planning V2 Modification batch (only the
    // recipient's own added / removed / modified missions, with the updated planning PDF).
    // Formerly an untracked SendBillingEmailMessage; now dispatched like any notification.
    case PLANNING_CHANGE_SUMMARY     = 'PLANNING_CHANGE_SUMMARY';
    case PLANNING_MISSION_ADDED      = 'PLANNING_MISSION_ADDED';
    case PLANNING_MISSION_UPDATED    = 'PLANNING_MISSION_UPDATED';

    // ── Nominative request / direct assignment by a manager (D-125) ──────────
    // MISSION_OFFERED           → the targeted instrumentist: a request awaiting her answer
    //                             (accept = existing claim, or decline).
    // MISSION_ASSIGNED_DIRECTLY → the instrumentist: CONFIRMATION only, the mission is
    //                             already hers (agreed outside SurgicalHub) — never phrased as
    //                             a request, no action expected.
    // MISSION_OFFER_DECLINED    → managers/admins: the target refused, the mission is still
    //                             OPEN and uncovered.
    case MISSION_OFFERED           = 'MISSION_OFFERED';
    case MISSION_ASSIGNED_DIRECTLY = 'MISSION_ASSIGNED_DIRECTLY';
    case MISSION_OFFER_DECLINED    = 'MISSION_OFFER_DECLINED';

    // ── Absence-driven mission reactions (post-deploy absence auto-mutation) ─
    // None of the cases above carry "this happened because of an absence you/they just
    // declared" framing, and none are wired to email today (in-app/push only) — these three
    // are recipient-perspective-specific, mirroring the SURGEON_POST_COVERED vs
    // PLANNING_MISSION_REASSIGNED split above for the same underlying event.
    case ABSENCE_INSTRUMENTIST_RELEASED = 'ABSENCE_INSTRUMENTIST_RELEASED'; // to the removed instrumentist
    case ABSENCE_SURGEON_MISSION_OPENED = 'ABSENCE_SURGEON_MISSION_OPENED'; // to the surgeon, instrumentist absence
    case ABSENCE_MISSION_CANCELLED      = 'ABSENCE_MISSION_CANCELLED';      // to the instrumentist, surgeon absence, no compatible target found

    // CAS B (D-118) — surgeon-absence reaction found a same-day/same-site OPEN mission for the
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
    //
    // RETIRED by H3 (D-129): nothing produces it any more — the absence's released missions
    // are solicited by OpenMissionPoolNotifier like every other opening (email for everyone
    // per preferences, not only for people without push). Kept so the history (feed items,
    // outbound rows) stays readable; hidden from the preferences list (see isRetired()).
    case ABSENCE_POOL_MISSION_AVAILABLE = 'ABSENCE_POOL_MISSION_AVAILABLE'; // retired — historical rows only

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

    // ── Reprise de salle libérée (D-124) ─────────────────────────────────────
    // À l'instrumentiste initialement prévue sur la salle du chirurgien absent, UNIQUEMENT si
    // MissionEligibilityService::evaluate() la juge éligible pour la nouvelle Mission OPEN du
    // repreneur. Priorité informationnelle seulement (jamais une attribution, jamais une
    // exclusivité) : la Mission est OPEN pour tout le pool en même temps, qui reçoit
    // OPEN_MISSION_AVAILABLE. In-app + push selon préférences (même pipeline que
    // MissionLifecycleChangedMessageHandler), email=false par défaut. 27 caractères (< 32).
    case ROOM_TAKEOVER_MISSION_OFFER = 'ROOM_TAKEOVER_MISSION_OFFER';

    // ── H5a — email flows migrated to NotificationDispatcher ─────────────────
    // Encoding reminder D+1 (D-083) — RETIRED by D-143 (replaced by ENCODING_REMINDER,
    // J+1/J+2/J+4). Value kept so the existing outbound / dispatch history keeps its type.
    case ENCODING_REMINDER_D1 = 'ENCODING_REMINDER_D1';

    // Absence reminders sent by a manager (D-051): one individual email per selected person.
    // Explicit one-off manager action → MANDATORY email (see emailIsMandatory()).
    case ABSENCE_REQUEST_MISSING = 'ABSENCE_REQUEST_MISSING';
    case ABSENCE_ENCODED_RECAP   = 'ABSENCE_ENCODED_RECAP';

    // Identity / security (category C) — never subject to business preferences.
    case ACCOUNT_INVITATION    = 'ACCOUNT_INVITATION';
    case ACCOUNT_EMAIL_CHANGED = 'ACCOUNT_EMAIL_CHANGED';

    // H5b (D-132) — the mission declaration flow (Lot B3/B4): formerly in-app rows written
    // directly (no preference, no trace). Same eventType values, so existing feed items keep
    // reading the same; now through NotificationDispatcher (in-app, no email / push content).
    case MISSION_DECLARED          = 'MISSION_DECLARED';          // to managers / admins
    case MISSION_DECLARED_APPROVED = 'MISSION_DECLARED_APPROVED'; // to the declaring instrumentist
    case MISSION_DECLARED_REJECTED = 'MISSION_DECLARED_REJECTED'; // to the declaring instrumentist

    // ── Encodage progressif, relances et retour chirurgien (D-143) ────────────
    // ENCODING_REMINDER   → instrumentiste : relance J+1/J+2/J+4 et relance manuelle (centre
    //                       de notifications ; push / e-mail décidés par EncodingReminderService
    //                       à travers NotificationDispatcher).
    // ENCODING_ESCALATION → managers/admins : encodage toujours en attente à J+4.
    // Ces deux-là sont des obligations de service (validation, rémunération) : jamais
    // configurables (isOperationalObligation()), absentes de l'écran des préférences.
    // ENCODING_SUBMITTED  → chirurgien : l'encodage de sa mission vient d'être soumis
    //                       (ou re-soumis après correction) — lecture, aucune validation exigée.
    // ENCODING_ANOMALY_INSTRUMENTIST → instrumentiste : le chirurgien a signalé un point à
    //                       vérifier sur son encodage (D-100), la coordination le suit.
    case ENCODING_REMINDER   = 'ENCODING_REMINDER';
    case ENCODING_ESCALATION = 'ENCODING_ESCALATION';
    case ENCODING_SUBMITTED  = 'ENCODING_SUBMITTED';
    case ENCODING_ANOMALY_INSTRUMENTIST = 'ENCODING_ANOMALY_INSTRUMENTIST'; // 30 chars
    // D-148 — instrumentiste : un manager a renvoyé son encodage en correction (SUBMITTED) ou
    //                       l'a rouvert (VALIDATED), avec le motif. Mêmes canaux que
    //                       ENCODING_ANOMALY_INSTRUMENTIST (in-app + push selon préférences).
    case ENCODING_CORRECTION_REQUESTED = 'ENCODING_CORRECTION_REQUESTED'; // 29 chars
    // D-154 — instrumentiste : le chirurgien de la mission a complété ou corrigé les
    //                       interventions / le matériel de son encodage. Une notification par
    //                       série de modifications (regroupées, jamais une par clic) ; in-app
    //                       par défaut, push et e-mail selon ses préférences.
    case ENCODING_UPDATED_BY_SURGEON = 'ENCODING_UPDATED_BY_SURGEON'; // 27 chars
    // D-136 — « Rappeler les heures » (manager → instrumentiste), auparavant une chaîne libre
    // sur les lignes outbound ; même valeur pour que l'historique existant garde son type.
    case MISSION_HOURS_REMINDER = 'MISSION_HOURS_REMINDER';

    // ── Refus d'affectation par le chirurgien (D-151) ─────────────────────────
    // ASSIGNMENT_REFUSED_BY_SURGEON → managers opérationnels : la véritable origine d'une
    //                       désaffectation (le chirurgien), que l'instrumentiste ne voit jamais
    //                       (elle reçoit PLANNING_MISSION_RELEASED, formulation neutre).
    // ASSIGNMENT_REVIEW_REQUESTED   → managers opérationnels : le chirurgien demande une
    //                       intervention sur l'affectation (délai de refus écoulé, mission
    //                       imminente ou affectation non refusable). Aucun changement d'état.
    case ASSIGNMENT_REFUSED_BY_SURGEON = 'ASSIGNMENT_REFUSED_BY_SURGEON'; // 29 chars
    case ASSIGNMENT_REVIEW_REQUESTED   = 'ASSIGNMENT_REVIEW_REQUESTED';   // 27 chars

    /**
     * H5a — types whose email is sent whatever the recipient's preferences
     * (NotificationEmailPolicy::MANDATORY): identity / security emails and explicit one-off
     * manager actions addressed to one person. Never offered as a preference switch.
     */
    public function emailIsMandatory(): bool
    {
        return in_array($this, [
            self::PLANNING_RESENT_MANUAL, self::ABSENCE_REQUEST_MISSING, self::ABSENCE_ENCODED_RECAP,
            self::ACCOUNT_INVITATION, self::ACCOUNT_EMAIL_CHANGED,
        ], true);
    }

    /**
     * D-143 — notifications opérationnelles d'encodage : délivrées même si l'utilisateur n'a
     * jamais activé le push (repli e-mail), jamais coupables par une préférence.
     */
    public function isOperationalObligation(): bool
    {
        return in_array($this, [self::ENCODING_REMINDER, self::ENCODING_ESCALATION, self::MISSION_HOURS_REMINDER], true);
    }

    /** Offered in the person's notification preferences (a switch must change something). */
    public function isConfigurable(): bool
    {
        return !$this->isRetired() && !$this->emailIsMandatory() && !$this->isOperationalObligation();
    }

    /**
     * H3 (D-129) — types no code produces any more, kept only so historical rows stay
     * readable. Never offered as a preference (a switch that changes nothing would lie).
     * D-143 — ENCODING_REMINDER_D1 replaced by ENCODING_REMINDER.
     */
    public function isRetired(): bool
    {
        return in_array($this, [self::ABSENCE_POOL_MISSION_AVAILABLE, self::ENCODING_REMINDER_D1], true);
    }
}
