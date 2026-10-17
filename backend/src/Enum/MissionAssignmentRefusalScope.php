<?php

namespace App\Enum;

/**
 * D-151 — reach of a surgeon's refusal. PUNCTUAL: this mission only (the refused
 * instrumentist can never take it back, no lasting incompatibility).
 */
enum MissionAssignmentRefusalScope: string
{
    case PUNCTUAL = 'PUNCTUAL';
}
