<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Thrown when a DRAFT-only Mission mutation (pre-deploy instrumentist assignment) is
 * attempted on a Mission that has already left DRAFT. Deployed missions must go through
 * MissionPostDeployService (release/reassign/assign) instead — see D-056.
 *
 * Mapped to error.code = 'MISSION_NOT_DRAFT' by ApiExceptionSubscriber so API consumers
 * can distinguish this from a generic 409 CONFLICT.
 */
class MissionNotDraftException extends ConflictHttpException
{
}
