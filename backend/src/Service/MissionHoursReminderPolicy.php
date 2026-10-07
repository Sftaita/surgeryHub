<?php

namespace App\Service;

use App\Entity\Mission;
use App\Enum\EffectiveDurationSource;
use App\Enum\MissionStatus;

/**
 * D-133 — "Rappeler les heures" : définition UNIQUE du moment où un rappel des heures réelles
 * a un sens, lue à la fois par MissionVoter::HOURS_REMIND (autorisation de l'endpoint) et par
 * MissionActionsService ('remind_hours' dans allowedActions[]). Jamais réimplémentée côté
 * frontend, qui se contente de lire allowedActions.
 *
 * Distinct de la relance d'encodage (D-120, MissionVoter::ENCODING_REMIND) : celle-ci porte sur
 * le workflow ("as-tu fini d'encoder ?"), ce rappel sur la source temporelle ("as-tu saisi tes
 * heures réellement prestées ?") — deux axes indépendants (D-118, décision 3).
 *
 * Conditions cumulatives :
 *  - un instrumentiste est affecté (sinon personne à rappeler) ;
 *  - la mission est terminée chronologiquement (endAt <= now) — avant, il n'y a pas encore
 *    d'heures réellement prestées à demander ;
 *  - aucune heure réelle n'est connue : même définition exactement que
 *    MissionExecutionService::resolveDuration() (source PLANNED = simple repli sur le planifié,
 *    jamais une saisie) ;
 *  - l'instrumentiste peut encore saisir ses heures depuis son écran : mêmes statuts que
 *    'edit_hours' dans MissionActionsService (et que la relance D-120), encodage ni verrouillé
 *    ni facturé — rappeler une saisie impossible n'aurait aucun sens.
 */
final class MissionHoursReminderPolicy
{
    /** Statuts où l'instrumentiste assigné dispose de 'edit_hours' (MissionActionsService). */
    private const HOURS_EDITABLE_STATUSES = [
        MissionStatus::ASSIGNED,
        MissionStatus::IN_PROGRESS,
        MissionStatus::ENCODING_IN_PROGRESS,
        MissionStatus::DECLARED,
    ];

    public static function isReminderRelevant(Mission $mission, ?\DateTimeImmutable $now = null): bool
    {
        if ($mission->getInstrumentist() === null) {
            return false;
        }

        if (!in_array($mission->getStatus(), self::HOURS_EDITABLE_STATUSES, true)) {
            return false;
        }

        if ($mission->getEncodingLockedAt() !== null || $mission->getInvoiceGeneratedAt() !== null) {
            return false;
        }

        $endAt = $mission->getEndAt();
        if ($endAt === null || $endAt > ($now ?? new \DateTimeImmutable())) {
            return false;
        }

        return MissionExecutionService::resolveDuration($mission)->source === EffectiveDurationSource::PLANNED;
    }
}
