<?php

namespace App\Exception;

/**
 * Thrown by PlanningDeploymentService::deploy() when the DRAFT PlanningVersion being
 * published contains at least one mission whose assigned instrumentist is now absent —
 * detected by a fresh revalidation against CURRENT absence data at deploy time, never
 * assumed still valid just because it was valid when the draft was generated (D-090).
 *
 * Deliberately blocks the ENTIRE deployment rather than silently excluding only the
 * conflicting missions — a partially-published planning without the manager's explicit
 * intent is exactly the "silent redeploy" failure mode this exists to prevent. The manager
 * must resolve every listed conflict (reassign or remove the instrumentist) and retry.
 */
final class PlanningDraftConflictException extends \RuntimeException
{
    /**
     * @param list<array{
     *   missionId: int, date: string, siteId: ?int, siteName: ?string,
     *   surgeonId: ?int, surgeonName: ?string,
     *   instrumentistId: int, instrumentistName: string, reason: string,
     * }> $conflicts
     */
    public function __construct(private readonly array $conflicts)
    {
        parent::__construct(sprintf(
            'Déploiement bloqué : %d affectation(s) invalidée(s) par une absence détectée depuis la génération.',
            count($conflicts),
        ));
    }

    /** @return list<array<string, mixed>> */
    public function getConflicts(): array
    {
        return $this->conflicts;
    }
}
