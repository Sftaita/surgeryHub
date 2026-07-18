<?php

namespace App\Enum;

/**
 * EPIC Exécution & Valorisation, Lot 3 (D-073) — les quatre types réellement produits
 * par ce lot (§7). Volontairement pas un système universel abstrait tant que le besoin
 * réel ne dépasse pas ces quatre cas.
 */
enum FinancialLineType: string
{
    case FIRM_INTERVENTION_FEE = 'FIRM_INTERVENTION_FEE';
    case FIRM_MATERIAL_FEE = 'FIRM_MATERIAL_FEE';
    case INSTRUMENTIST_HOURLY = 'INSTRUMENTIST_HOURLY';
    case INSTRUMENTIST_CONSULTATION_FEE = 'INSTRUMENTIST_CONSULTATION_FEE';
}
