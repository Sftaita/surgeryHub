<?php

namespace App\Service;

/** Resolved channel toggles for one (user, notificationType) pair. */
final class NotificationChannels
{
    public function __construct(
        public readonly bool $inApp,
        public readonly bool $email,
        public readonly bool $push,
    ) {}
}
