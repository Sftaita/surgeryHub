<?php

namespace App\Dto\Request\Response;

/** Computed server-side per docs/planning-v2-architecture-freeze.md §B so the frontend never has to reduce the lines array itself. */
final class PreviewSummaryResponse
{
    public function __construct(
        public int $total,
        public int $covered,
        public int $uncovered,
        public int $skipped,
        public int $conflict,
        public int $modified,
    ) {
    }

    /** @param array<int, array{status: string}> $lines */
    public static function fromLines(array $lines): self
    {
        $counts = array_count_values(array_column($lines, 'status'));

        return new self(
            total: count($lines),
            covered: $counts['COVERED'] ?? 0,
            uncovered: $counts['UNCOVERED'] ?? 0,
            skipped: $counts['SKIPPED'] ?? 0,
            conflict: $counts['CONFLICT'] ?? 0,
            modified: $counts['MODIFIED'] ?? 0,
        );
    }
}
