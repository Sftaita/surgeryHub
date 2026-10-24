<?php

namespace App\Dto;

/**
 * D-165 — one not-yet-saved line of the planning editor, sent with a roster request so the
 * candidates' SCHEDULE_CONFLICT reflects the day as the manager is composing it, not only
 * what is persisted: an edited existing mission ($missionId set — its persisted slot is
 * replaced by this one, or dropped when $cancelled) or a new line ($missionId null).
 * Display-only input: every save re-validates against the database (D-101).
 */
final readonly class ProposedSlot
{
    public function __construct(
        public ?int $missionId,
        public ?int $instrumentistId,
        public \DateTimeImmutable $startAt,
        public \DateTimeImmutable $endAt,
        public ?int $surgeonId = null,
        public ?int $siteId = null,
        public bool $cancelled = false,
    ) {}
}
