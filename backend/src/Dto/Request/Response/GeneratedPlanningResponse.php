<?php

namespace App\Dto\Request\Response;

/** Mirrors PlanningGeneratorServiceV2::generate()'s return shape exactly (same as V1's generate() response). */
final class GeneratedPlanningResponse
{
    /**
     * @param array<int,array{missionId:int|null,date:string|null,requestedInstrumentistId:int,
     *   requestedInstrumentistName:string,reasons:list<string>}> $rejectedAssignments
     *   D-101 — instrumentist assignments the backend refused to keep (ABSENT/SCHEDULE_CONFLICT/
     *   INACTIVE/NO_SITE_MEMBERSHIP) while applying the client-supplied lines; those lines were
     *   generated without an instrumentist (UNCOVERED) instead of failing the whole request.
     *   Never silent — the frontend must surface this list to the manager.
     */
    public function __construct(
        public int $versionId,
        public int $created,
        public int $updated,
        public int $skipped,
        public array $rejectedAssignments = [],
    ) {
    }
}
