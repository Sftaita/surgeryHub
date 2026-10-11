<?php

namespace App\Enum;

/**
 * H2 (D-128) — how the email channel relates to push for a given notification.
 *
 *   WHEN_ENABLED                — email sent whenever the resolver enables it (the default,
 *                                 and the rule for every notification migrated in H2).
 *   FALLBACK_WHEN_PUSH_NOT_SENT — D-083 orchestration kept for the types that already used
 *                                 it: email only if push is disabled or was not accepted.
 *   MANDATORY                   — H5a: the email is sent whatever the recipient's preferences
 *                                 (identity / security emails — invitation, email change — and
 *                                 explicit one-off manager actions — manual planning resend,
 *                                 absence reminders). The preference resolver is still asked
 *                                 for the OTHER channels; the decision is recorded with
 *                                 source MANDATORY. Only "no address" can skip it.
 */
enum NotificationEmailPolicy: string
{
    case WHEN_ENABLED                = 'WHEN_ENABLED';
    case FALLBACK_WHEN_PUSH_NOT_SENT = 'FALLBACK_WHEN_PUSH_NOT_SENT';
    case MANDATORY                   = 'MANDATORY';
}
