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

    /**
     * D-156 — part imputée à cette mission de la présence réelle partagée d'une prestation
     * regroupée (WorkedPresence), selon la règle « le segment partagé revient à la mission
     * qui termine le plus tôt ». La somme des parts du groupe = la durée réelle de la présence.
     */
    case PRESENCE_SHARE = 'PRESENCE_SHARE';

    /** Aucune donnée d'exécution disponible — repli sur Mission.startAt/endAt planifiés. */
    case PLANNED = 'PLANNED';
}
