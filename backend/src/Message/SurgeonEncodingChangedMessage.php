<?php

namespace App\Message;

/**
 * D-154 — the mission's surgeon has just added, changed or removed an intervention or a
 * material line of an encoding that belongs to the assigned instrumentist. Dispatched after
 * each successful write (SurgeonEncodingChangeNotifier); the handler groups a burst of writes
 * into ONE notification (SurgeonEncodingChangedMessageHandler::GROUPING_WINDOW), never one
 * per click or quantity change.
 *
 * Routing: async. Ids only, no encoding content, no amount.
 */
final class SurgeonEncodingChangedMessage
{
    public function __construct(
        public readonly int $missionId,
        public readonly int $surgeonId,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
