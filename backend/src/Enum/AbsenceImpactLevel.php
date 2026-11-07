<?php

namespace App\Enum;

/**
 * H4 (D-127 / D-130) — how concrete the planning an absence impacts is. For ONE occurrence,
 * the most concrete state wins: PUBLISHED > DRAFT > USUAL_SCHEDULE. The template never
 * overrides an existing Mission's own snapshot.
 */
enum AbsenceImpactLevel: string
{
    /** N1 — SurgeonSchedulePost (habitual organisation), no Mission exists for the occurrence. */
    case USUAL_SCHEDULE = 'USUAL_SCHEDULE';
    /** N2 — a DRAFT Mission (planning being prepared, never published). */
    case DRAFT = 'DRAFT';
    /** N3 — a Mission of a published planning, or operational through any other valid flow. */
    case PUBLISHED = 'PUBLISHED';
}
