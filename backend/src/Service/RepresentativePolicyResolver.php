<?php

namespace App\Service;

use App\Dto\RepresentativePolicy;
use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\InterventionType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Refonte Catalogue/Prestations (D-092) — seul point de lecture de la politique
 * "présence d'un délégué" / "forfait attendu" portée par FirmServiceOffering.
 *
 * Exception scopée et documentée à l'invariant D-067 : FinancialCalculationService (via
 * ce service) est le SEUL consommateur autorisé à lire FirmServiceOffering pour ces 4
 * indicateurs non-monétaires. PricingRuleResolver n'importe pas cette classe et n'a
 * aucune dépendance vers FirmServiceOffering ou son repository (voir
 * PricingRuleResolverArchitectureTest). SuggestedMaterial n'est jamais lu ici — ce
 * service ignore totalement la collection de matériels suggérés.
 */
final class RepresentativePolicyResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?ReadScopeCache $readCache = null,
    ) {}

    public function resolve(Firm $firm, InterventionType $interventionType): RepresentativePolicy
    {
        $find = fn () => $this->em->getRepository(FirmServiceOffering::class)->findOneBy([
            'firm' => $firm,
            'interventionType' => $interventionType,
        ]);
        // D-142 — même clé que l'autre lecteur de FirmServiceOffering : une requête par couple.
        $key = $firm->getId() !== null && $interventionType->getId() !== null ? sprintf('firm_service_offering:%d:%d', $firm->getId(), $interventionType->getId()) : null;
        $offering = $this->readCache !== null ? $this->readCache->remember($key, $find) : $find();

        if ($offering === null) {
            return RepresentativePolicy::default();
        }

        return new RepresentativePolicy(
            representativePresenceRelevant: $offering->isRepresentativePresenceRelevant(),
            representativeSuppressesInterventionFee: $offering->isRepresentativeSuppressesInterventionFee(),
            representativeSuppressesOwnMaterialFees: $offering->isRepresentativeSuppressesOwnMaterialFees(),
            feeApplicable: $offering->isFeeApplicable(),
        );
    }
}
