<?php

namespace App\Dto\Request\Response;

final class FirmServiceOfferingEncodingDto
{
    /**
     * @param MaterialItemSlimDto[] $suggestedMaterials
     */
    public function __construct(
        public readonly int $offeringId,
        public readonly FirmSlimDto $firm,
        public readonly ?string $label,
        public readonly array $suggestedMaterials,
        /**
         * Refonte Catalogue/Prestations (D-092) — le frontend n'affiche la question
         * "délégué présent ?" à l'encodage que si ce champ est vrai pour la firme
         * effectivement choisie. Jamais un montant, jamais une politique de calcul —
         * uniquement "cette question est-elle pertinente ?".
         */
        public readonly bool $representativePresenceRelevant = false,
    ) {}
}
