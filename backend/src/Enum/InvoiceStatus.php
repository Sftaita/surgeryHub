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
}
