<?php

namespace App\Dto;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — §18 du lot : forme minimale partagée par les
 * trois endpoints de drill-down (missions/calculations/documents), pour que le manager
 * puisse toujours passer d'un chiffre agrégé à sa liste source. `sourceType` distingue
 * l'origine ("MISSION"/"FINANCIAL_CALCULATION"/"FIRM_INVOICE"/"INSTRUMENTIST_STATEMENT").
 */
final readonly class FinancialStatisticsDrilldownItemDto
{
    public function __construct(
        public int $id,
        public \DateTimeImmutable $date,
        public string $beneficiary,
        public ?string $currency,
        public ?string $amount,
        public string $status,
        public string $sourceType,
        public int $sourceId,
    ) {}
}
