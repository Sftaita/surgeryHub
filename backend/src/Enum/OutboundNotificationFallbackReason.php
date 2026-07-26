<?php

namespace App\Enum;

/** D-084 — pourquoi un canal Push non livrable a déclenché un repli email. */
enum OutboundNotificationFallbackReason: string
{
    case NO_SUBSCRIPTION = 'NO_SUBSCRIPTION';
    case EXPIRED = 'EXPIRED';
    case ALL_FAILED = 'ALL_FAILED';
    case PUSH_DISABLED = 'PUSH_DISABLED';
}
