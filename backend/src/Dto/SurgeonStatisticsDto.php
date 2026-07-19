<?php

namespace App\Dto;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — GET /api/financial-statistics/by-surgeon.
 * §14 du lot — le chirurgien est un axe ANALYTIQUE, jamais un bénéficiaire financier :
 * aucun champ "paidAmount"/"remainingAmount" ici (contrairement aux firmes et
 * instrumentistes), les paiements des firmes ne lui sont jamais attribués.
 *
 * `surgeonNameSnapshot` — limite documentée (D-077) : FinancialCalculationLine ne
 * porte AUCUN snapshot du chirurgien (contrairement à firm/material/instrumentist —
 * voir FinancialCalculationService::resolveFirmInterventionLine()/etc., aucune de ces
 * méthodes n'écrit de clé "surgeon*" dans $spec['snapshot']). Source réelle :
 * Mission.surgeon (FK vivante, via financialCalculationLine.financialCalculation.mission),
 * jamais reconstruite artificiellement — voir §21 du lot pour la même tolérance
 * appliquée aux libellés d'intervention "si disponibles".
 */
final readonly class SurgeonStatisticsDto
{
    public function __construct(
        public ?int $surgeonId,
        public string $surgeonNameSnapshot,
        public string $currency,
        public int $missionCount,
        public int $executedMissionCount,
        public string $generatedFirmRevenue,
        public string $generatedInstrumentistCompensation,
        public string $averageMissionValue,
    ) {}
}
