<?php

namespace App\Dto\Request\Response;

/** Mirrors PlanningGeneratorServiceV2::generate()'s return shape exactly (same as V1's generate() response). */
final class GeneratedPlanningResponse
{
    public function __construct(
        public int $versionId,
        public int $created,
        public int $updated,
        public int $skipped,
    ) {
    }
}
