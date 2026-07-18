<?php

namespace App\Dto\Request\Response;

final class MissionExecutionDisputeDto
{
    public function __construct(
        public readonly int $id,
        public readonly string $reasonCode,
        public readonly ?string $comment,
        public readonly string $status,
        public readonly ?string $resolutionComment,
        public readonly string $raisedByDisplayName,
        public readonly string $createdAt,
    ) {}
}
