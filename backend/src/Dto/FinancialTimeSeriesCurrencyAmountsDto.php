<?php

namespace App\Dto;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — bloc monétaire par devise d'un bucket de série temporelle (§11 du lot).
 * D-186 — un solde signé par couche (calculé, documenté, trésorerie), calculé ici, jamais côté client.
 */
final readonly class FinancialTimeSeriesCurrencyAmountsDto
{
    public function __construct(
        public string $currency,
        public string $generatedFirmRevenue,
        public string $generatedInstrumentistCompensation,
        public string $generatedNetBalance,
        public string $invoicedNetAmount,
        public string $statementNetAmount,
        public string $documentedNetBalance,
        public string $paymentsIn,
        public string $paymentsOut,
        public string $netCashFlow,
    ) {}
}
