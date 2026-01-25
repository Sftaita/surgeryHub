<?php

namespace App\Dto\Request\Response;

final class MissionEncodingDto
{
    /**
     * @param MissionEncodingInterventionDto[] $interventions
     */
    public function __construct(
        public readonly int $missionId,
        public readonly string $missionType,
        public readonly string $missionStatus,
        public readonly array $interventions,
    ) {}
}
