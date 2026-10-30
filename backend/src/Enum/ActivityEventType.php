<?php

namespace App\Enum;

/**
 * H1 (D-126) — event types NATIVE to the transversal journal, i.e. events that have no
 * specialized audit table of their own (or whose specialized table can lose them, like a
 * deleted DRAFT). Events mirrored from AuditEvent / UserAuditEvent keep their own
 * AuditEventType / UserAuditEventType value as eventType — see ActivityEventTypeRegistry.
 *
 * Only significant business events belong here — never HTTP requests, SQL, debug logs,
 * heartbeats or polling (Sentry/Monolog stay responsible for technical logs).
 */
enum ActivityEventType: string
{
    case ABSENCE_CREATED = 'ABSENCE_CREATED';
    case ABSENCE_UPDATED = 'ABSENCE_UPDATED';
    case ABSENCE_DELETED = 'ABSENCE_DELETED';

    case PLANNING_GENERATED            = 'PLANNING_GENERATED';
    case PLANNING_DEPLOYED             = 'PLANNING_DEPLOYED';
    case PLANNING_DRAFT_DELETED        = 'PLANNING_DRAFT_DELETED';
    case PLANNING_MODIFICATION_APPLIED = 'PLANNING_MODIFICATION_APPLIED';

    // H2 (D-128) — notification traceability. NOTIFICATION_DISPATCHED carries the decision
    // and the immediate outcome of every channel (in-app created, channels SKIPPED with
    // their reason); the asynchronous transitions get their own event.
    case NOTIFICATION_DISPATCHED     = 'NOTIFICATION_DISPATCHED';
    case NOTIFICATION_CHANNEL_QUEUED = 'NOTIFICATION_CHANNEL_QUEUED';
    case NOTIFICATION_CHANNEL_SENT   = 'NOTIFICATION_CHANNEL_SENT';
    case NOTIFICATION_CHANNEL_FAILED = 'NOTIFICATION_CHANNEL_FAILED';

    // H3 (D-129) — one opening episode evaluated by OpenMissionPoolNotifier: why the mission
    // opened, how many were eligible, who was contextually excluded, how many were solicited
    // (zero included — "nobody could take it" is an explicit, visible fact).
    case OPEN_POOL_EVALUATED = 'OPEN_POOL_EVALUATED';

    // H4 (D-130) — one absence-processing run (create / update) as the manager needs to read
    // it: every impacted occurrence with its level (N1 usual / N2 DRAFT / N3 published), the
    // consequence applied, who is informed, what is still uncovered.
    case ABSENCE_IMPACT_EVALUATED = 'ABSENCE_IMPACT_EVALUATED';
    // H4 (D-130) — one person's planning impacted by an absence (one per occurrence and
    // person informed) — the link between the absence / mission event and her notification.
    case USER_PLANNING_IMPACTED = 'USER_PLANNING_IMPACTED';

    // ── H5b (D-132) — domains that were silent. Written by the governed-entity listener
    // (App\Activity\Governed) in the transaction of the change, one event per entity and
    // flush, with a structured diff of the tracked fields only — or, for the delivery of
    // documents and D-114 communications, by their own recorders.

