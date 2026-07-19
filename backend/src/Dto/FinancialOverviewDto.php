<?php

namespace App\Dto;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — GET /api/financial-statistics/overview.
 *
 * @param FinancialOverviewCurrencyDto[] $currencies
 */
final readonly class FinancialOverviewDto
{
    /** @param FinancialOverviewCurrencyDto[] $currencies */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public FinancialOverviewActivityDto $activity,
        public array $currencies,
    ) {}
}
