<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * EPIC Revue instrumentiste, Lot 3, commit 5 — InterventionTypeRequestManagerController::
 * resolve() appelé pour une demande sans MissionInterventionDraft associé. Ne devrait
 * plus arriver pour une demande créée via le workflow actuel (InterventionTypeRequestController::
 * create(), commit 3, crée toujours les deux ensemble) — reste possible pour une ligne
 * historique antérieure à ce lot (même précédent que MissionIntervention::$interventionType
 * nullable pour la ligne legacy mission #529, D-068). Conflict plutôt que 404 : la
 * demande existe bien, c'est son état qui empêche cette action.
 * Mappée à error.code = 'INTERVENTION_TYPE_REQUEST_WITHOUT_DRAFT' (409) par ApiExceptionSubscriber.
 */
class InterventionTypeRequestWithoutDraftException extends ConflictHttpException
{
}
