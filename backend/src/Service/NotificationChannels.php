<?php

namespace App\Service;

/** Resolved channel toggles for one (user, notificationType) pair. */
final class NotificationChannels
{
    /** H2 (D-128) — the toggles come from a stored NotificationPreference row. */
    public const SOURCE_PREFERENCE = 'PREFERENCE';
    /** H2 (D-128) — no stored row: the type's product default applied. */
    public const SOURCE_DEFAULT = 'DEFAULT';
    /** H5a — email forced by NotificationEmailPolicy::MANDATORY (other channels per preferences). */
    public const SOURCE_MANDATORY = 'MANDATORY';

    public function __construct(
        public readonly bool $inApp,
        public readonly bool $email,
        public readonly bool $push,
        public readonly string $source = self::SOURCE_DEFAULT,
    ) {}
}
