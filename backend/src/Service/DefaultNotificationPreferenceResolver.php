<?php

namespace App\Service;

use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Enum\NotificationType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Looks up a stored NotificationPreference row; falls back to the hardcoded product
 * defaults when none exists (no settings UI has been built yet, so no user has ever
 * had the chance to create one — this is expected, not a degraded state):
 *   - in-app: enabled
 *   - email: enabled (planning alerts are considered "important" — there is only one
 *     NotificationType today, so this is the blanket default; splitting into
 *     important/non-important categories is a future enum case, not a resolver change)
 *   - push: disabled until the user explicitly opts in (requires a device subscription
 *     anyway — see PushSubscription — so defaulting to on would be silently inert at best)
 */
class DefaultNotificationPreferenceResolver implements NotificationPreferenceResolver
{
    private const DEFAULT_IN_APP = true;
    private const DEFAULT_EMAIL  = true;
    private const DEFAULT_PUSH   = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function resolve(User $user, NotificationType $type): NotificationChannels
    {
        $preference = $this->em->getRepository(NotificationPreference::class)
            ->findOneBy(['user' => $user, 'notificationType' => $type]);

        if ($preference === null) {
            return new NotificationChannels(self::DEFAULT_IN_APP, self::DEFAULT_EMAIL, self::DEFAULT_PUSH);
        }

        return new NotificationChannels(
            $preference->isInAppEnabled(),
            $preference->isEmailEnabled(),
            $preference->isPushEnabled(),
        );
    }
}
