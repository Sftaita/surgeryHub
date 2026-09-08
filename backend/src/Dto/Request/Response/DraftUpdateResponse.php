<?php

namespace App\Dto\Request\Response;

/** CAS D (D-115) — PATCH /api/planning/v2/drafts/{id}. */
final class DraftUpdateResponse
{
    /** @param list<array<string, mixed>> $rejectedAssignments */
    public function __construct(
        public int $created,
        public int $updated,
        public int $removed,
        public int $skipped,
        public array $rejectedAssignments,
    ) {
    }
}
