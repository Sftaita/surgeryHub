<?php

namespace App\Dto\Request\Response;

/**
 * Lot 6 — signaux de cohérence, informationnels uniquement (jamais bloquants). Préparent
 * les futurs contrôles qualité manager ; l'UX des avertissements n'est pas de ce lot.
 */
final class MissionInterventionCoherenceDto
{
    /**
     * @param int[] $unusedSuggestedMaterialItemIds matériels suggérés (par l'offering
     *        firme principale × type) pas encore ajoutés à cette intervention
     * @param int[] $unexpectedMaterialItemIds matériels déjà ajoutés qui ne figurent pas
     *        dans la liste suggérée (jamais bloquant — un instrumentiste peut toujours
     *        s'écarter de la suggestion)
     * @param int[] $materialLineIdsFromOtherFirm lignes matériel dont la firme diffère de
     *        la firme principale de l'intervention
     */
    public function __construct(
        public readonly bool $hasNoMaterialLines,
        public readonly array $unusedSuggestedMaterialItemIds,
        public readonly array $unexpectedMaterialItemIds,
        public readonly array $materialLineIdsFromOtherFirm,
    ) {}
}
