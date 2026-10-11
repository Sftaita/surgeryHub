<?php

namespace App\Enum;

/** H4 (D-130) — why a person no longer belongs to a Mission that had been communicated to her. */
enum MissionCommunicationWithdrawal: string
{
    case CANCELLED  = 'CANCELLED';
    case RELEASED   = 'RELEASED';
    case REASSIGNED = 'REASSIGNED';
}
