<?php

namespace App\Dto\Request\Response;

final class MissionEncodingCatalogDto
{
    /**
     * @param MaterialItemSlimDto[] $items
     * @param FirmSlimDto[] $firms
     * @param InterventionTypeSlimDto[] $interventionTypes actifs uniquement (Lot 5)
     */
    public function __construct(
        public readonly array $items,
        public readonly array $firms,
        public readonly array $interventionTypes,
    ) {}
}
