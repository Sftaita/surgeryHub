<?php

namespace App\Enum;

/**
 * D-150 — business origin of a MissionAssignment, always passed explicitly by the
 * application service that performs the affectation (never inferred afterwards).
 *
 * Only POOL_CLAIM and OFFER_ACCEPTED are affectations the instrumentist accepted of her own
 * accord (pool, or a nominative offer) — see isVoluntaryAcceptance().
 */
enum MissionAssignmentSource: string
{
    /** Instrumentist took an OPEN mission from the pool (MissionPostDeployService::claim()). */
    case POOL_CLAIM = 'POOL_CLAIM';
    /** Instrumentist accepted a nominative (TARGETED) offer — same claim() path (D-125). */
    case OFFER_ACCEPTED = 'OFFER_ACCEPTED';
    /** Manager « Attribuer directement » (D-125, assignDirectly()). */
    case DIRECT_ASSIGNMENT = 'DIRECT_ASSIGNMENT';
    /** Manager assigns an OPEN mission or replaces the instrumentist (assign()/reassign(), planning alert actions). */
    case MANAGER_ASSIGNMENT = 'MANAGER_ASSIGNMENT';
    /** A DRAFT mission already carrying an instrumentist was published by PlanningDeploymentService. */
    case PLANNING_DEPLOYMENT = 'PLANNING_DEPLOYMENT';
    /** Mission added after deploy with an instrumentist (createPostDeploy()). */
    case POST_DEPLOY_ADDITION = 'POST_DEPLOY_ADDITION';
    /** Restored by absence reconciliation (restoreAfterCancellation(), D-104). */
    case RESTORED_AFTER_ABSENCE = 'RESTORED_AFTER_ABSENCE';
    /** Mission declared by the instrumentist herself (DECLARED, spec §4.3). */
    case DECLARED = 'DECLARED';
    /** Affectation that existed before D-150 — reconstructed by the migration, date approximate. */
    case LEGACY = 'LEGACY';

    public function isVoluntaryAcceptance(): bool
    {
        return $this === self::POOL_CLAIM || $this === self::OFFER_ACCEPTED;
    }
}
