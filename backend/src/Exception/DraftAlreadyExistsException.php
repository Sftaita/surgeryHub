<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * EPIC Revue instrumentiste, Lot 3 — MissionInterventionDraftService::createForRequest()
 * appelé pour un InterventionTypeRequest qui a déjà un draft (relation OneToOne unique,
 * un seul par demande). Contrairement à ConflictingAttachmentTargetsException, celle-ci
 * EST atteignable via une requête HTTP légitime (double-soumission, retry réseau) une
 * fois le contrôleur branché — d'où ConflictHttpException plutôt que LogicException.
 * Pas encore enregistrée dans ApiExceptionSubscriber : à faire dans le commit qui
 * branche createForRequest() au contrôleur (ce commit reste isolé du workflow HTTP).
 */
class DraftAlreadyExistsException extends ConflictHttpException
{
}
