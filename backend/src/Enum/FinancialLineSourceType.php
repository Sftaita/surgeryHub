<?php

namespace App\Enum;

/** EPIC Exécution & Valorisation, Lot 3 (D-073) — quel enregistrement métier a produit la ligne (traçabilité), distinct de FinancialLineType (nature financière). */
enum FinancialLineSourceType: string
{
    case MISSION_INTERVENTION = 'MISSION_INTERVENTION';
    case MATERIAL_LINE = 'MATERIAL_LINE';
    case MISSION_EXECUTION = 'MISSION_EXECUTION';
}
