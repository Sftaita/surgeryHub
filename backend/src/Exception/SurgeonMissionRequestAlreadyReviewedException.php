<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Lot 5 (D-099) — SurgeonMissionRequestService::accept()/reject() relit la demande (sous
 * verrou pessimiste, après refresh()) et constate qu'elle n'est plus PENDING. Couvre
 * uniformément : une seconde tentative de revue, une tentative concurrente qui perd la
 * course sous le verrou (§22 — deux managers ouvrent la même demande), et tout état
 * terminal (ACCEPTED/REJECTED, tous deux définitifs en V1, §4). Un seul type
 * d'exception pour un seul fait métier — jamais une seconde Mission créée, jamais une
 * demande réacceptée après refus.
 * Mappée à error.code = 'SURGEON_MISSION_REQUEST_ALREADY_REVIEWED' (409).
 */
class SurgeonMissionRequestAlreadyReviewedException extends ConflictHttpException
{
}
