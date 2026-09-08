<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * CAS D (D-115) — thrown when a draft-only operation (reopen for editing, line mutation,
 * or delete) targets a PlanningVersion that is no longer DRAFT (already deployed/archived,
 * or — for delete — contains at least one mission that left DRAFT some other way). Mapped
 * to error.code = 'PLANNING_VERSION_NOT_DRAFT' by ApiExceptionSubscriber, same pattern as
 * MissionNotDraftException (D-056).
 */
class PlanningVersionNotDraftException extends ConflictHttpException
{
}
