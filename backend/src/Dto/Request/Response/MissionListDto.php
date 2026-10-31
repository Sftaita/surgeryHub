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
        /**
         * D-125 — nominative request state, computed server-side (MissionDispatchService::
         * pendingOffer()/lastDeclinedOffer()), never re-derived client-side:
         * {status: 'PENDING'|'DECLINED', instrumentist: {id, name}, offeredAt, declinedAt,
         * priority, expiresAt, startsBeforeExpiry} (D-177: room take-over priority and its deadline).
         * PENDING = OPEN, not covered, awaiting that instrumentist's answer. Null otherwise.
         *
         * @var array<string,mixed>|null
         */
        public readonly ?array $targetedOffer = null,
        /**
         * D-172 — managers only (null otherwise): encoding state, who finalized / validated it,
         * and its real locks by level (MissionEncodingOverviewService). Never a MissionStatus.
         *
         * @var array<string,mixed>|null
         */
        public readonly ?array $encoding = null,
    ) {}
}