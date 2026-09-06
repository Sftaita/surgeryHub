<?php

namespace App\Enum;

/**
 * Communication des absences chirurgiens — Lot C (D-114), §7 de la demande. Classification
 * en lecture seule d'un couple (absence, site) pour « Libération de salle » — jamais
 * persisté, uniquement pour la preview/le résultat du rattrapage.
 *
 * `ALREADY_SENT` du vocabulaire proposé par la demande est délibérément fusionné avec
 * `NO_NEW_OCCURRENCE` : les deux décrivent exactement le même fait côté decision (le delta
 * d'occurrences jamais annoncées est vide) — le premier envoi et un allongement sans
 * nouvelle occurrence produisent la même conclusion pour ce site, distinguer les deux
 * n'apporterait aucune information supplémentaire au manager.
 */
enum AbsenceBackfillRoomReleaseAction: string
{
    case WILL_SEND = 'WILL_SEND';
    case NO_NEW_OCCURRENCE = 'NO_NEW_OCCURRENCE';
    case NO_RECIPIENT = 'NO_RECIPIENT';
    case NO_FUTURE_BLOCK = 'NO_FUTURE_BLOCK';
    case DISABLED = 'DISABLED';
}