    // Catalogue: intervention types, firms, service offerings and their suggested material /
    // mandatory choices.
    case INTERVENTION_TYPE_CREATED = 'INTERVENTION_TYPE_CREATED';
    case INTERVENTION_TYPE_UPDATED = 'INTERVENTION_TYPE_UPDATED';
    case INTERVENTION_TYPE_DELETED = 'INTERVENTION_TYPE_DELETED';
    case FIRM_CREATED = 'FIRM_CREATED';
    case FIRM_UPDATED = 'FIRM_UPDATED';
    case FIRM_DELETED = 'FIRM_DELETED';
    case FIRM_OFFERING_CREATED = 'FIRM_OFFERING_CREATED';
    case FIRM_OFFERING_UPDATED = 'FIRM_OFFERING_UPDATED';
    case FIRM_OFFERING_DELETED = 'FIRM_OFFERING_DELETED';
    case FIRM_OFFERING_MATERIAL_ADDED   = 'FIRM_OFFERING_MATERIAL_ADDED';
    case FIRM_OFFERING_MATERIAL_REMOVED = 'FIRM_OFFERING_MATERIAL_REMOVED';
    case FIRM_OFFERING_CHOICE_GROUP_CREATED = 'FIRM_OFFERING_CHOICE_GROUP_CREATED';
    case FIRM_OFFERING_CHOICE_GROUP_UPDATED = 'FIRM_OFFERING_CHOICE_GROUP_UPDATED';
    case FIRM_OFFERING_CHOICE_GROUP_DELETED = 'FIRM_OFFERING_CHOICE_GROUP_DELETED';
    case FIRM_OFFERING_CHOICE_OPTION_ADDED   = 'FIRM_OFFERING_CHOICE_OPTION_ADDED';
    case FIRM_OFFERING_CHOICE_OPTION_UPDATED = 'FIRM_OFFERING_CHOICE_OPTION_UPDATED';
    case FIRM_OFFERING_CHOICE_OPTION_REMOVED = 'FIRM_OFFERING_CHOICE_OPTION_REMOVED';
    // Material catalogue.
    case MATERIAL_ITEM_CREATED = 'MATERIAL_ITEM_CREATED';
    case MATERIAL_ITEM_UPDATED = 'MATERIAL_ITEM_UPDATED';
    case MATERIAL_ITEM_DELETED = 'MATERIAL_ITEM_DELETED';

    // Organisation: sites, site groups, habitual planning (posts, shift periods, manual
    // occurrence exceptions), planning alerts raised, D-114 settings.
    case SITE_CREATED = 'SITE_CREATED';
    case SITE_UPDATED = 'SITE_UPDATED';
    case SITE_DELETED = 'SITE_DELETED';
    case SITE_GROUP_CREATED = 'SITE_GROUP_CREATED';
    case SITE_GROUP_UPDATED = 'SITE_GROUP_UPDATED';
    case SITE_GROUP_DELETED = 'SITE_GROUP_DELETED';
    case SITE_GROUP_SITE_ADDED   = 'SITE_GROUP_SITE_ADDED';
    case SITE_GROUP_SITE_REMOVED = 'SITE_GROUP_SITE_REMOVED';
    case SURGEON_SCHEDULE_POST_CREATED     = 'SURGEON_SCHEDULE_POST_CREATED';
    case SURGEON_SCHEDULE_POST_UPDATED     = 'SURGEON_SCHEDULE_POST_UPDATED';
    case SURGEON_SCHEDULE_POST_DEACTIVATED = 'SURGEON_SCHEDULE_POST_DEACTIVATED';
    case SURGEON_SCHEDULE_POST_DELETED     = 'SURGEON_SCHEDULE_POST_DELETED';
    case SHIFT_PERIOD_CREATED     = 'SHIFT_PERIOD_CREATED';
    case SHIFT_PERIOD_UPDATED     = 'SHIFT_PERIOD_UPDATED';
    case SHIFT_PERIOD_DEACTIVATED = 'SHIFT_PERIOD_DEACTIVATED';
    case PLANNING_OCCURRENCE_EXCEPTION_CREATED = 'PLANNING_OCCURRENCE_EXCEPTION_CREATED';
    case PLANNING_OCCURRENCE_EXCEPTION_UPDATED = 'PLANNING_OCCURRENCE_EXCEPTION_UPDATED';
    case PLANNING_OCCURRENCE_EXCEPTION_DELETED = 'PLANNING_OCCURRENCE_EXCEPTION_DELETED';
    case PLANNING_ALERT_RAISED = 'PLANNING_ALERT_RAISED';
    case PLANNING_CONFLICT_WAIVER_GRANTED     = 'PLANNING_CONFLICT_WAIVER_GRANTED';
    case PLANNING_CONFLICT_WAIVER_INVALIDATED = 'PLANNING_CONFLICT_WAIVER_INVALIDATED';
    case ABSENCE_COMMUNICATION_SETTINGS_UPDATED = 'ABSENCE_COMMUNICATION_SETTINGS_UPDATED';

