<?php

namespace App\Dto;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — GET /api/financial-statistics/by-intervention.
 * `interventionCodeSnapshot`/`interventionNameSnapshot` proviennent de
 * FinancialCalculationLine.snapshot (interventionCodeSnapshot/interventionLabelSnapshot,
 * §15 du lot) — jamais du InterventionType actuel.
 */
final readonly class InterventionStatisticsDto
{
    public function __construct(
        public ?int $interventionTypeId,
        public string $interventionCodeSnapshot,
        public string $interventionNameSnapshot,
        public string $currency,
        public int $missionCount,
        public string $interventionRevenue,
        public string $materialRevenue,
        public string $instrumentistCompensation,
        public string $averageMissionValue,
        public int $averageDurationMinutes,
    ) {}
}
