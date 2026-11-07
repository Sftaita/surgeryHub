<?php

namespace App\Notification;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\NotificationEmailPolicy;
use App\Enum\NotificationType;

/**
 * H2 (D-128) — "this person should be told this, about that". Built by the business side
 * (handlers); interpreted only by NotificationDispatcher, which alone decides channels
 * (through NotificationPreferenceResolver) and records every outcome.
 *
 * $inAppPayload    — the NotificationEvent payload (the in-app feed's existing shape).
 * $email / $push   — the content of each channel; null means the channel does not exist
 *                    for this notification (recorded SKIPPED / NOT_APPLICABLE, never silent).
 * $inApp           — H5a: false when the notification has no in-app item (identity emails,
 *                    absence reminders, encoding reminder) — recorded SKIPPED / NOT_APPLICABLE.
 * $emailAddress    — H5a: explicit address (email change: the PREVIOUS address); defaults to
 *                    the recipient's current address. Always recorded on the delivery.
 * $confidential    — H5a: the email carries a secret (invitation token): its rendered body is
 *                    never copied into outbound_notification, its context never into a payload.
 * $episodeRef      — identifies the business episode (e.g. one lifecycle transition). Part
 *                    of the idempotency key: a Messenger retry of the same episode never
 *                    produces a second dispatch. Defaults to the operation's correlationId.
 */
final class NotificationIntent
{
    /** @param array<string, mixed> $inAppPayload */
    public function __construct(
        public readonly User $recipient,
        public readonly NotificationType $type,
        public readonly array $inAppPayload,
        public readonly ?Mission $mission = null,
        public readonly ?EmailContent $email = null,
        public readonly ?PushContent $push = null,
        public readonly ?string $episodeRef = null,
        public readonly ?string $subjectType = null,
        public readonly ?int $subjectId = null,
        public readonly NotificationEmailPolicy $emailPolicy = NotificationEmailPolicy::WHEN_ENABLED,
        // H4 (D-130) — missions this notification presents as part of the recipient's planning.
        public readonly ?MissionCommunicationSpec $communicates = null,
        public readonly bool $inApp = true,
        public readonly ?string $emailAddress = null,
        public readonly bool $confidential = false,
    ) {
    }
}
