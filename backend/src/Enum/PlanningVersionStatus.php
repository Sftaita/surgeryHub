<?php

namespace App\Enum;

enum PlanningVersionStatus: string
{
    case DRAFT    = 'DRAFT';
    case ACTIVE   = 'ACTIVE';
    case ARCHIVED = 'ARCHIVED';
}
