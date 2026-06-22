<?php

namespace App\Enum;

enum OccurrenceExceptionType: string
{
    case CANCELLED               = 'CANCELLED';
    case MOVED                   = 'MOVED';
    case INSTRUMENTIST_OVERRIDE  = 'INSTRUMENTIST_OVERRIDE';
    case TIME_OVERRIDE           = 'TIME_OVERRIDE';
}
