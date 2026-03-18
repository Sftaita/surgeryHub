<?php

namespace App\Enum;

enum InvoiceStatus: string
{
    case DRAFT = 'DRAFT';
    case GENERATED = 'GENERATED';
    case SENT = 'SENT';
    case PAID = 'PAID';
}
