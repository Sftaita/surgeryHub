<?php

namespace App\Dto\Request\Response;

final class SiteSummaryResponse
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }
}