<?php

namespace App\Enum;

enum InvoiceStatus: string
{
    case DRAFT = 'DRAFT';
    case GENERATED = 'GENERATED';
    case SENT = 'SENT';
    case PAID = 'PAID';

    /**
     * EPIC Exécution & Valorisation, Lot 4 (D-074) — annulation avant émission
     * uniquement (voir FirmInvoiceService/InstrumentistStatementService::cancel()).
     */
    case CANCELLED = 'CANCELLED';

    /**
     * D-137 — brouillon de facture firme abandonné (FirmInvoiceService::abandonDraft()) :
     * il n'a JAMAIS été une facture (aucun numéro, aucune émission, aucun calcul verrouillé).
     * Distinct de CANCELLED (facture GENERATED annulée). Conservé pour l'audit (le journal
     * FirmBillingLineEvent y fait référence) ; masqué par défaut de la liste des factures ;
     * jamais compté dans les statistiques documentaires. Colonne VARCHAR : aucune migration.
     */
    case ABANDONED = 'ABANDONED';
}
