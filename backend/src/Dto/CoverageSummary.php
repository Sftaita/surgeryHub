<?php

namespace App\Dto;

final readonly class CoverageSummary
{
    public ?float $coveragePercent;

    public function __construct(
        public int $versionId,
        public int $total,
        public int $covered,
        public int $open,
        public int $cancelled,
    ) {
        $this->coveragePercent = $total > 0 ? round($covered / $total * 100, 1) : null;
    }
}
