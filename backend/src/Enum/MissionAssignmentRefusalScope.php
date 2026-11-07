<?php

namespace App\Enum;

/**
 * D-151 — reach of a surgeon's refusal. PUNCTUAL: this mission only (the refused
 * instrumentist can never take it back, no lasting incompatibility). DURABLE (D-152): the
 * same, plus an active surgeon–instrumentist incompatibility for all his future missions.
 */
enum MissionAssignmentRefusalScope: string
{
    case PUNCTUAL = 'PUNCTUAL';
    case DURABLE  = 'DURABLE';
}
