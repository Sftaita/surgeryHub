<?php

namespace App\Dto\Request\Response;

/**
 * Lot 7 (D-070) — agrégation mission-level des signaux de cohérence par intervention
 * (Lot 6). Informationnel uniquement, jamais bloquant : sert au manager pour décider
 * lui-même de valider/refuser, ne conditionne aucune règle serveur.
 */
final class MissionEncodingCoherenceSummaryDto
{
    public function __construct(
        public readonly bool $hasNoInterventions,
        public readonly bool $hasInterventionsWithNoMaterial,
        public readonly bool $hasUnusedSuggestions,
        public readonly bool $hasMaterialFromOtherFirm,
        public readonly bool $hasMissingPrimaryFirm,
    ) {}
}
