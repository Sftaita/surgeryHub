<?php

namespace App\Dto\Request\Response;

final class MissionEncodingFirmDto
{
    /**
     * @param MissionEncodingMaterialLineDto[] $materialLines
     * @param MissionEncodingMaterialItemRequestDto[] $materialItemRequests
     */
    public function __construct(
        public readonly int $id,
        public readonly string $firmName,
        public readonly array $materialLines,
        public readonly array $materialItemRequests,
    ) {}
}
