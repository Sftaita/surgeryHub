<?php

namespace App\Enum;

/**
 * Classifies the type of post-deploy mutation carried by MissionLifecycleChangedMessage.
 * Never persisted to the database — used only as a typed discriminator in the message.
 *
 * Batch 15A: enum defined here.
 * Batch 15B: RELEASED, CANCELLED, CLAIMED dispatched by MissionPostDeployService.
 * Batch 15E: RELEASED and CLAIMED handled in MissionLifecycleChangedMessageHandler.
 */
enum MissionChangeType: string
{
    case RELEASED     = 'RELEASED';      // ASSIGNED → OPEN
    case CANCELLED    = 'CANCELLED';     // OPEN → CANCELLED
    case CLAIMED      = 'CLAIMED';       // OPEN → ASSIGNED (instrumentiste)
    case REASSIGNED   = 'REASSIGNED';    // ASSIGNED → ASSIGNED (different instrumentiste)
    case TIME_CHANGED = 'TIME_CHANGED';  // start/end time modification
    case ADDED        = 'ADDED';         // new Mission created post-deploy
    case REMOVED      = 'REMOVED';       // Mission deleted post-deploy (future)
    case UPDATED      = 'UPDATED';       // generic update (fallback)
    case STARTED      = 'STARTED';       // ASSIGNED → IN_PROGRESS (D-064, automated on startAt)

    // Lot 7 (D-070) — encoding workflow. All currently hit the handler's documented
    // "unhandled changeType — forward-compatible skip" default branch: dispatched to
    // prepare the event, no notification wired yet (deliberate — see D-070).
    case ENCODING_STARTED   = 'ENCODING_STARTED';    // IN_PROGRESS/ASSIGNED → ENCODING_IN_PROGRESS
    case ENCODING_COMPLETED = 'ENCODING_COMPLETED';  // ... → SUBMITTED
    case ENCODING_VALIDATED = 'ENCODING_VALIDATED';  // SUBMITTED → VALIDATED
    case ENCODING_REJECTED  = 'ENCODING_REJECTED';   // SUBMITTED → ENCODING_IN_PROGRESS (comment required)
    case ENCODING_REOPENED  = 'ENCODING_REOPENED';   // VALIDATED → ENCODING_IN_PROGRESS (comment optional)
}
