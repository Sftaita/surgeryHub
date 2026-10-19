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
        public ?string $surgeonPhotoPath = null,
        public ?string $instrumentistPhotoPath = null,
        /**
         * D-125 — status of the Mission this line mirrors (existingMissionId), if any.
         * Anything but DRAFT is never modified by generate() (R-01): the editor shows such a
         * line as the existing reality and locks it. Additive field, null otherwise.
         */
        public ?string $existingMissionStatus = null,
        /**
         * D-156 — informative only: size of the multi-room prestation this line forms with
         * other simultaneous lines of the same instrumentist (same surgeon, site, day, BLOC),
         * null when alone. Never a validation step; additive field.
         */
        public ?int $multiRoomCount = null,
        /** D-156 — key of that group (composition + times); null when alone. */
        public ?string $multiRoomGroupKey = null,
        /**
         * D-156 — 'REQUIRED': assignment proposed by the posts, to confirm or reorganise
         * before deploy; 'CONFIRMED': confirmed in this draft; null when not multi-room.
         */
        public ?string $multiRoomConfirmation = null,
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
            surgeonPhotoPath: $line['surgeonPhotoPath'] ?? null,
            instrumentistPhotoPath: $line['instrumentistPhotoPath'] ?? null,
            existingMissionStatus: $line['existingMissionStatus'] ?? null,
            multiRoomCount: $line['multiRoomCount'] ?? null,
            multiRoomGroupKey: $line['multiRoomGroupKey'] ?? null,
            multiRoomConfirmation: $line['multiRoomConfirmation'] ?? null,
        );
    }
}
