<?php

namespace App\Dto\Response;

final class HospitalSlimDto
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $timezone,
    ) {}
}
