<?php

namespace App\Enum;

enum EligibilityReason: string
{
    case INACTIVE            = 'INACTIVE';
    case NO_SITE_MEMBERSHIP  = 'NO_SITE_MEMBERSHIP';
    case ABSENT              = 'ABSENT';
    case SCHEDULE_CONFLICT   = 'SCHEDULE_CONFLICT';
    case ALREADY_ASSIGNED    = 'ALREADY_ASSIGNED';
    case INCOMPATIBLE_STATUS = 'INCOMPATIBLE_STATUS';

    public function label(): string
    {
        return match ($this) {
            self::INACTIVE            => 'Compte inactif',
            self::NO_SITE_MEMBERSHIP  => "Pas d'affiliation au site",
            self::ABSENT              => 'Absent ce jour',
            self::SCHEDULE_CONFLICT   => "Conflit d'horaire",
            self::ALREADY_ASSIGNED    => 'Mission déjà attribuée',
            self::INCOMPATIBLE_STATUS => 'Statut incompatible',
        };
    }
}
