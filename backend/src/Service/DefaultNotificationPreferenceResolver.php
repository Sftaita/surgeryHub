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
