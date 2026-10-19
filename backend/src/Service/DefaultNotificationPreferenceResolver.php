<?php

namespace App\Service;

use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Enum\NotificationType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Looks up a stored NotificationPreference row; falls back to per-type product defaults
 * when none exists (Batch 15A — replaces the former blanket email=true default).
 *
 * Per-type defaults (roadmap §7 Notification Matrix):
 *   - PLANNING_ALERT, PLANNING_DEPLOYED_*: inApp=true, email=true  (important / actionable)
 *   - PLANNING_MISSION_CANCELLED:          inApp=true, email=true  (urgent — may require re-assignment)
 *   - ABSENCE_*:                           inApp=true, email=true  (urgent — same "your mission just
 *                                           changed" urgency as PLANNING_MISSION_CANCELLED, just
 *                                           triggered by an absence instead of a manual action)
 *   - ABSENCE_INSTRUMENTIST_REASSIGNED
 *     (CAS B, D-118):                       inApp=true, email=true — same urgency as
 *                                           ABSENCE_MISSION_CANCELLED, the other outcome of the
 *                                           exact same event (mutually exclusive per mission).
 *   - ABSENCE_SELF_DECLARED (Lot 3):       inApp=true, email=false (deliberate exception within the
 *                                           ABSENCE_* family — fires only when a self-declared absence
 *                                           did NOT impact any mission, informational only; see
 *                                           AbsenceSelfDeclaredMessageHandler, which never sends email
 *                                           for this type regardless of stored preference)
 *   - ABSENCE_OCCURRENCE_CANCELLED / _MGR (Lot 3, D-103):  inApp=true, email=true — same
 *                                           urgency as the rest of the ABSENCE_* family: a habitual
 *                                           future Post occurrence is neutralized before any Mission
 *                                           was ever generated for it.
 *   - ABSENCE_OCCURRENCE_RESTORED,
 *     MISSION_RESTORED (Lot 4, D-104):       inApp=true, email=true — same urgency as the
 *                                           reversal they undo; only ever dispatched on a real,
 *                                           safe restoration (never a blanket "absence deleted" notice).
 *   - ABSENCE_OCCURRENCE_CANCELLED_MGR, ABSENCE_OCCURRENCE_RESTORED_MGR,
 *     MISSION_RESTORED_MGR:                  dead since Lot 5 (D-105) — kept in
 *                                           EMAIL_ON_BY_DEFAULT only so a previously-stored
 *                                           preference row's default lookup stays meaningful;
 *                                           no handler dispatches these types anymore.
 *   - ABSENCE_IMPACT_SUMMARY (Lot 5, D-105): inApp=true, email=true — the single consolidated
 *                                           manager recap for any absence create/update/delete,
 *                                           replacing the three types above.
 *   - CATALOGUE_REQUEST_RESOLVED/IGNORED:  inApp=true, email=true  (actionable — the instrumentist is
 *                                           waiting on a yes/no for a catalogue proposal, D-093)
 *   - CATALOGUE_REQUEST_CREATED:           inApp=true, email=false (follow-up to D-093 — a manager
 *                                           is told a proposal is waiting, not urgent enough for
 *                                           email; deliberately excluded even as a push-failure
 *                                           fallback, see CatalogueRequestCreatedMessageHandler)
 *   - SURGEON_MISSION_REQUEST_ACCEPTED/
 *     REJECTED (Lot 5, D-099):              inApp=true, email=true  (actionable — same reasoning as
 *                                           CATALOGUE_REQUEST_RESOLVED/IGNORED, the surgeon is
 *                                           waiting on a yes/no for their mission request)
 *   - SURGEON_MISSION_REQUEST_CREATED
 *     (Lot 5, D-099):                       inApp=true, email=false (manager side, same reasoning
 *                                           as CATALOGUE_REQUEST_CREATED — not urgent)
 *   - All others (pool, coverage, updates): inApp=true, email=false (informational)
 *
 *   - ENCODING_REMINDER_D1 (H5a):          inApp=true (no in-app item exists), email=true, push=true —
 *                                           push first, email as fallback, as before H5a.
 *   - mandatory-email types (H5a, NotificationType::emailIsMandatory()): the email ignores
 *                                           this resolver altogether (NotificationEmailPolicy::MANDATORY).
 *
 * push: false by default — except PUSH_ON_BY_DEFAULT (OPEN_MISSION_AVAILABLE, H3/D-129); a push
 * is only ever delivered to a registered device (PushSubscription).
 */
