<?php

namespace App\Enum;

/** EPIC Exécution & Valorisation, Lot 5 (D-075) — méthodes réellement utilisées, pas de passerelle (Stripe/SEPA) inventée. */
enum PaymentMethod: string
{
    case BANK_TRANSFER = 'BANK_TRANSFER';
    case CASH = 'CASH';
    case OTHER = 'OTHER';
}
