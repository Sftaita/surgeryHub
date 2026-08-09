<?php

namespace App\Dto\Request\Response;

final class MaterialItemSlimDto
{
    public function __construct(
        public readonly int $id,
        public readonly ?FirmSlimDto $firm,
        public readonly string $referenceCode,
        public readonly string $label,
        public readonly string $unit,
        public readonly bool $isImplant,
        public readonly bool $active,
        // Lot 6 (D-100) — nullable : `null` = champ volontairement omis pour le chirurgien
        // (jamais de "billing state" visible, voir MissionEncodingService). Le manager et
        // l'instrumentiste reçoivent toujours une vraie valeur, inchangé.
        public readonly ?string $billingStatus = null,
    ) {}
}
