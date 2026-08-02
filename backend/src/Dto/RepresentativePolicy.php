<?php

namespace App\Dto;

/**
 * Refonte Catalogue/Prestations (D-092) — politique commerciale "présence d'un délégué"
 * pour un couple (Firm, InterventionType), lue depuis FirmServiceOffering. Jamais un
 * montant : uniquement des indicateurs consultés par FinancialCalculationService
 * *après* résolution normale du tarif via PricingRuleResolver, pour décider s'il faut
 * neutraliser un montant déjà résolu. PricingRuleResolver n'a et n'aura jamais
 * connaissance de cette classe (voir D-067, exception scopée documentée).
 */
final readonly class RepresentativePolicy
{
    public function __construct(
        public bool $representativePresenceRelevant,
        public bool $representativeSuppressesInterventionFee,
        public bool $representativeSuppressesOwnMaterialFees,
        public bool $feeApplicable,
    ) {}

    /**
     * Aucune FirmServiceOffering configurée pour ce couple (Firm, InterventionType) :
     * comportement identique à avant ce lot — le forfait est attendu normalement
     * (feeApplicable=true), aucune politique délégué ne s'applique. Une prestation
     * (accélérateur de saisie, D-067) n'est jamais un préalable obligatoire à une
     * PricingRule — cette valeur par défaut préserve cet invariant.
     */
    public static function default(): self
    {
        return new self(
            representativePresenceRelevant: false,
            representativeSuppressesInterventionFee: false,
            representativeSuppressesOwnMaterialFees: false,
            feeApplicable: true,
        );
    }
}
