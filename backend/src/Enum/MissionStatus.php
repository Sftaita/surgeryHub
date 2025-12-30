<?php

namespace App\Enum;

enum MissionStatus: string
{
    case DRAFT = 'DRAFT';
    case OPEN = 'OPEN';
    case ASSIGNED = 'ASSIGNED';
    case SUBMITTED = 'SUBMITTED';
    case VALIDATED = 'VALIDATED';
    case CLOSED = 'CLOSED';
}
