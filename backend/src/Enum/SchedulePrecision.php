<?php

namespace App\Enum;

enum SchedulePrecision: string
{
    case EXACT = 'EXACT';
    case APPROXIMATE = 'APPROXIMATE';
}
