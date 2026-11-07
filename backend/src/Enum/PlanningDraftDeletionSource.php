<?php

namespace App\Enum;

/**
 * H1 (D-126) — why a DRAFT PlanningVersion was physically deleted.
 *
 * Only MANUAL exists today: the only server-side deletion path is
 * DELETE /api/planning/versions/{id} (PlanningDraftService::delete()). A "regeneration" in
 * the editor is a client-side sequence (delete, then generate) of two separate requests —
 * the server cannot tell it apart from a plain deletion, so no REGENERATION value is
 * invented here.
 */
enum PlanningDraftDeletionSource: string
{
    case MANUAL = 'MANUAL';
}
