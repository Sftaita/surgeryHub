<?php

namespace App\Exception;

/**
 * CAS D (D-115) — thrown by PlanningV2GenerationController::assertNoUndeployedDraftExists()
 * instead of a bare ConflictHttpException, so the 409 body carries the existing draft's id —
 * the frontend can offer "Ouvrir le brouillon" directly from the error instead of a dead-end
 * message. This is a fallback path only: the primary UX proactively shows "Brouillon
 * existant" before the manager ever reaches generate() again (GET /api/planning/versions
 * ?status=DRAFT). Never auto-deletes or replaces the existing draft — see docs/decisions.md
 * D-115.
 */
final class PlanningDraftAlreadyExistsException extends \RuntimeException
{
    public function __construct(private readonly int $existingVersionId)
    {
        parent::__construct(sprintf(
            'Un brouillon (version #%d) existe déjà pour cette période — ouvrez-le ou supprimez-le avant de régénérer.',
            $existingVersionId,
        ));
    }

    public function getExistingVersionId(): int
    {
        return $this->existingVersionId;
    }
}
