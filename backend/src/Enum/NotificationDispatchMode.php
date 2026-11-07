<?php

namespace App\Enum;

/**
 * H2 (D-128) —
 *   ISOLATED — one business event, one recipient: every enabled channel is delivered for
 *              this dispatch alone (one email per dispatch).
 *   BATCH    — one operation touching several subjects for the same recipient (deploy,
 *              Modification batch…): in-app stays per dispatch, but email and push are
 *              grouped into ONE delivery per (recipient, type, correlation) — see
 *              OutboundNotification.groupKey.
 */
enum NotificationDispatchMode: string
{
    case ISOLATED = 'ISOLATED';
    case BATCH    = 'BATCH';
}
