<?php

namespace App\Notification;

/** H3 (D-129) — outcome of one evaluated opening episode (also traced as OPEN_POOL_EVALUATED). */
final class OpenPoolResult
{
    public function __construct(
        public readonly OpenEpisode $episode,
        public readonly bool $missionOpen,
        public readonly int $eligibleCount,
        public readonly int $excludedCount,
        public readonly int $notifiedCount,
    ) {
    }

    public function noEligibleCandidates(): bool
    {
        return $this->missionOpen && $this->eligibleCount === 0;
    }
}
