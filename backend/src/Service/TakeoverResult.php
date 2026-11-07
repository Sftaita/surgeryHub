<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\User;

/**
 * D-177 — outcome of ReleasedRoomSlotTakeoverService::takeOverInTransaction(), handed to
 * afterTakeOver() once the caller's transaction committed.
 */
final class TakeoverResult
{
    /** @param array<string, mixed> $payload the ROOM_SLOT_TAKEN_OVER audit payload */
    public function __construct(
        public readonly ReleasedOperatingRoomSlot $slot,
        public readonly Mission $mission,
        public readonly TakeoverPublication $publication,
        public readonly array $payload,
        public readonly User $actor,
    ) {
    }
}
