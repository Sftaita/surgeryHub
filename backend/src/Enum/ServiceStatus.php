<?php

namespace App\Enum;

enum ServiceStatus: string
{
    case CALCULATED = 'CALCULATED';
    case APPROVED = 'APPROVED';
    case PAID = 'PAID';
}
