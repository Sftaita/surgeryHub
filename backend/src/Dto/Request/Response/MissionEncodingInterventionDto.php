<?php

namespace App\Dto\Request\Response;

final class MissionEncodingInterventionDto
{
    /**
     * @param MissionEncodingMaterialLineDto[] $materialLines
     * @param MissionEncodingMaterialItemRequestDto[] $materialItemRequests
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
    ) {}
}