    // People: notification preferences, push devices, legacy User.hourlyRate fields.
    case NOTIFICATION_PREFERENCES_UPDATED = 'NOTIFICATION_PREFERENCES_UPDATED';
    case PUSH_SUBSCRIPTION_CREATED = 'PUSH_SUBSCRIPTION_CREATED';
    case PUSH_SUBSCRIPTION_REMOVED = 'PUSH_SUBSCRIPTION_REMOVED';
    // D-175 — même appareil, nouvel endpoint ; envois acceptés sans accusé de réception.
    case PUSH_SUBSCRIPTION_RENEWED = 'PUSH_SUBSCRIPTION_RENEWED';
    case PUSH_SUBSCRIPTION_SUSPECTED = 'PUSH_SUBSCRIPTION_SUSPECTED';
    case INSTRUMENTIST_LEGACY_RATES_UPDATED = 'INSTRUMENTIST_LEGACY_RATES_UPDATED';

    // Encoding content: interventions and material used (billing basis).
    case MISSION_INTERVENTION_ADDED   = 'MISSION_INTERVENTION_ADDED';
    case MISSION_INTERVENTION_UPDATED = 'MISSION_INTERVENTION_UPDATED';
    case MISSION_INTERVENTION_REMOVED = 'MISSION_INTERVENTION_REMOVED';
    case MISSION_MATERIAL_LINE_ADDED   = 'MISSION_MATERIAL_LINE_ADDED';
    case MISSION_MATERIAL_LINE_UPDATED = 'MISSION_MATERIAL_LINE_UPDATED';
    case MISSION_MATERIAL_LINE_REMOVED = 'MISSION_MATERIAL_LINE_REMOVED';

    // Financial documents: the decision to communicate a document (distinct from the email
    // delivery itself, traced by NOTIFICATION_CHANNEL_*).
    case FIRM_INVOICE_SENT            = 'FIRM_INVOICE_SENT';
    case FIRM_INVOICE_RESENT          = 'FIRM_INVOICE_RESENT';
    case INSTRUMENTIST_STATEMENT_SENT   = 'INSTRUMENTIST_STATEMENT_SENT';
    case INSTRUMENTIST_STATEMENT_RESENT = 'INSTRUMENTIST_STATEMENT_RESENT';

    // D-114: a communication (and its deliveries) recorded in the specialised journal.
    case SURGEON_ABSENCE_COMMUNICATION_RECORDED = 'SURGEON_ABSENCE_COMMUNICATION_RECORDED';

    // Exports (ExportLog mirror).
    case EXPORT_GENERATED = 'EXPORT_GENERATED';

