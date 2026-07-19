<?php

namespace App\Enum;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — granularité autorisée pour
 * GET /api/financial-statistics/timeseries (§11 du lot).
 */
enum StatisticsGranularity: string
{
    case DAY = 'DAY';
    case WEEK = 'WEEK';
    case MONTH = 'MONTH';
}
