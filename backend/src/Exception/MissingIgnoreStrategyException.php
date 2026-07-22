<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * EPIC Revue instrumentiste, Lot 3, commit 6 — MissionInterventionDraftService::ignore()
 * refuse d'ignorer silencieusement une InterventionTypeRequest dont le draft porte déjà
 * du matériel (MaterialLine et/ou MaterialItemRequest) sans qu'une stratégie explicite
 * (KEEP_AS_HISTORY ou REASSIGN) n'ait été choisie par le manager — perdre la trace du
 * matériel déjà déclaré par l'instrumentiste serait un effet de bord silencieux.
 * Mappée à error.code = 'MISSING_IGNORE_STRATEGY' (422) par ApiExceptionSubscriber.
 */
class MissingIgnoreStrategyException extends UnprocessableEntityHttpException
{
}
