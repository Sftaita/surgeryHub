<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * EPIC Revue instrumentiste, Lot 3 — MissionInterventionDraftService::createForRequest()
 * appelé pour un InterventionTypeRequest qui a déjà un draft (relation OneToOne unique,
 * un seul par demande). Contrairement à ConflictingAttachmentTargetsException, celle-ci
 * EST atteignable via une requête HTTP légitime (double-soumission, retry réseau) —
 * d'où ConflictHttpException plutôt que LogicException. Mappée à
 * error.code = 'DRAFT_ALREADY_EXISTS' (409) par ApiExceptionSubscriber.
 */
class DraftAlreadyExistsException extends ConflictHttpException
{
}
