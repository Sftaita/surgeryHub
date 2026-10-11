<?php

namespace App\Enum;

/**
 * H4 (D-130) — how a Mission came to be presented to a person as part of her planning.
 * Validated against the real flows (no synonyms):
 *   DEPLOY        — the planning deployment notification (PLANNING_DEPLOYED_INSTRUMENTIST / _SURGEON);
 *   CLAIM         — she took it herself (pool claim, including accepting a nominative request);
 *   ASSIGN        — a manager (or an automatic reassignment / restoration) put her on it and she was told;
 *   DIRECT_ASSIGN — D-125 "attribuer directement" (MISSION_ASSIGNED_DIRECTLY);
 *   MODIFICATION  — the recap of a Planning Modification batch (PLANNING_CHANGE_SUMMARY);
 *   RESEND        — H5a: a manager re-sent her currently published planning (PLANNING_RESENT_MANUAL),
 *                   recorded only once a channel really reached her.
 */
enum MissionCommunicationSource: string
{
    case DEPLOY        = 'DEPLOY';
    case CLAIM         = 'CLAIM';
    case ASSIGN        = 'ASSIGN';
    case DIRECT_ASSIGN = 'DIRECT_ASSIGN';
    case MODIFICATION  = 'MODIFICATION';
    case RESEND        = 'RESEND';
}
