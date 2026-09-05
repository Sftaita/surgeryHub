<?php

namespace App\Enum;

/**
 * Communication des absences chirurgiens (Lot A, D-114). Seul ROOM_RELEASE est émis par ce
 * lot ; les trois autres cases sont définies dès maintenant pour stabiliser le schéma du
 * journal (SurgeonAbsenceCommunication) avant les Lots B/C, qui les émettront.
 */
enum AbsenceCommunicationType: string
{
    case ROOM_RELEASE = 'ROOM_RELEASE';
    case BLOCK_MANAGEMENT_ABSENCE = 'BLOCK_MANAGEMENT_ABSENCE';
    case BLOCK_MANAGEMENT_MODIFICATION = 'BLOCK_MANAGEMENT_MODIFICATION';
    case BLOCK_MANAGEMENT_CANCELLATION = 'BLOCK_MANAGEMENT_CANCELLATION';
}
