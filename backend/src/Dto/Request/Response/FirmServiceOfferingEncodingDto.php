<?php

namespace App\Dto\Request\Response;

final class FirmServiceOfferingEncodingDto
{
    /**
     * @param MaterialItemSlimDto[] $suggestedMaterials
     */
    public function __construct(
        public readonly int $offeringId,
        public readonly FirmSlimDto $firm,
        public readonly ?string $label,
        public readonly array $suggestedMaterials,
    ) {}
}
