<?php

namespace App\Dto\Request\Response;

final class MissionEncodingInterventionDto
{
    /**
     * @param MissionEncodingMaterialLineDto[] $materialLines
     * @param MissionEncodingMaterialItemRequestDto[] $materialItemRequests
     * @param MaterialItemSlimDto[] $suggestedMaterials matériels suggérés par la
     *        prestation (firme principale × type) — vide si `primaryFirm` n'est pas
     *        défini ou si aucune prestation ne couvre ce couple (Lot 6)
     *
     * `code`/`label` restent l'instantané figé à la création (voir MissionIntervention) —
     * inchangés même si `interventionType` est ensuite renommé/désactivé. `interventionType`
     * est null uniquement pour les lignes historiques pré-Lot 5 (legacy, non mappées).
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $label,
        public readonly int $orderIndex,
        public readonly ?InterventionTypeSlimDto $interventionType,
        public readonly ?FirmSlimDto $primaryFirm,
        public readonly array $materialLines,
        public readonly array $materialItemRequests,
        public readonly array $suggestedMaterials,
        public readonly MissionInterventionCoherenceDto $coherence,
    ) {}
}
