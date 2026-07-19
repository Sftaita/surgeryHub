<?php

namespace App\Dto;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — un bucket de GET /api/financial-statistics/timeseries
 * (§11 du lot). `missionCount` n'est pas dupliqué par devise (une mission n'a pas de
 * devise propre — voir FinancialOverviewActivityDto). Un bucket sans donnée est présent
 * avec des valeurs à zéro (§11), jamais omis.
 *
 * @param FinancialTimeSeriesCurrencyAmountsDto[] $currencies
 */
final readonly class FinancialTimeSeriesPointDto
{
    /** @param FinancialTimeSeriesCurrencyAmountsDto[] $currencies */
    public function __construct(
        public \DateTimeImmutable $periodStart,
        public \DateTimeImmutable $periodEnd,
        public int $missionCount,
        public array $currencies,
    ) {}
}
