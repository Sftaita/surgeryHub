<?php

namespace App\Enum;

/**
 * D-103 (Lot 3) — provenance of a PlanningOccurrenceException: a manager's deliberate,
 * manual action (the only origin before this lot) vs. an automatic neutralization caused
 * by a surgeon absence recorded before any Mission was generated for that occurrence.
 * Never overwritten once set — see SurgeonAbsenceOccurrenceImpactService, which only ever
 * creates a brand-new row and never mutates an existing exception regardless of its type
 * or source.
 */
enum OccurrenceExceptionSource: string
{
    case MANAGER = 'MANAGER';
    case SURGEON_ABSENCE = 'SURGEON_ABSENCE';
}
