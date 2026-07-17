<?php

namespace App\Enum;

enum PricingRuleType: string
{
    case INTERVENTION_FEE = 'INTERVENTION_FEE';

    /**
     * Renommé depuis IMPLANT_FEE (Lot 1) : `isImplant` redevient une information
     * purement médicale — la seule source de vérité du caractère facturable d'un
     * matériel est désormais l'existence d'une PricingRule active, quel que soit
     * isImplant. Voir docs/decisions.md.
     */
    case MATERIAL_FEE = 'MATERIAL_FEE';
}
