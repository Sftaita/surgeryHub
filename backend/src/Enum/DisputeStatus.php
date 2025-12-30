<?php

namespace App\Enum;

enum DisputeStatus: string
{
    case OPEN = 'OPEN';
    case IN_REVIEW = 'IN_REVIEW';
    case RESOLVED = 'RESOLVED';
    case REJECTED = 'REJECTED';
}
