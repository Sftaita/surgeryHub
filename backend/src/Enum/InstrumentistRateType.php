<?php

namespace App\Enum;

/**
 * EPIC Exécution & Valorisation, Lot 2 (D-072) — les deux seuls types de tarif
 * instrumentiste réellement utilisés par les chemins de production actuels
 * (InstrumentistStatementService::buildPreviewLine() : BLOC → hourlyRate,
 * CONSULTATION → consultationFee). Aucun autre type n'est inventé prématurément.
 */
enum InstrumentistRateType: string
{
    case HOURLY_RATE = 'HOURLY_RATE';
    case CONSULTATION_FEE = 'CONSULTATION_FEE';
}
