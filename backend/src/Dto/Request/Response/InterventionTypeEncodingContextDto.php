<?php

namespace App\Dto\Request\Response;

/**
 * Lot 6 — réponse riche renvoyée quand l'instrumentiste sélectionne un InterventionType
 * à l'encodage : évite d'enchaîner plusieurs appels (offerings, matériels suggérés,
 * firme principale) pour ouvrir une seule intervention. Toute l'intelligence
 * (résolution de la firme principale suggérée, choix des matériels) reste ici, côté
 * backend — le frontend ne fait qu'afficher/sélectionner.
 */
final class InterventionTypeEncodingContextDto
{
    /**
     * @param FirmServiceOfferingEncodingDto[] $offerings prestations actives pour ce type,
     *        toutes firmes confondues — chacune porte ses propres matériels suggérés
     */
    public function __construct(
        public readonly InterventionTypeSlimDto $interventionType,
        public readonly ?FirmSlimDto $suggestedPrimaryFirm,
        public readonly array $offerings,
    ) {}
}
