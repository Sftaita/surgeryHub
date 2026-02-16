<?php

namespace App\Dto\Request\Response;

use App\Dto\Request\Response\MissionEncodingCatalogDto;

final class MissionEncodingDto
{
    /**
     * @param MissionEncodingInterventionDto[] $interventions
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
        public readonly MissionEncodingCatalogDto $catalog,
    ) {}
}
