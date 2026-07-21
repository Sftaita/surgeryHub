<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * EPIC Revue instrumentiste, Lot 3, commit 4 — MaterialAttachmentResolver::resolve()
 * appelé avec missionInterventionId ET interventionDraftId renseignés simultanément.
 * Distincte de ConflictingAttachmentTargetsException (LogicException, invariant interne
 * sur une entité déjà construite) : celle-ci porte sur une ENTRÉE HTTP syntaxiquement
 * valide mais sémantiquement contradictoire — d'où UnprocessableEntityHttpException
 * (422), même famille que InterventionTypeInactiveException/PrimaryFirmInactiveException.
 * Mappée à error.code = 'CONFLICTING_MATERIAL_ATTACHMENT_INPUT' par ApiExceptionSubscriber.
 */
class ConflictingMaterialAttachmentInputException extends UnprocessableEntityHttpException
{
}
