<?php

namespace App\Enum;

/**
 * Statut d'une ligne du journal SurgeonAbsenceCommunication (Lot A, D-114). En Lot A, seul
 * SENT est produit (envoi toujours synchrone, jamais différé) ; SCHEDULED/CANCELLED/FAILED
 * seront utilisés à partir du Lot B (délai configurable, scheduling cron).
 */
enum AbsenceCommunicationStatus: string
{
    case SCHEDULED = 'SCHEDULED';
    case SENT = 'SENT';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
}
