<?php

namespace App\Dto;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — GET /api/financial-statistics/pipeline
 * (§17 du lot). Chaque compteur a une définition métier disjointe des autres — voir
 * FinancialStatisticsQueryService::pipeline() — aucun élément n'est compté deux fois.
 */
final readonly class FinancialPipelineDto
{
    public function __construct(
        public int $validatedMissionsWithoutCalculation,
        public int $calculationsAwaitingApproval,
        public int $approvedCalculationsWithoutDocuments,
        public int $partiallyDocumentedCalculations,
        public int $generatedInvoicesNotIssued,
        public int $generatedStatementsNotIssued,
        public int $issuedInvoicesWithOpenBalance,
        public int $issuedStatementsWithOpenBalance,
        public int $overpaidDocumentsAwaitingRefund,
    ) {}
}
