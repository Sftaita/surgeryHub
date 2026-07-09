<?php

namespace App\Message;

use App\Enum\MissionChangeType;

/**
 * Dispatched after every post-deploy Mission status mutation (R-07).
 *
 * Routing: async (messenger.yaml) — R-08.
 * Handler: MissionLifecycleChangedMessageHandler (skeleton in 15A, logic in 15E).
 *
 * Payload is a snapshot of the names at action time — never resolved from FKs at read time (R-12).
 * No patient or financial data in payload (R-11).
 */
final class MissionLifecycleChangedMessage
{
    public function __construct(
        public readonly int               $missionId,
        public readonly MissionChangeType $changeType,
        public readonly int               $actorId,
        public readonly array             $payload,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
