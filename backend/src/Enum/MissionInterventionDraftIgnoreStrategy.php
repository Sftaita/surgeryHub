<?php

namespace App\Enum;

/**
 * EPIC Revue instrumentiste, Lot 3, commit 6 — stratégie choisie par le manager pour
 * ignorer une InterventionTypeRequest dont le draft porte déjà du matériel :
 * KEEP_AS_HISTORY (matériel gelé sur le draft, non facturable, lecture seule) ou REASSIGN
 * (matériel repointé vers une MissionIntervention réelle de la même mission). Obligatoire
 * dès que le draft porte du matériel — voir MissionInterventionDraftService::ignore().
 */
enum MissionInterventionDraftIgnoreStrategy: string
{
    case KEEP_AS_HISTORY = 'KEEP_AS_HISTORY';
    case REASSIGN = 'REASSIGN';
}
