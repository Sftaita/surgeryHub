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
    ) {}
}
