<?php

namespace App\Enum;

/**
 * H1 (D-126) — typed reason why a generated occurrence did NOT become a covered DRAFT
 * mission, as recorded in PLANNING_GENERATED. Derived from what the generator already
 * decides (preview line status SKIPPED / UNCOVERED / CONFLICT, surgeon/instrumentist
 * absence, SURGEON_ABSENCE occurrence exceptions) — never a new business rule.
 *
 *   SURGEON_ABSENT       — SKIPPED: the surgeon is absent that day (absence row or
 *                          PlanningOccurrenceException source=SURGEON_ABSENCE).
 *   MANAGER_SKIPPED      — SKIPPED by the manager in the Preview Editor ("Ignorer").
 *   INSTRUMENTIST_ABSENT — UNCOVERED: the post's habitual instrumentist is absent.
 *   CONFLICT             — CONFLICT: the habitual instrumentist is already booked.
 */
enum PlanningOccurrenceOutcomeReason: string
{
    case SURGEON_ABSENT       = 'SURGEON_ABSENT';
    case MANAGER_SKIPPED      = 'MANAGER_SKIPPED';
    case INSTRUMENTIST_ABSENT = 'INSTRUMENTIST_ABSENT';
    case CONFLICT             = 'CONFLICT';
}
