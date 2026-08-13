<?php

namespace App\Message;

/**
 * D-110 (J-14) — dispatched once per Mission by CheckUncoveredEscalationsCommand, only
 * after mission.uncoveredEscalationSentAt has actually been persisted (never before —
 * the command's lock+flush happens first, this is dispatched strictly after commit). One
 * message per escalated mission, never batched across missions — each is its own episode.
 *
 * No patient data. Names snapshotted directly (same R-12 convention as
 * MissionLifecycleChangedMessage) — never resolved from a FK at handler read-time.
 */
final class MissionUncoveredEscalationMessage
{
    public function __construct(
        public readonly int $missionId,
        public readonly int $surgeonId,
        public readonly string $surgeonName,
        public readonly ?int $siteId,
        public readonly ?string $siteName,
        public readonly string $startAt,
        public readonly \DateTimeImmutable $occurredAt,
    ) {
    }
}
