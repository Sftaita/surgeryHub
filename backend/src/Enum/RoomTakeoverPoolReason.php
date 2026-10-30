<?php

namespace App\Enum;

/**
 * D-177 — why the mission of a room take-over is (now) open to the eligible pool. Recorded
 * on MISSION_PUBLISHED_TO_POOL (`poolOpenedReason`) and, for the first four, on
 * ROOM_TAKEOVER_PRIORITY_BYPASSED (`reason`): the manager always knows why nobody had a
 * priority, or why it ended.
 */
enum RoomTakeoverPoolReason: string
{
    // Priority never started (Cas B).
    case NO_USUAL_INSTRUMENTIST = 'NO_USUAL_INSTRUMENTIST';
    case USUAL_INSTRUMENTIST_ABSENT = 'USUAL_INSTRUMENTIST_ABSENT';
    case USUAL_INSTRUMENTIST_INELIGIBLE = 'USUAL_INSTRUMENTIST_INELIGIBLE';

    // Priority ended (Cas A / C / D).
    case PRIORITY_DECLINED = 'PRIORITY_DECLINED';
    case PRIORITY_EXPIRED = 'PRIORITY_EXPIRED';
    case RECIPIENT_UNAVAILABLE = 'RECIPIENT_UNAVAILABLE';
    case LIFTED_BY_MANAGER = 'LIFTED_BY_MANAGER';
}
