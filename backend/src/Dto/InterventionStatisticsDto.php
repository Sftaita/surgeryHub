<?php

namespace App\Dto;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — GET /api/financial-statistics/by-intervention.
 * `interventionCodeSnapshot`/`interventionNameSnapshot` proviennent de
 * FinancialCalculationLine.snapshot (interventionCodeSnapshot/interventionLabelSnapshot,
 * §15 du lot) — jamais du InterventionType actuel.
 *
 * D-186 — recettes uniquement : `totalRevenue` = interventionRevenue + materialRevenue ;
 * `interventionCount` = interventions valorisées de ce type ; `averageRevenuePerIntervention`
 * = totalRevenue / interventionCount. La rémunération instrumentiste appartient à la mission,
 * jamais à une intervention : seul `averageAssociatedMissionCost` (coût instrumentiste total
 * moyen des missions comportant ce type, indicateur contextuel, null si aucune) est exposé.
 * interventionTypeId=null sans intervention = matériel non rattaché à une intervention.
 */
final readonly class InterventionStatisticsDto
{
    public function __construct(
        public ?int $interventionTypeId,
        public string $interventionCodeSnapshot,
        public string $interventionNameSnapshot,
        public string $currency,
        public int $missionCount,
        public int $interventionCount,
        public string $interventionRevenue,
        public string $materialRevenue,
        public string $totalRevenue,
        public string $averageRevenuePerIntervention,
        public ?string $averageAssociatedMissionCost,
        public int $averageDurationMinutes,
    ) {}
}
