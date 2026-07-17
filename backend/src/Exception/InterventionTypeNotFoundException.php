<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lot 5 (D-068) — interventionTypeId fourni à la création/mise à jour d'une
 * MissionIntervention ne correspond à aucun InterventionType existant.
 * Mappée à error.code = 'INTERVENTION_TYPE_NOT_FOUND' par ApiExceptionSubscriber.
 */
class InterventionTypeNotFoundException extends NotFoundHttpException
{
}
