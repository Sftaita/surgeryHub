<?php

namespace App\Enum;

/** D-150 — why a MissionAssignment ended. Written once, never changed afterwards. */
enum MissionAssignmentEndReason: string
{
    /** The mission went back to OPEN without instrumentist (release, planning alert « ouvrir au pool »). */
    case RELEASED = 'RELEASED';
    /** Another instrumentist took over the same mission (assign()/reassign()). */
    case REPLACED = 'REPLACED';
    /** The mission was cancelled. */
    case CANCELLED = 'CANCELLED';
    /** D-151 — the mission's surgeon refused this voluntary acceptance within the refusal window. */
    case REFUSED_BY_SURGEON = 'REFUSED_BY_SURGEON';
    /**
     * The declared mission was rejected (DECLARED → REJECTED, final). Mission::$instrumentist
     * is kept as the historical fact; only the affectation ends.
     */
    case REJECTED = 'REJECTED';
    /**
     * Migration backfill only (Version20261014090000): a CLOSED mission still carrying its
     * instrumentist. No application code sets CLOSED today.
     */
    case CLOSED = 'CLOSED';
}
