<?php

namespace App\Dto\Request\Response;

final class MissionDetailDto
{
    /**
     * @param string[] $allowedActions
     */
    public function __construct(
        public readonly int $id,
        public readonly HospitalSlimDto $site,
        public readonly ?string $startAt,
        public readonly ?string $endAt,
        public readonly string $schedulePrecision,
        public readonly string $type,
        public readonly string $status,
        public readonly UserSlimDto $surgeon,
        public readonly ?UserSlimDto $instrumentist,
        public readonly array $allowedActions,
        public readonly ?string $noMaterialComment,
        public readonly ?bool $submittedWithoutMaterial,
        /** Socle chirurgien (Lot 2, D-095) — voir MissionListDto::$covered. */
        public readonly bool $covered,
        /**
         * D-120 — infos de relance d'encodage (cockpit Suivi des encodages), lues via
         * EncodingReminderService, jamais recalculées côté frontend.
         */
        public readonly ?string $automaticReminderSentAt,
        public readonly ?string $nextAutomaticReminderAt,
        public readonly ?string $lastManualReminderAt,
        public readonly ?string $lastManualReminderByName,
    ) {}
}