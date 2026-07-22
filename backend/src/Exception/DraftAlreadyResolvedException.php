<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * EPIC Revue instrumentiste, Lot 3, commit 5 — MissionInterventionDraftService::resolve()
 * relit le draft (sous verrou pessimiste, après refresh()) et constate qu'il n'est plus
 * OPEN, ou que la InterventionTypeRequest liée n'est plus PENDING. Couvre uniformément :
 * une seconde tentative de résolution, une tentative concurrente qui perd la course sous
 * le verrou, et tout état terminal (CONVERTED/MATERIAL_REASSIGNED/KEPT_AS_HISTORY) —
 * un seul type d'exception pour un seul fait métier ("cette résolution n'est plus
 * possible"), jamais une seconde MissionIntervention créée.
 * Mappée à error.code = 'DRAFT_ALREADY_RESOLVED' (409) par ApiExceptionSubscriber.
 */
class DraftAlreadyResolvedException extends ConflictHttpException
{
}
