<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lot 5 (D-068) — primaryFirmId fourni à la création/mise à jour d'une
 * MissionIntervention ne correspond à aucune Firm existante.
 * Mappée à error.code = 'PRIMARY_FIRM_NOT_FOUND' par ApiExceptionSubscriber.
 */
class PrimaryFirmNotFoundException extends NotFoundHttpException
{
}
