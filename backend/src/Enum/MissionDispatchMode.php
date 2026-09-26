<?php

namespace App\Enum;

/**
 * D-125 — how a manager puts a Mission into play, whatever its origin (manual creation,
 * accepted SurgeonMissionRequest, addition after the month was generated). Never
 * persisted: POOL/TARGETED materialize as a MissionPublication (OPEN), DIRECT as the
 * ASSIGNED status itself. See MissionDispatchService.
 */
enum MissionDispatchMode: string
{
    /** OPEN — every eligible instrumentist may claim it. */
    case POOL = 'POOL';

    /** OPEN + TARGETED publication — one instrumentist is asked, must accept (claim) or decline. */
    case TARGETED = 'TARGETED';

    /** ASSIGNED immediately — agreement already obtained outside SurgicalHub, no acceptance step. */
    case DIRECT = 'DIRECT';
}
