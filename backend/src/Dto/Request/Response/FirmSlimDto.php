<?php

namespace App\Dto\Request\Response;

final class FirmSlimDto
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {}
}
