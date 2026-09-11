<?php

namespace App\Exception;

/**
 * Thrown by PlanningDraftService::assertScopeConfirmed() — blocks every draft-mutating
 * action (update(), deploy) on a group-scoped draft whose PlanningVersion::$scopeSource is
 * RECONSTRUCTED (reconstructed after the fact from persisted Missions, never certified
 * complete — see PlanningVersionScopeSource). reopen() (read-only inspection) and delete()
 * are deliberately NOT guarded by this: a manager must be able to see what a legacy draft
 * contains, and to delete it outright, without first confirming a scope they may simply
 * want to discard. Resolved by PlanningDraftService::confirmScope().
 */
final class PlanningDraftScopeConfirmationRequiredException extends \RuntimeException
{
    public function __construct(private readonly int $versionId)
    {
        parent::__construct(
            'Le périmètre de ce brouillon a été reconstruit automatiquement et doit être '
            . 'confirmé par un manager avant toute modification ou déploiement.',
        );
    }

    public function getVersionId(): int
    {
        return $this->versionId;
    }
}
