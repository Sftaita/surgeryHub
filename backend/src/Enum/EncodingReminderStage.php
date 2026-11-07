<?php

namespace App\Enum;

/**
 * D-143 — échéances de la relance progressive d'encodage. Chaque échéance tombe à 09 h
 * Europe/Brussels le N-ième jour civil suivant la date locale de fin de mission.
 */
enum EncodingReminderStage: string
{
    case D1 = 'D1';
    case D2 = 'D2';
    case D4 = 'D4';

    /** Nombre de jours civils après la date locale de fin de mission. */
    public function dayOffset(): int
    {
        return match ($this) {
            self::D1 => 1,
            self::D2 => 2,
            self::D4 => 4,
        };
    }

    /** J+4 : e-mail instrumentiste systématique + alerte managers. */
    public function isEscalation(): bool
    {
        return $this === self::D4;
    }
}
