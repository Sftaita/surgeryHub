<?php

namespace App\Enum;

enum UncoveredReason: string
{
    case NO_SITE_MEMBERSHIP         = 'NO_SITE_MEMBERSHIP';
    case ALL_ABSENT                 = 'ALL_ABSENT';
    case ALL_IN_CONFLICT            = 'ALL_IN_CONFLICT';
    case MANUALLY_LEFT_OPEN         = 'MANUALLY_LEFT_OPEN';

    public function label(): string
    {
        return match ($this) {
            self::NO_SITE_MEMBERSHIP => 'Aucun instrumentiste affilié au site',
            self::ALL_ABSENT         => 'Tous les instrumentistes sont absents',
            self::ALL_IN_CONFLICT    => 'Tous les instrumentistes ont un conflit',
            self::MANUALLY_LEFT_OPEN => 'Recherche en cours',
        };
    }
}
