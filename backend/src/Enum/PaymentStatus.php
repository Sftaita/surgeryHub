<?php

namespace App\Enum;

/**
 * EPIC Exécution & Valorisation, Lot 5 (D-075) — dimension FINANCIÈRE d'un document,
 * distincte de sa dimension DOCUMENTAIRE (InvoiceStatus: GENERATED/SENT/CANCELLED).
 * Jamais persistée : toujours dérivée de paidAmount vs grossAmount
 * (DocumentPaymentService::computeBalance()) — §7 du lot, ne jamais stocker le solde en
 * doublon.
 */
enum PaymentStatus: string
{
    case UNPAID = 'UNPAID';
    case PARTIALLY_PAID = 'PARTIALLY_PAID';
    case PAID = 'PAID';
}
