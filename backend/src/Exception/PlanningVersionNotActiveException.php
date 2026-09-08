<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * CAS C (D-116) — thrown when a post-deploy mutation (apply-modifications: add/reassign/
 * release/cancel/reschedule a mission) targets a PlanningVersion that is no longer the
 * live ACTIVE one for its scope. Without this guard, a manager whose Modification-mode
 * tab was opened against a version later ARCHIVED by a concurrent redeploy (see
 * PlanningDeploymentService::deploy(), which archives the previous ACTIVE version) could
 * silently attach a new/edited Mission to that now-archived version — invisible
 * thereafter, since every published-planning view resolves to the CURRENT ACTIVE version.
 * Mapped to error.code = 'PLANNING_VERSION_NOT_ACTIVE' by ApiExceptionSubscriber, same
 * pattern as PlanningVersionNotDraftException (D-115).
 */
class PlanningVersionNotActiveException extends ConflictHttpException
{
}
