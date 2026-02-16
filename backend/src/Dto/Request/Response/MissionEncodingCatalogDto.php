<?php

namespace App\Dto\Request\Response;

final class MissionEncodingCatalogDto
{
    /**
     * @param MaterialItemSlimDto[] $items
     * @param FirmSlimDto[] $firms
     */
    public function __construct(
        public readonly array $items,
        public readonly array $firms,
    ) {}
}
