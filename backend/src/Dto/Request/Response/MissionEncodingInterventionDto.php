<?php

namespace App\Dto\Request\Response;

final class MissionEncodingInterventionDto
{
    /**
     * @param MissionEncodingMaterialLineDto[] $materialLines
     * @param MissionEncodingMaterialItemRequestDto[] $materialItemRequests
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $label,
        public readonly int $orderIndex,
        public readonly array $materialLines,
        public readonly array $materialItemRequests,
    ) {}
}
