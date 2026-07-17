<?php

namespace App\Dto\Request\Response;

final class MissionEncodingInterventionTypeRequestDto
{
    public function __construct(
        public readonly int $id,
        public readonly string $label,
        public readonly ?string $suggestedCode,
        public readonly ?string $comment,
    ) {}
}
