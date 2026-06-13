<?php

namespace App\Enum;

enum PlanningDeploymentStatus: string
{
    case PENDING    = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case DONE       = 'DONE';
    case FAILED     = 'FAILED';
}
