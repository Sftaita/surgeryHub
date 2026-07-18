<?php

namespace App\Enum;

enum MissionStatus: string
{
    case DRAFT = 'DRAFT';
    case OPEN = 'OPEN';
    case DECLARED = 'DECLARED';
    case ASSIGNED = 'ASSIGNED';
    case REJECTED = 'REJECTED';
    case SUBMITTED = 'SUBMITTED';
    case VALIDATED = 'VALIDATED';
    case CLOSED = 'CLOSED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case CANCELLED = 'CANCELLED';
    /** Lot 7 (D-070) : l'instrumentiste a explicitement commencé l'encodage (distinct
     *  d'IN_PROGRESS, qui ne reflète qu'une fenêtre horaire écoulée — voir D-064). */
    case ENCODING_IN_PROGRESS = 'ENCODING_IN_PROGRESS';

    /** Centralized French label — use this instead of ->value in any user-facing surface. */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT               => 'Brouillon',
            self::OPEN                => 'Ouvert',
            self::DECLARED            => 'Déclarée',
            self::ASSIGNED            => 'Assignée',
            self::REJECTED            => 'Refusée',
            self::SUBMITTED           => 'Soumise',
            self::VALIDATED           => 'Validée',
            self::CLOSED              => 'Clôturée',
            self::IN_PROGRESS         => 'En cours',
            self::CANCELLED           => 'Annulée',
            self::ENCODING_IN_PROGRESS => 'Encodage en cours',
        };
    }
}