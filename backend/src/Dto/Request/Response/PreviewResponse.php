<?php

namespace App\Dto\Request\Response;

final class PreviewResponse
{
    /** @param PreviewLineResponse[] $lines */
    public function __construct(
        public array $lines,
        public PreviewSummaryResponse $summary,
        public string $previewVersion,
        public string $generatedAt,
    ) {
    }
}
