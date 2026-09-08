<?php

namespace App\Dto\Request\Response;

/** CAS D (D-115) — GET /api/planning/v2/drafts/{id}. Same shape as PreviewResponse plus version metadata and the divergence flag. */
final class DraftReopenResponse
{
    /** @param PreviewLineResponse[] $lines */
    public function __construct(
        public DraftVersionSummaryResponse $version,
        public array $lines,
        public PreviewSummaryResponse $summary,
        public string $previewVersion,
        public bool $divergent,
        public string $generatedAt,
    ) {
    }
}
