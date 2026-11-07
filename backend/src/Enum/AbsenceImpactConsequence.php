<?php

namespace App\Enum;

/**
 * H4 (D-130) — the business consequence an absence impact calls for. Determined by
 * AbsenceImpactResolver; APPLIED by the existing services (never by the resolver).
 */
enum AbsenceImpactConsequence: string
{
    /** N1, surgeon absent: the occurrence is neutralised (PlanningOccurrenceException CANCELLED, D-103). */
    case OCCURRENCE_NEUTRALIZED = 'OCCURRENCE_NEUTRALIZED';
    /** N1, instrumentist absent: the occurrence will need another instrumentist (no mutation; generation never assigns an absent person, D-101). */
    case OCCURRENCE_TO_COVER = 'OCCURRENCE_TO_COVER';
    /** N2, surgeon absent: the DRAFT will be neutralised at deploy (D-090); planning alert for the manager. */
    case DRAFT_TO_NEUTRALIZE = 'DRAFT_TO_NEUTRALIZE';
    /** N2, instrumentist absent: the DRAFT must be re-covered before deploy (D-090 blocks it); planning alert. */
    case DRAFT_TO_COVER = 'DRAFT_TO_COVER';
    /** N3, surgeon absent: OPEN|ASSIGNED → CANCELLED (D-062). */
    case MISSION_CANCELLED = 'MISSION_CANCELLED';
    /** N3, instrumentist absent: ASSIGNED → OPEN, pool solicited by OpenMissionPoolNotifier (D-062, D-129). */
    case MISSION_OPENED = 'MISSION_OPENED';
    /** N3, mission already declared / validated / in progress: never auto-mutated, manager decides (planning alert). */
    case MANAGER_DECISION = 'MANAGER_DECISION';
}
