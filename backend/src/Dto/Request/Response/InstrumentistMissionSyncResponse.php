<?php

namespace App\Dto\Request\Response;

final class InstrumentistMissionSyncResponse
{
    /**
     * @param MissionListDto[] $missions
     * @param int[]            $removedMissionIds
     */
    public function __construct(
        public readonly string $serverTime,
        public readonly bool $changed,
        public readonly array $missions,
        public readonly array $removedMissionIds,
    ) {}
}
