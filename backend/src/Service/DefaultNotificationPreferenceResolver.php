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
 * push: always false by default — requires an explicit device subscription (PushSubscription).
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
        NotificationType::SURGEON_MISSION_OPEN_PUBLISHED,
        NotificationType::CATALOGUE_REQUEST_RESOLVED,
        NotificationType::CATALOGUE_REQUEST_IGNORED,
        NotificationType::SURGEON_MISSION_REQUEST_ACCEPTED,
        NotificationType::SURGEON_MISSION_REQUEST_REJECTED,
        NotificationType::ENCODING_ANOMALY_RESOLVED,
        NotificationType::ABSENCE_OCCURRENCE_CANCELLED,
        NotificationType::ABSENCE_OCCURRENCE_CANCELLED_MGR,
        NotificationType::ABSENCE_OCCURRENCE_RESTORED,
        NotificationType::ABSENCE_OCCURRENCE_RESTORED_MGR,
        NotificationType::MISSION_RESTORED,
        NotificationType::MISSION_RESTORED_MGR,
        NotificationType::ABSENCE_IMPACT_SUMMARY,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function resolve(User $user, NotificationType $type): NotificationChannels
    {
        $preference = $this->em->getRepository(NotificationPreference::class)
            ->findOneBy(['user' => $user, 'notificationType' => $type]);

        if ($preference === null) {
            return new NotificationChannels(
                inApp: true,
                email: in_array($type, self::EMAIL_ON_BY_DEFAULT, strict: true),
                push:  false,
            );
        }

        return new NotificationChannels(
            $preference->isInAppEnabled(),
            $preference->isEmailEnabled(),
            $preference->isPushEnabled(),
        );
    }
}
