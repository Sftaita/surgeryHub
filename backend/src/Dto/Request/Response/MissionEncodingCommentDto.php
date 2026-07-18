<?php

namespace App\Dto\Request\Response;

final class MissionEncodingCommentDto
{
    public function __construct(
        public readonly int $id,
        public readonly string $comment,
        public readonly string $authorDisplayName,
        public readonly string $createdAt,
    ) {}
}
