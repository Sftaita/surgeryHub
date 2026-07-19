<?php

namespace App\Enum;

/**
 * EPIC Exécution & Valorisation, Lot 6 (D-076) — §8 du lot : taxonomie limitée aux
 * besoins réels, pas de nomenclature comptable démesurée. OTHER exige un commentaire
 * (validé par FinancialCorrectionService, jamais au niveau DB).
 */
enum CorrectionReasonCode: string
{
    case WRONG_QUANTITY = 'WRONG_QUANTITY';
    case WRONG_RATE = 'WRONG_RATE';
    case WRONG_DURATION = 'WRONG_DURATION';
    case DUPLICATE_LINE = 'DUPLICATE_LINE';
    case OMITTED_LINE = 'OMITTED_LINE';
    case WRONG_BENEFICIARY = 'WRONG_BENEFICIARY';
    case COMMERCIAL_ADJUSTMENT = 'COMMERCIAL_ADJUSTMENT';
    case OTHER = 'OTHER';
}
