<?php

namespace App\Service;

use App\Dto\Request\Response\FirmSlimDto;
use App\Dto\Request\Response\MaterialItemSlimDto;
use App\Entity\MaterialItem;

final class MaterialItemMapper
{
    public function toSlim(MaterialItem $mi): MaterialItemSlimDto
    {
        $firm = $mi->getFirm();
        $firmDto = null;

        if ($firm !== null) {
            $firmDto = new FirmSlimDto(
                id: (int) $firm->getId(),
                name: (string) $firm->getName(),
            );
        }

        return new MaterialItemSlimDto(
            id: (int) $mi->getId(),
            firm: $firmDto,
            referenceCode: (string) $mi->getReferenceCode(),
            label: (string) $mi->getLabel(),
            unit: (string) $mi->getUnit(),
            isImplant: (bool) $mi->isImplant(),
        );
    }
}
