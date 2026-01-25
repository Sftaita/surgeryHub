<?php

namespace App\Dto\Request\Response;

final class MissionEncodingMaterialItemRequestDto
{
    public function __construct(
        public readonly int $id,
        public readonly string $label,
        public readonly ?string $referenceCode,
        public readonly ?string $comment,
    ) {}
}