class DefaultNotificationPreferenceResolver implements NotificationPreferenceResolver
{
    /**
     * Types whose default is email=true (urgent / important per notification matrix).
     * All other types default to email=false.
     */
    private const EMAIL_ON_BY_DEFAULT = [
        NotificationType::PLANNING_ALERT,
        NotificationType::PLANNING_DEPLOYED_INSTRUMENTIST,
        NotificationType::PLANNING_DEPLOYED_SURGEON,
        NotificationType::PLANNING_DEPLOYED_MANAGER,
        NotificationType::PLANNING_MISSION_CANCELLED,
        NotificationType::ABSENCE_INSTRUMENTIST_RELEASED,
        NotificationType::ABSENCE_SURGEON_MISSION_OPENED,
        NotificationType::ABSENCE_MISSION_CANCELLED,
        NotificationType::ABSENCE_INSTRUMENTIST_REASSIGNED,
        NotificationType::SURGEON_MISSION_OPEN_PUBLISHED,
        NotificationType::CATALOGUE_REQUEST_RESOLVED,
        NotificationType::CATALOGUE_REQUEST_IGNORED,
        // D-113 (amende D-094) — une proposition catalogue non traitée ne doit plus
        // pouvoir passer inaperçue faute d'avoir été vue dans l'app.
        NotificationType::CATALOGUE_REQUEST_CREATED,
        NotificationType::SURGEON_MISSION_REQUEST_ACCEPTED,
        NotificationType::SURGEON_MISSION_REQUEST_REJECTED,
        NotificationType::ENCODING_ANOMALY_RESOLVED,
        NotificationType::ABSENCE_OCCURRENCE_CANCELLED,
        NotificationType::ABSENCE_OCCURRENCE_CANCELLED_MGR,
        NotificationType::ABSENCE_OCCURRENCE_UNCOVERED,
        NotificationType::ABSENCE_POOL_MISSION_AVAILABLE,
        NotificationType::ABSENCE_OCCURRENCE_RESTORED,
        NotificationType::ABSENCE_OCCURRENCE_RESTORED_MGR,
        NotificationType::MISSION_RESTORED,
        NotificationType::MISSION_RESTORED_MGR,
        NotificationType::ABSENCE_IMPACT_SUMMARY,
        NotificationType::MISSION_UNCOVERED_ESCALATION,
        // D-125 — a request awaiting an answer, the confirmation of an assignment made on
        // the instrumentist's behalf, and a refusal leaving a mission uncovered: none may go
        // unnoticed for want of opening the app.
        NotificationType::MISSION_OFFERED,
        NotificationType::MISSION_ASSIGNED_DIRECTLY,
        NotificationType::MISSION_OFFER_DECLINED,
        // H2 (D-127) — a manager removed you from a published mission: a real change of your
        // planning, same urgency as PLANNING_MISSION_CANCELLED.
        NotificationType::PLANNING_MISSION_RELEASED,
        // H3 (D-129) — a real OPEN mission proposed to the eligible pool: nobody should miss
        // it for want of opening the app (validated decision, this type only).
        NotificationType::OPEN_MISSION_AVAILABLE,
        // H3 (D-129) — the Modification recap was always emailed: unchanged default.
        NotificationType::PLANNING_CHANGE_SUMMARY,
        // H5a — retired by D-143 (kept for historical rows): unchanged default.
        NotificationType::ENCODING_REMINDER_D1,
        // D-143 — operational encoding reminders (not configurable): the email channel is
        // always available; EncodingReminderService decides fallback (J+1/J+2) or mandatory (J+4).
        NotificationType::ENCODING_REMINDER,
        NotificationType::ENCODING_ESCALATION,
        // D-136 — rappel des heures : e-mail de secours (non configurable).
        NotificationType::MISSION_HOURS_REMINDER,
        // D-151 — a surgeon refusal leaves a mission uncovered, a review request awaits a
        // manager decision: same urgency as MISSION_OFFER_DECLINED (D-125).
        NotificationType::ASSIGNMENT_REFUSED_BY_SURGEON,
        NotificationType::ASSIGNMENT_REVIEW_REQUESTED,
    ];

    /**
     * H3 (D-129) — types whose default is push=true (effective only when the person has a
     * registered device — otherwise the push delivery is SKIPPED / NO_PUSH_SUBSCRIPTION).
     * Every other type keeps push=false by default.
     */
    private const PUSH_ON_BY_DEFAULT = [
        NotificationType::OPEN_MISSION_AVAILABLE,
        // H5a — the encoding reminder was always push first (D-083), before any preference
        // was consulted: same default, now an actual, changeable preference.
        NotificationType::ENCODING_REMINDER_D1,
        // D-143 — the reminder is pushed whenever a device is really subscribed ("push si
        // opérationnel"), the email being the fallback. Not configurable.
        NotificationType::ENCODING_REMINDER,
        // D-136 — the hours reminder was always push first (unconditional): unchanged.
        NotificationType::MISSION_HOURS_REMINDER,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /** The type's product default (no stored preference) — also the "before" of a first save (H5b). */
    public static function defaultsFor(NotificationType $type): NotificationChannels
    {
        return new NotificationChannels(
            inApp: true,
            email: in_array($type, self::EMAIL_ON_BY_DEFAULT, strict: true),
            push:  in_array($type, self::PUSH_ON_BY_DEFAULT, strict: true),
        );
    }

    public function resolve(User $user, NotificationType $type): NotificationChannels
    {
        $preference = $this->em->getRepository(NotificationPreference::class)
            ->findOneBy(['user' => $user, 'notificationType' => $type]);

        if ($preference === null) {
            return self::defaultsFor($type);
        }

        return new NotificationChannels(
            $preference->isInAppEnabled(),
            $preference->isEmailEnabled(),
            $preference->isPushEnabled(),
            NotificationChannels::SOURCE_PREFERENCE,
        );
    }
}
