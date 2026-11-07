<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;

/**
 * D-155 — « activité exécutée sans instrumentiste » : une seule règle pour le voter
 * (MissionVoter::DECLARE_EXECUTED_WITHOUT_INSTRUMENTIST / CANCEL_…), les actions servies
 * (allowedActions) et, sous le verrou de la mission, la transition elle-même
 * (MissionPostDeployService) — un appel direct est refusé exactement quand l'action n'est
 * pas proposée.
 *
 * Distinction essentielle : une mission OPEN encore disponible (à venir, dans le pool) n'est
 * jamais concernée ; seule une mission OPEN dont le début est passé (plus jamais prenable,
 * D-151) et que personne n'a couverte peut être déclarée réalisée sans instrumentiste.
 */
final class ExecutedWithoutInstrumentistPolicy
{
    /** Statuts depuis lesquels un manager peut annuler une déclaration erronée (avant soumission). */
    public const CANCELLABLE_STATUSES = [MissionStatus::ENCODING_IN_PROGRESS];

    public static function isManager(User $user): bool
    {
        $roles = $user->getRoles();

        return in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true);
    }

    /** Pourquoi la déclaration est impossible sur cette mission (null = possible), quel que soit l'acteur. */
    public static function declarationBlockedReason(Mission $mission, \DateTimeImmutable $now): ?string
    {
        if ($mission->isExecutedWithoutInstrumentist()) {
            return "L'activité de cette mission est déjà déclarée réalisée sans instrumentiste.";
        }
        if ($mission->getStatus() !== MissionStatus::OPEN || $mission->getInstrumentist() !== null) {
            return "Seule une mission ouverte, sans instrumentiste, peut être déclarée réalisée sans instrumentiste.";
        }
        $startAt = $mission->getStartAt();
        if ($startAt === null || $now < $startAt) {
            return "La mission n'a pas encore commencé : elle reste disponible pour les instrumentistes.";
        }

        return null;
    }

    /** Le chirurgien de CETTE mission (jamais celle d'un confrère) ou un manager. */
    public static function canDeclare(Mission $mission, User $user, \DateTimeImmutable $now): bool
    {
        if (!self::isManager($user) && !SurgeonEncodingPolicy::isMissionSurgeon($mission, $user)) {
            return false;
        }

        return self::declarationBlockedReason($mission, $now) === null;
    }

    /** Pourquoi l'annulation est impossible (null = possible). Jamais une fois soumise, validée ou verrouillée. */
    public static function cancellationBlockedReason(Mission $mission): ?string
    {
        if (!$mission->isExecutedWithoutInstrumentist()) {
            return "Aucune activité sans instrumentiste n'est déclarée sur cette mission.";
        }
        if (!in_array($mission->getStatus(), self::CANCELLABLE_STATUSES, true) || $mission->isEncodingLocked()) {
            return "La déclaration ne peut plus être annulée : l'encodage a été soumis ou validé (renvoyez-le d'abord en correction).";
        }

        return null;
    }

    public static function canCancel(Mission $mission, User $user): bool
    {
        return self::isManager($user) && self::cancellationBlockedReason($mission) === null;
    }

    /** Le chirurgien de la mission soumet lui-même l'encodage d'une activité qu'il a déclarée. */
    public static function surgeonCanSubmit(Mission $mission, User $user, \DateTimeImmutable $now): bool
    {
        return $mission->isExecutedWithoutInstrumentist()
            && in_array($mission->getStatus(), [MissionStatus::ENCODING_IN_PROGRESS, MissionStatus::SUBMITTED], true)
            && SurgeonEncodingPolicy::canEditEncoding($mission, $user, $now);
    }
}
