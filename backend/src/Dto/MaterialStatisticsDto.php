<?php

namespace App\Dto;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — GET /api/financial-statistics/top-materials
 * (§16 du lot). `materialNameSnapshot`/`firmSnapshot` proviennent de
 * FinancialCalculationLine.snapshot (materialNameSnapshot/materialFirmSnapshot) — jamais
 * du MaterialItem/Firm actuel.
 *
 * `materialReferenceSnapshot` — limite documentée (D-077) : FinancialCalculationLine.snapshot
 * ne porte PAS le code référence (seuls materialItemId/materialNameSnapshot/
 * materialFirmSnapshot y figurent). Lu en direct via la FK de navigation
 * materialLine.item.referenceCode (jamais une donnée tarifaire — un simple code
 * d'identification), jamais reconstruit. Le regroupement lui-même utilise
 * `materialItemId` (identité stable), jamais le libellé, pour §16 : "distinguer deux
 * références différentes même si les noms se ressemblent".
 */
final readonly class MaterialStatisticsDto
{
    public function __construct(
        public ?int $materialId,
        public ?string $materialReferenceSnapshot,
        public string $materialNameSnapshot,
        public string $firmSnapshot,
        public string $currency,
        public string $quantity,
        public int $missionCount,
        public string $generatedRevenue,
        public string $averageUnitRevenue,
    ) {}
}
