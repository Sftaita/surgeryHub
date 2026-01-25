<?php

namespace App\Service;

use App\Dto\Response\MaterialItemSlimDto;
use App\Entity\MaterialItem;

final class MaterialItemMapper
{
    public function toSlim(MaterialItem $mi): MaterialItemSlimDto
    {
        return new MaterialItemSlimDto(
            id: (int) $mi->getId(),
            manufacturer: $mi->getManufacturer(),
            referenceCode: (string) $mi->getReferenceCode(),
            label: (string) $mi->getLabel(),
            unit: (string) $mi->getUnit(),
            isImplant: $mi->isImplant(),
            active: $mi->isActive(),
        );
    }
}
