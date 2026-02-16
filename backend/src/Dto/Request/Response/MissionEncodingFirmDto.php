<?php

namespace App\Dto\Request\Response;

/**
 * @deprecated Firm is now a reference entity; encoding is no longer grouped by firm.
 * This DTO is kept temporarily to avoid breaking autoload if referenced elsewhere.
 */
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
