<?php

namespace App\Dto;

/**
 * Tarification firme conditionnée à un choix obligatoire — pour un couple
 * (Firm, InterventionType), lue depuis FirmServiceOffering/RequiredChoiceGroup. Jamais
 * un montant : uniquement de quoi valider qu'une réponse a été fournie et qu'elle est
 * une des options actives du groupe. PricingRuleResolver n'a et n'aura jamais
 * connaissance de cette classe (même exception scopée que RepresentativePolicy, D-067/
 * D-092).
 */
final readonly class RequiredChoicePolicy
{
    /** @param int[] $activeOptionIds */
    public function __construct(
        public bool $required,
        public ?int $groupId,
        public ?string $question,
        public array $activeOptionIds,
    ) {}

    /**
     * Aucun groupe opérationnel (pas de FirmServiceOffering, pas de groupe actif, ou
     * groupe actif avec moins de 2 options actives — un choix à une seule option n'en
     * est pas un, voir RequiredChoiceGroup::isOperational()) : comportement identique à
     * une prestation standard, aucune question, aucune contrainte.
     */
    public static function none(): self
    {
        return new self(required: false, groupId: null, question: null, activeOptionIds: []);
    }
}
