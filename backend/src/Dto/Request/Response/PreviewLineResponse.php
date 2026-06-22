<?php

namespace App\Dto\Request\Response;

/** One occurrence line from PlanningGeneratorServiceV2::preview() — frozen shape per docs/planning-v2-architecture-freeze.md §B. */
final class PreviewLineResponse
{
    public function __construct(
        public string $date,
        public int $postId,
        public int $surgeonId,
        public string $surgeonName,
        public string $missionType,
        public string $startTime,
        public string $endTime,
        public ?int $siteId,
        public ?string $siteName,
        public ?int $instrumentistId,
        public string $instrumentistName,
        public string $status,
        public ?int $existingMissionId,
        public ?int $existingInstrumentistId,
        public ?string $existingInstrumentistName,
        public bool $freedFrom,
    ) {
    }

    /** @param array<string, mixed> $line */
    public static function fromLine(array $line): self
    {
        return new self(
            date: $line['date'],
            postId: $line['postId'],
            surgeonId: $line['surgeonId'],
            surgeonName: $line['surgeonName'],
            missionType: $line['missionType'],
            startTime: $line['startTime'],
            endTime: $line['endTime'],
            siteId: $line['siteId'],
            siteName: $line['siteName'],
            instrumentistId: $line['instrumentistId'],
            instrumentistName: $line['instrumentistName'],
            status: $line['status'],
            existingMissionId: $line['existingMissionId'],
            existingInstrumentistId: $line['existingInstrumentistId'],
            existingInstrumentistName: $line['existingInstrumentistName'],
            freedFrom: $line['freedFrom'] ?? false,
        );
    }
}
