<?php

namespace App\Dto\Request\Response;

use App\Dto\Request\Response\MissionEncodingCatalogDto;

final class MissionEncodingDto
{
    /**
     * @param MissionEncodingInterventionDto[] $interventions
     * @param MissionEncodingInterventionTypeRequestDto[] $interventionTypeRequests demandes
     *        PENDING (Lot 5) — pas rattachées à une intervention, elle n'existe pas encore
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
        public readonly array $interventionTypeRequests,
        public readonly MissionEncodingCatalogDto $catalog,
    ) {}
}
