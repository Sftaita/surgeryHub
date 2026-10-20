<?php

namespace App\Service;

use App\Entity\Mission;
use App\Enum\MissionStatus;

/**
 * D-158 — une seule règle pour « une absence modifie-t-elle automatiquement cette mission
 * publiée ? », lue par AbsenceImpactResolver (conséquence annoncée),
 * AbsenceMissionReactionService (annulation / retrait, re-vérifiés sous le verrou de la
 * mission) et AbsenceImpactService (motif porté par l'alerte manager).
 *
 *  - absence du chirurgien : seule une mission OPEN|ASSIGNED est annulée (règle D-062) ;
 *  - absence de l'instrumentiste : seule une mission ASSIGNED est remise au pool (D-062).
 *
 * Dans les deux cas, uniquement si la mission est encore à venir et sans aucune activité
 * encodée. Une mission déjà commencée (absence déclarée après coup) ou portant un encodage
 * (interventions, matériel, heures réelles, activité déclarée sans instrumentiste) peut avoir
 * eu lieu : elle n'est jamais annulée ni rouverte à l'aveugle — elle reste telle quelle et le
 * manager reçoit l'alerte d'absence (AbsenceImpactService) avec le motif.
 */
final class AbsenceMissionProtectionPolicy
{
    /** Statuts qu'une absence du chirurgien peut annuler. */
    public const ACTIONABLE_STATUSES = [MissionStatus::OPEN, MissionStatus::ASSIGNED];

    /** Statuts qu'une absence de l'instrumentiste peut rouvrir (remise au pool). */
    public const INSTRUMENTIST_ACTIONABLE_STATUSES = [MissionStatus::ASSIGNED];

    public const BLOCKED_MISSION_STARTED   = 'MISSION_STARTED';
    public const BLOCKED_ACTIVITY_ENCODED  = 'ACTIVITY_ENCODED';

    /**
     * Pourquoi la réaction automatique est exclue (null = réaction automatique permise, ou
     * mission hors périmètre OPEN|ASSIGNED : ni annulée, ni rouverte par une absence).
     */
    public static function blockedReason(Mission $mission, \DateTimeImmutable $now): ?string
    {
        if (!in_array($mission->getStatus(), self::ACTIONABLE_STATUSES, true)) {
            return null;
        }

        $startAt = $mission->getStartAt();
        if ($startAt === null || $startAt <= $now) {
            return self::BLOCKED_MISSION_STARTED;
        }

        if (self::hasEncodedActivity($mission)) {
            return self::BLOCKED_ACTIVITY_ENCODED;
        }

        return null;
    }

    /** Absence du chirurgien : la mission peut-elle être annulée automatiquement ? */
    public static function canAutoCancel(Mission $mission, \DateTimeImmutable $now): bool
    {
        return in_array($mission->getStatus(), self::ACTIONABLE_STATUSES, true)
            && self::blockedReason($mission, $now) === null;
    }

    /** Absence de l'instrumentiste : la mission peut-elle être remise au pool automatiquement ? */
    public static function canAutoRelease(Mission $mission, \DateTimeImmutable $now): bool
    {
        return in_array($mission->getStatus(), self::INSTRUMENTIST_ACTIONABLE_STATUSES, true)
            && self::blockedReason($mission, $now) === null;
    }

    private static function hasEncodedActivity(Mission $mission): bool
    {
        if ($mission->isExecutedWithoutInstrumentist()
            || !$mission->getInterventions()->isEmpty()
            || !$mission->getMaterialLines()->isEmpty()) {
            return true;
        }

        $execution = $mission->getExecution();

        return $execution !== null
            && ($execution->getActualStartAt() !== null || $execution->getActualEndAt() !== null);
    }
}
