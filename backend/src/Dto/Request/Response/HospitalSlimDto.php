<?php

namespace App\Dto\Request\Response;

final class HospitalSlimDto
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $timezone,
        public readonly ?string $address = null,
        public readonly ?string $photoPath = null,
    ) {}
}
