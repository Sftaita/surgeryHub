<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Lot 5 (D-099, §23) — SurgeonMissionRequestService::accept() a trouvé, via
 * PlanningConflictDetectionService::findConflict(), une autre mission active du
 * chirurgien qui chevauche la période demandée. L'acceptation échoue proprement et la
 * demande reste PENDING (aucune Mission créée, rien à rollback) : le manager peut
 * ensuite ajuster les dates, refuser la demande, ou résoudre le conflit ailleurs puis
 * réessayer.
 * Mappée à error.code = 'SURGEON_MISSION_REQUEST_CONFLICT' (409).
 */
class SurgeonMissionRequestConflictException extends ConflictHttpException
{
}
