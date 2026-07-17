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
}
