<?php

namespace App\Enum;

/**
 * D-084 — statut honnête d'une communication sortante. SENT signifie uniquement que le
 * fournisseur (Push) ou le transport (email) a accepté l'envoi — jamais que le
 * destinataire l'a lu. Ne jamais ajouter DELIVERED/OPENED/READ/CLICKED sans une preuve
 * technique réelle (voir D-084).
 */
enum OutboundNotificationStatus: string
{
    case QUEUED = 'QUEUED';
    case SENT = 'SENT';
    case FAILED = 'FAILED';
    case SKIPPED = 'SKIPPED';
}
