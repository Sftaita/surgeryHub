<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Lot 5 (D-068) — l'InterventionType référencé existe mais active=false : un nouvel
 * encodage ne peut pas l'utiliser (un encodage déjà historisé sur ce type, lui, reste
 * inchangé — voir MissionIntervention).
 * Mappée à error.code = 'INTERVENTION_TYPE_INACTIVE' par ApiExceptionSubscriber.
 */
class InterventionTypeInactiveException extends UnprocessableEntityHttpException
{
}
