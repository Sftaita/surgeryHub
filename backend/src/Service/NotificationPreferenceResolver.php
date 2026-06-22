<?php

namespace App\Service;

use App\Entity\User;
use App\Enum\NotificationType;

/**
 * Contract any notification-dispatching code (PlanningAlertRaisedMessageHandler today,
 * any future notification handler tomorrow) calls to decide which channels to actually
 * use for a given user — so individual handlers never hardcode "always send email" or
 * "always push". A future settings UI only needs to read/write NotificationPreference
 * rows; this interface and its call sites never need to change.
 */
interface NotificationPreferenceResolver
{
    public function resolve(User $user, NotificationType $type): NotificationChannels;
}
