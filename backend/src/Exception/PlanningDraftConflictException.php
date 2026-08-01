<?php

namespace App\Exception;

/**
 * Thrown by PlanningDeploymentService::deploy() when the DRAFT PlanningVersion being
 * published contains at least one mission that is no longer valid against the CURRENT
 * state — never assumed still valid just because it was valid when the draft was
 * generated. Two distinct causes share this same blocking mechanism (see each entry's
 * `type`):
 *   - 'ABSENCE' — the assigned instrumentist is now absent (D-090).
 *   - 'CROSS_SITE_CONFLICT' — the surgeon or instrumentist is now double-booked on another
 *     active mission, on this site or another one (D-091).
 *
 * Deliberately blocks the ENTIRE deployment rather than silently excluding only the
 * conflicting missions — a partially-published planning without the manager's explicit
 * intent is exactly the "silent redeploy" failure mode this exists to prevent. The manager
 * must resolve every listed conflict and retry.
 */
final class PlanningDraftConflictException extends \RuntimeException
{
    /**
     * @param list<array{
     *   type: string, missionId: int, date: string, siteId: ?int, siteName: ?string,
     *   surgeonId: ?int, surgeonName: ?string,
     *   instrumentistId: ?int, instrumentistName: ?string, reason: string,
     *   conflictingMissionId?: int, conflictingSiteId?: ?int, conflictingSiteName?: ?string,
     *   conflictingStartAt?: string, conflictingEndAt?: string,
     * }> $conflicts
     */
    public function __construct(private readonly array $conflicts)
    {
        parent::__construct(sprintf(
            'Déploiement bloqué : %d conflit(s) détecté(s) depuis la génération.',
            count($conflicts),
        ));
    }

    /** @return list<array<string, mixed>> */
    public function getConflicts(): array
    {
        return $this->conflicts;
    }
}
