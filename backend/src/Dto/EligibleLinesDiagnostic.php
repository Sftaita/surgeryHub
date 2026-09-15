<?php

namespace App\Dto;

/**
 * Diagnostic explicatif renvoyé par `previewEligibleLines()` (FirmInvoiceService /
 * InstrumentistStatementService) uniquement quand `lines` est vide — jamais recalculé
 * depuis les tarifs, uniquement dérivé de données déjà persistées (missions, calculs,
 * lignes, historique d'audit des échecs de calcul). Codes stables, jamais un message
 * libre à parser côté frontend (même principe que `FinancialCalculationAnomaly`).
 */
final readonly class EligibleLinesDiagnostic
{
    /** @param string[] $reasons sous-ensemble ordonné de codes stables, jamais vide */
    public function __construct(
        public int $validatedMissionCount,
        public int $calculationCount,
        public int $calculatedCount,
        public int $approvedCount,
        public int $lockedCount,
        public int $missingPricingCount,
        public int $currencyMismatchCount,
        public int $alreadyInvoicedCount,
        public array $reasons,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => 'NO_ELIGIBLE_LINES',
            'validatedMissionCount' => $this->validatedMissionCount,
            'calculationCount' => $this->calculationCount,
            'calculatedCount' => $this->calculatedCount,
            'approvedCount' => $this->approvedCount,
            'lockedCount' => $this->lockedCount,
            'missingPricingCount' => $this->missingPricingCount,
            'currencyMismatchCount' => $this->currencyMismatchCount,
            'alreadyInvoicedCount' => $this->alreadyInvoicedCount,
            'reasons' => $this->reasons,
        ];
    }
}
