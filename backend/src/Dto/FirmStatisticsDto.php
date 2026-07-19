<?php

namespace App\Dto;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — GET /api/financial-statistics/by-firm.
 * `firmNameSnapshot` provient de FinancialCalculationLine.snapshot (§12 du lot) —
 * jamais du nom actuel de la firme, une firme renommée/supprimée ne modifie jamais
 * l'historique. `currency` explicite : une même firme apparaît sur plusieurs lignes si
 * son activité couvre plusieurs devises (§5 du lot — jamais de total mélangé).
 */
final readonly class FirmStatisticsDto
{
    public function __construct(
        public ?int $firmId,
        public string $firmNameSnapshot,
        public string $currency,
        public int $missionCount,
        public string $interventionRevenue,
        public string $materialRevenue,
        public string $generatedRevenue,
        public string $invoicedNetAmount,
        public string $paidAmount,
        public string $remainingAmount,
        public string $averageRevenuePerMission,
    ) {}
}
