<?php

namespace App\Dto\Request\Response;

final class MissionEncodingMaterialLineDto
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $missionInterventionId,
        public readonly MaterialItemSlimDto $item,
        public readonly string $quantity,
        public readonly ?string $comment,
    ) {}
}
