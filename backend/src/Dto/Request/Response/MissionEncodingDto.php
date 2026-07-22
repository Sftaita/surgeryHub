<?php

namespace App\Dto\Request\Response;

use App\Dto\Request\Response\MissionEncodingCatalogDto;

final class MissionEncodingDto
{
    /**
     * @param MissionEncodingInterventionDto[] $interventions TRANSITOIRE — conservé pour
     *        compatibilité avec les consommateurs existants (frontend pré-commit 7). Ne
     *        contient QUE les MissionIntervention réelles, jamais les drafts. Préférer
     *        $entries pour tout nouveau consommateur : liste unifiée déjà ordonnée par
     *        orderIndex, incluant les MissionInterventionDraft encore utiles (EPIC Revue
     *        instrumentiste, Lot 3, commit 7). À retirer une fois le frontend basculé sur
     *        $entries (voir commit correspondant, pas encore planifié).
     * @param MissionEncodingEntryDto[] $entries liste unifiée triée par orderIndex —
     *        MissionIntervention (kind=INTERVENTION) + MissionInterventionDraft
     *        (kind=DRAFT) encore pertinents, voir MissionEncodingEntryDto
     * @param MissionEncodingInterventionTypeRequestDto[] $interventionTypeRequests demandes
     *        PENDING (Lot 5) — pas rattachées à une intervention, elle n'existe pas encore
     * @param MissionEncodingCommentDto[] $encodingComments commentaires manager historisés
     *        (reject/reopen, Lot 7) — jamais perdus, jamais écrasés
     *
     * mission = [
     *   'id' => int,
     *   'type' => string,
     *   'status' => string,
     *   'allowedActions' => string[],
     * ]
     */
    public function __construct(
        public readonly array $mission,
        public readonly array $interventions,
        public readonly array $entries,
        public readonly array $interventionTypeRequests,
        public readonly MissionEncodingCatalogDto $catalog,
        public readonly MissionEncodingCoherenceSummaryDto $coherenceSummary,
        public readonly array $encodingComments,
    ) {}
}
