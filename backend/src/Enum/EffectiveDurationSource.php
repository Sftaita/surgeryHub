<?php

namespace App\Enum;

/**
 * Lot 1 (Exécution & Valorisation) — indique d'où provient la durée retenue par
 * MissionExecutionService::resolveEffectiveDuration(). Distinct de HoursSource (qui
 * indique QUI a déclaré le réalisé) : celui-ci indique QUELLE donnée a été utilisée
 * pour calculer la durée effective.
 */
enum EffectiveDurationSource: string
{
    /** actualStartAt/actualEndAt renseignés — durée calculée depuis les horaires réels. */
    case ACTUAL_TIMES = 'ACTUAL_TIMES';

    /** actualDurationMinutes renseigné explicitement, sans horaires réels connus. */
    case ACTUAL_EXPLICIT = 'ACTUAL_EXPLICIT';

    /** Aucune donnée d'exécution disponible — repli sur Mission.startAt/endAt planifiés. */
    case PLANNED = 'PLANNED';
}
