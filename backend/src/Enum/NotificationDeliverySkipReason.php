<?php

namespace App\Enum;

/**
 * H2 (D-128) — why a channel of a notification was deliberately NOT used. Stored on the
 * SKIPPED OutboundNotification row, so "the email was never sent" always has an explicit,
 * honest answer instead of an absence of rows.
 *
 *   DISABLED_BY_PREFERENCE — NotificationPreferenceResolver (explicit preference or the
 *                            type's default) disabled this channel.
 *   NO_PUSH_SUBSCRIPTION   — push enabled, but the recipient has no registered device.
 *   NO_EMAIL_ADDRESS       — email enabled, but the recipient has no address.
 *   COVERED_BY_PUSH        — D-083 policy (email only as a push fallback) and the push was
 *                            accepted by the push service.
 *   NOT_APPLICABLE         — the channel does not exist for this kind of notification.
 */
enum NotificationDeliverySkipReason: string
{
    case DISABLED_BY_PREFERENCE = 'DISABLED_BY_PREFERENCE';
    case NO_PUSH_SUBSCRIPTION   = 'NO_PUSH_SUBSCRIPTION';
    case NO_EMAIL_ADDRESS       = 'NO_EMAIL_ADDRESS';
    case COVERED_BY_PUSH        = 'COVERED_BY_PUSH';
    case NOT_APPLICABLE         = 'NOT_APPLICABLE';
}