    public function domain(): ActivityDomain
    {
        return match ($this) {
            self::ABSENCE_CREATED, self::ABSENCE_UPDATED, self::ABSENCE_DELETED,
            self::ABSENCE_IMPACT_EVALUATED, self::USER_PLANNING_IMPACTED => ActivityDomain::ABSENCES,
            self::PLANNING_GENERATED, self::PLANNING_DEPLOYED, self::PLANNING_DRAFT_DELETED,
            self::PLANNING_MODIFICATION_APPLIED => ActivityDomain::PLANNING,
            self::NOTIFICATION_DISPATCHED, self::NOTIFICATION_CHANNEL_QUEUED,
            self::NOTIFICATION_CHANNEL_SENT, self::NOTIFICATION_CHANNEL_FAILED => ActivityDomain::NOTIFICATIONS,
            self::OPEN_POOL_EVALUATED => ActivityDomain::MISSIONS,
            self::INTERVENTION_TYPE_CREATED, self::INTERVENTION_TYPE_UPDATED, self::INTERVENTION_TYPE_DELETED,
            self::FIRM_CREATED, self::FIRM_UPDATED, self::FIRM_DELETED,
            self::FIRM_OFFERING_CREATED, self::FIRM_OFFERING_UPDATED, self::FIRM_OFFERING_DELETED,
            self::FIRM_OFFERING_MATERIAL_ADDED, self::FIRM_OFFERING_MATERIAL_REMOVED,
            self::FIRM_OFFERING_CHOICE_GROUP_CREATED, self::FIRM_OFFERING_CHOICE_GROUP_UPDATED, self::FIRM_OFFERING_CHOICE_GROUP_DELETED,
            self::FIRM_OFFERING_CHOICE_OPTION_ADDED, self::FIRM_OFFERING_CHOICE_OPTION_UPDATED, self::FIRM_OFFERING_CHOICE_OPTION_REMOVED => ActivityDomain::CATALOGUE,
            self::MATERIAL_ITEM_CREATED, self::MATERIAL_ITEM_UPDATED, self::MATERIAL_ITEM_DELETED => ActivityDomain::MATERIAL,
            self::SITE_CREATED, self::SITE_UPDATED, self::SITE_DELETED,
            self::SITE_GROUP_CREATED, self::SITE_GROUP_UPDATED, self::SITE_GROUP_DELETED,
            self::SITE_GROUP_SITE_ADDED, self::SITE_GROUP_SITE_REMOVED,
            self::SURGEON_SCHEDULE_POST_CREATED, self::SURGEON_SCHEDULE_POST_UPDATED,
            self::SURGEON_SCHEDULE_POST_DEACTIVATED, self::SURGEON_SCHEDULE_POST_DELETED,
            self::SHIFT_PERIOD_CREATED, self::SHIFT_PERIOD_UPDATED, self::SHIFT_PERIOD_DEACTIVATED,
            self::PLANNING_OCCURRENCE_EXCEPTION_CREATED, self::PLANNING_OCCURRENCE_EXCEPTION_UPDATED,
            self::PLANNING_OCCURRENCE_EXCEPTION_DELETED, self::PLANNING_ALERT_RAISED,
            self::PLANNING_CONFLICT_WAIVER_GRANTED, self::PLANNING_CONFLICT_WAIVER_INVALIDATED => ActivityDomain::PLANNING,
            self::ABSENCE_COMMUNICATION_SETTINGS_UPDATED, self::SURGEON_ABSENCE_COMMUNICATION_RECORDED => ActivityDomain::ROOMS,
            self::NOTIFICATION_PREFERENCES_UPDATED, self::PUSH_SUBSCRIPTION_CREATED, self::PUSH_SUBSCRIPTION_REMOVED,
            self::PUSH_SUBSCRIPTION_RENEWED, self::PUSH_SUBSCRIPTION_SUSPECTED => ActivityDomain::USERS,
            self::INSTRUMENTIST_LEGACY_RATES_UPDATED => ActivityDomain::PRICING,
            self::MISSION_INTERVENTION_ADDED, self::MISSION_INTERVENTION_UPDATED, self::MISSION_INTERVENTION_REMOVED,
            self::MISSION_MATERIAL_LINE_ADDED, self::MISSION_MATERIAL_LINE_UPDATED, self::MISSION_MATERIAL_LINE_REMOVED => ActivityDomain::ENCODING,
            self::FIRM_INVOICE_SENT, self::FIRM_INVOICE_RESENT,
            self::INSTRUMENTIST_STATEMENT_SENT, self::INSTRUMENTIST_STATEMENT_RESENT => ActivityDomain::BILLING,
            self::EXPORT_GENERATED => ActivityDomain::EXPORTS,
        };
    }
}
