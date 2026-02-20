<?php

namespace App\Enum;

enum MissionStatus: string
{
    case DRAFT = 'DRAFT';
    case OPEN = 'OPEN';
    case DECLARED = 'DECLARED';
    case ASSIGNED = 'ASSIGNED';
    case REJECTED = 'REJECTED';
    case SUBMITTED = 'SUBMITTED';
    case VALIDATED = 'VALIDATED';
    case CLOSED = 'CLOSED';
    case IN_PROGRESS = 'IN_PROGRESS';
}