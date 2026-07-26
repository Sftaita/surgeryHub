<?php

namespace App\Enum;

/**
 * D-084 — canal d'une communication sortante tracée. Distinct de PublicationChannel
 * (NotificationEvent, in-app uniquement) : ce lot ne couvre que PUSH et EMAIL.
 */
enum OutboundNotificationChannel: string
{
    case PUSH = 'PUSH';
    case EMAIL = 'EMAIL';
}
