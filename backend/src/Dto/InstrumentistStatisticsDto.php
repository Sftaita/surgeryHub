<?php

namespace App\Dto;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — GET /api/financial-statistics/by-instrumentist.
 * `instrumentistNameSnapshot` provient de FinancialCalculationLine.snapshot (§13 du
 * lot), jamais du User actuel — un renommage/désactivation ne modifie jamais
 * l'historique.
 */
final readonly class InstrumentistStatisticsDto
{
    public function __construct(
        public ?int $instrumentistId,
        public string $instrumentistNameSnapshot,
        public string $currency,
        public int $missionCount,
        public int $executedMinutes,
        public string $hourlyCompensation,
        public string $consultationFees,
        public string $generatedCompensation,
        public string $statementNetAmount,
        public string $paidAmount,
        public string $remainingAmount,
        public string $averageCompensationPerMission,
    ) {}
}
