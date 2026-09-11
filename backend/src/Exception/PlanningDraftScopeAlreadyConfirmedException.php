<?php

namespace App\Exception;

/**
 * Thrown by PlanningDraftService::confirmScope() when called again on a draft whose scope
 * is already PlanningVersionScopeSource::CONFIRMED — a clear, explicit state rather than
 * silently re-accepting (and potentially overwriting) an already-locked-in manager
 * decision. The manager's original confirmed scope is untouched.
 */
final class PlanningDraftScopeAlreadyConfirmedException extends \RuntimeException
{
    public function __construct(private readonly int $versionId)
    {
        parent::__construct('Le périmètre de ce brouillon a déjà été confirmé.');
    }

    public function getVersionId(): int
    {
        return $this->versionId;
    }
}
