<?php

namespace App\Dto\Request\Response;

final class MissionEncodingInterventionDto
{
    /**
     * @param MissionEncodingFirmDto[] $firms
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $label,
        public readonly int $orderIndex,
        public readonly array $firms,
    ) {}
}
