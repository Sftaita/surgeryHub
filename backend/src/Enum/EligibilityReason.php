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
    // D-151 — this instrumentist's affectation to THIS mission was refused by its surgeon:
    // she can never take it back (claim, offer, assignment), whatever the path.
    case REFUSED_FOR_MISSION = 'REFUSED_FOR_MISSION';
    // D-151 — the mission has already started: it can no longer be claimed from the pool
    // (mission-level, like INCOMPATIBLE_STATUS; never applied to manager assignments).
    case MISSION_ALREADY_STARTED = 'MISSION_ALREADY_STARTED';
    // D-152 — an active surgeon–instrumentist incompatibility with this mission's surgeon:
    // never offered, never claimable, never assignable, whatever the path.
    case SURGEON_INCOMPATIBILITY = 'SURGEON_INCOMPATIBILITY';

    public function label(): string
    {
        return match ($this) {
            self::INACTIVE            => 'Compte inactif',
            self::NO_SITE_MEMBERSHIP  => "Pas d'affiliation au site",
            self::ABSENT              => 'Absent ce jour',
            self::SCHEDULE_CONFLICT   => "Conflit d'horaire",
            self::ALREADY_ASSIGNED    => 'Mission déjà attribuée',
            self::INCOMPATIBLE_STATUS => 'Statut incompatible',
            // Instrumentist-facing (claim error): neutral, never reveals a personal refusal (D-151).
            self::REFUSED_FOR_MISSION => "Cette mission n'est plus disponible pour vous",
            self::MISSION_ALREADY_STARTED => 'Mission déjà commencée',
            // Instrumentist-facing (claim error): neutral, never reveals a block (D-152).
            self::SURGEON_INCOMPATIBILITY => "Cette mission n'est plus disponible pour vous",
        };
    }
}
