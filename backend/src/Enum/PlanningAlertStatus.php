<?php

namespace App\Enum;

enum PlanningAlertStatus: string
{
    case OPEN          = 'OPEN';
    case ACKNOWLEDGED  = 'ACKNOWLEDGED';
    case RESOLVED      = 'RESOLVED';
    case IGNORED       = 'IGNORED';
}
