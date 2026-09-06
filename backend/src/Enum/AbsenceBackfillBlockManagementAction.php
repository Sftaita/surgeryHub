<?php

namespace App\Enum;

/**
 * Communication des absences chirurgiens — Lot C (D-114), §7 de la demande. Classification
 * en lecture seule d'un couple (absence, site) pour « Gestion du bloc » — jamais persisté.
 *
 * `NO_BLOCK` du vocabulaire proposé par la demande n'a structurellement aucun cas d'usage
 * ici : une ligne de preview n'est jamais générée pour un site sans occurrence BLOCK dans la
 * fenêtre de l'absence (`SurgeonAbsenceBlockOccurrenceResolver::resolveForWindow()` est le
 * point d'entrée commun aux deux flux — un site absent de son résultat n'apparaît jamais
 * dans la liste des sites à classifier). Omis pour ne pas fabriquer un état inatteignable.
 *
 * `ABSENCE_ALREADY_ENDED` est un ajout par rapport à la liste proposée dans la demande —
 * nécessaire pour couvrir §14 (congé déjà entièrement terminé au moment du rattrapage :
 * aucune notification rétroactive de gestion du bloc, jamais implicite dans les 6 statuts
 * initialement listés).
 */
enum AbsenceBackfillBlockManagementAction: string
{
    case WILL_SEND_NOW = 'WILL_SEND_NOW';
    case WILL_SCHEDULE = 'WILL_SCHEDULE';
    case ALREADY_PROCESSED = 'ALREADY_PROCESSED';
    case DISABLED = 'DISABLED';
    case MISSING_CONFIG = 'MISSING_CONFIG';
    case ABSENCE_ALREADY_ENDED = 'ABSENCE_ALREADY_ENDED';
}
