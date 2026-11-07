<?php

namespace App\Dto\Request\Response;

final class MissionEncodingMaterialItemRequestDto
{
    public function __construct(
        public readonly int $id,
        public readonly string $label,
        public readonly ?string $referenceCode,
        public readonly ?string $comment,
        /** D-146 bis — firme existante proposée (null : nom libre ou demande antérieure). */
        public readonly ?FirmSlimDto $proposedFirm = null,
        /** D-146 bis — « Ma firme n'est pas dans la liste » : nom libre proposé. */
        public readonly ?string $proposedFirmName = null,
        /** D-146 bis — quantité déclarée (null pour une demande antérieure). */
        public readonly ?string $quantity = null,
    ) {}
}
