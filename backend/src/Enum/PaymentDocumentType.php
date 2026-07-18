<?php

namespace App\Enum;

/**
 * EPIC Exécution & Valorisation, Lot 5 (D-075) — discriminant polymorphe pour Payment
 * (§3/§4 du lot : "il ne doit pas être spécifique aux factures", une seule table sert
 * les deux types de document sans duplication). Pas de FK Doctrine directe vers
 * FirmInvoice/InstrumentistStatement depuis Payment (impossible nativement vers deux
 * tables différentes) — Payment.documentId est validé au niveau applicatif par
 * DocumentPaymentService, jamais au niveau DB.
 */
enum PaymentDocumentType: string
{
    case FIRM_INVOICE = 'FIRM_INVOICE';
    case INSTRUMENTIST_STATEMENT = 'INSTRUMENTIST_STATEMENT';
}
