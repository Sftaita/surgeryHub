<?php

namespace App\Service;

use App\Dto\Request\Response\FirmSlimDto;
use App\Dto\Request\Response\MaterialItemSlimDto;
use App\Entity\MaterialItem;

final class MaterialItemMapper
{
    /**
     * @param bool $includeBillingStatus Lot 6 (D-100) — false pour un consommateur
     *             chirurgien (MissionEncodingService) : la classification tarifaire d'un
     *             matériel reste un "billing state", jamais montré à un chirurgien.
     *             true partout ailleurs (catalogue manager, recherche instrumentiste) —
     *             comportement strictement inchangé pour ces appelants.
     */
    public function toSlim(MaterialItem $mi, bool $includeBillingStatus = true): MaterialItemSlimDto
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
            active: (bool) $mi->isActive(),
            billingStatus: $includeBillingStatus ? $mi->getBillingStatus()->value : null,
        );
    }
}
