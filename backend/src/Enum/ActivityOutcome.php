<?php

namespace App\Enum;

/** H1 (D-126) — result of the operation an ActivityEvent describes. */
enum ActivityOutcome: string
{
    case SUCCESS = 'SUCCESS';
    /** Part of a batch was committed before a later step failed (non-atomic batch). */
    case PARTIAL = 'PARTIAL';
    case FAILED  = 'FAILED';
}
