<?php

namespace App\Message;

/**
 * D-181 — dispatched ONCE per manager reassignment operation (MissionReassignmentService),
 * after every mission of the operation was committed, and only when at least one instrumentist
 * really changed. Carries ids and the changed window only (no names, no internal comment):
 * the handler reloads people and missions.
 *
 * $operationId is the operation's correlationId (also inherited through CorrelationStamp):
 * the idempotency of every notification of the operation hangs on it.
 *
 * @phpstan-type Change array{
 *   mode: string, missionId: int, originalMissionId: int,
 *   fromInstrumentistId: int|null, toInstrumentistId: int, surgeonId: int|null,
 *   startAt: string|null, endAt: string|null,
 *   approved?: bool,
 * }
 *
 * D-182 — mode REPLACED (MissionReplacementService): missionId is the existing mission kept,
 * originalMissionId the planned mission cancelled as its duplicate, approved whether the kept
 * mission's declaration was approved by the same operation.
 */
final class MissionReassignmentNotificationMessage
{
    /** @param list<Change> $changes */
    public function __construct(
        public readonly string $operationId,
        public readonly int $actorId,
        public readonly array $changes,
    ) {
    }
}
