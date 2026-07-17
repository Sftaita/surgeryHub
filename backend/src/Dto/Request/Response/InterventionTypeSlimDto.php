<?php

namespace App\Dto\Request\Response;

final class InterventionTypeSlimDto
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $label,
    ) {}
}
