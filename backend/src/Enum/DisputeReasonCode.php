<?php

namespace App\Enum;

enum DisputeReasonCode: string
{
    case DURATION_INCOHERENT = 'DURATION_INCOHERENT';
    case WRONG_DATE = 'WRONG_DATE';
    case DUPLICATE = 'DUPLICATE';
    case OTHER = 'OTHER';
}
