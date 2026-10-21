<?php

namespace App\Message;

/**
 * D-161 — asks ReleasedRoomAvailabilityNotifier, asynchronously and after the slots were
 * written, to announce the rooms one absence processing has really just created. The
 * technical creation (ReleasedOperatingRoomSlotService) and the announcement stay two
 * distinct operations: only AbsenceImpactCoordinator dispatches this message, the backfill
 * command (AvailableRoomsBackfillCommand) never does.
 */
final class ReleasedRoomSlotsCreatedMessage
{
    /** @param list<int> $slotIds */
    public function __construct(
        public readonly array $slotIds,
        public readonly ?int $absenceId = null,
    ) {
    }
}
