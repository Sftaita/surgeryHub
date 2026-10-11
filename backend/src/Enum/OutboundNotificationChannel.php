<?php

namespace App\Enum;

/**
 * D-084 — canal d'une communication sortante tracée. Distinct de PublicationChannel
 * (NotificationEvent, in-app uniquement).
 *
 * H2 (D-128) — IN_APP ajouté : chaque canal d'une notification passée par
 * NotificationDispatcher a désormais sa propre ligne de livraison, y compris l'in-app
 * (reliée au NotificationEvent créé) et y compris un canal non utilisé (SKIPPED + raison).
 */
enum OutboundNotificationChannel: string
{
    case PUSH = 'PUSH';
    case EMAIL = 'EMAIL';
    case IN_APP = 'IN_APP';
}
