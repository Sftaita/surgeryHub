<?php

namespace App\Message;

/**
 * D-177 — dispatched with a DelayStamp when a room take-over priority is issued, delivered at
 * its deadline (the Doctrine transport keeps it across restarts). The handler re-checks
 * everything under lock (RoomTakeoverPriorityService::expire()); the sweep command
 * `app:room-takeover:expire-priorities` is the backstop if this message is lost or failed.
 */
final class RoomTakeoverPriorityExpiryMessage
{
    public function __construct(
        public readonly int $publicationId,
    ) {
    }
}
