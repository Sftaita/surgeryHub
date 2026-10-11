<?php

namespace App\Service;

use App\Dto\RequiredChoicePolicy;
use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\InterventionType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tarification firme conditionnée à un choix obligatoire — seul point de lecture de la
 * configuration RequiredChoiceGroup/ChoiceOption portée par FirmServiceOffering.
 *
 * Exception scopée à l'invariant D-067, même nature que RepresentativePolicyResolver
 * (D-092) : InterventionService (validation d'encodage) et FinancialCalculationService
 * (défense en profondeur avant résolution du tarif) sont les seuls consommateurs
 * autorisés. PricingRuleResolver n'importe pas cette classe ni FirmServiceOffering —
 * il reçoit la ChoiceOption déjà résolue en paramètre, exactement comme InterventionType/
 * MaterialItem (voir PricingRuleResolverArchitectureTest).
 */
final class RequiredChoiceGroupResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?ReadScopeCache $readCache = null,
    ) {}

    public function resolve(Firm $firm, InterventionType $interventionType): RequiredChoicePolicy
    {
        $find = fn () => $this->em->getRepository(FirmServiceOffering::class)->findOneBy([
            'firm' => $firm,
            'interventionType' => $interventionType,
        ]);
        // D-142 — même clé que l'autre lecteur de FirmServiceOffering : une requête par couple.
        $key = $firm->getId() !== null && $interventionType->getId() !== null ? sprintf('firm_service_offering:%d:%d', $firm->getId(), $interventionType->getId()) : null;
        $offering = $this->readCache !== null ? $this->readCache->remember($key, $find) : $find();

        if ($offering === null) {
            return RequiredChoicePolicy::none();
        }

        $group = $offering->getActiveChoiceGroup();
        if ($group === null || !$group->isOperational()) {
            return RequiredChoicePolicy::none();
        }

        $activeOptionIds = [];
        foreach ($group->getOptions() as $option) {
            if ($option->isActive()) {
                $activeOptionIds[] = $option->getId();
            }
        }

        return new RequiredChoicePolicy(
            required: true,
            groupId: $group->getId(),
            question: $group->getQuestion(),
            activeOptionIds: $activeOptionIds,
        );
    }
}
