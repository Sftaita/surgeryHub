<?php

namespace App\Dto\Request\Response;

final class MissionListDto
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
        /**
         * Socle chirurgien (Lot 2, D-095) — même règle que PlanningCoverageService::isCovered(),
         * source unique. Le frontend ne doit jamais recalculer un second set de statuts
         * "couvert"/"à couvrir" localement.
         */
        public readonly bool $covered,
    ) {}
}