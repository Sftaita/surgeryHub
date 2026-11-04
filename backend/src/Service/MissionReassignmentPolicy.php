<?php

namespace App\Service;

use App\Entity\Mission;
use App\Enum\MissionStatus;
use App\Service\Prestation\MissionEncodingDataGuard;

/**
 * D-181 — how the instrumentist of a published mission may be changed by a manager, in pure
 * functions (no database access), shared by MissionPostDeployService (the guard), the
 * allowedActions and the mission detail served to the client. Never recomputed client-side.
 *
 *   DIRECT — the mission changes hands entirely (same mission, new instrumentist). Only when
 *            nothing is encoded on it: the date, a passed start time or the automatic
 *            IN_PROGRESS status (app:missions:start-due) never block it.
 *   RELAY  — the mission has started (or carries encoded data): it ends at the relay time with
 *            its first instrumentist, who keeps everything she encoded, and a linked relay
 *            mission (Mission::relayOf) continues the slot with the replacement.
 *
 * Never on SUBMITTED / VALIDATED / CLOSED (blockedReason() explains the correction path),
 * never on a mission sharing a common presence (multi-room prestation, D-156): its hours
 * belong to the whole prestation and are detached first through the controlled correction.
 */
final class MissionReassignmentPolicy
{
    public const MODE_DIRECT = 'DIRECT';
    public const MODE_RELAY  = 'RELAY';

    public const REASSIGNABLE_STATUSES = [
        MissionStatus::ASSIGNED,
        MissionStatus::IN_PROGRESS,
        MissionStatus::ENCODING_IN_PROGRESS,
    ];

    /** Stable error codes (409 MissionReassignmentException). */
    public const ENCODING_SUBMITTED   = 'REASSIGNMENT_ENCODING_SUBMITTED';
    public const ENCODING_VALIDATED   = 'REASSIGNMENT_ENCODING_VALIDATED';
    public const MISSION_CLOSED       = 'REASSIGNMENT_MISSION_CLOSED';
    public const SHARED_PRESENCE      = 'REASSIGNMENT_SHARED_PRESENCE';
    public const NOT_REASSIGNABLE     = 'REASSIGNMENT_NOT_POSSIBLE';
    public const MODE_NOT_AVAILABLE   = 'REASSIGNMENT_MODE_NOT_AVAILABLE';
    public const RELAY_TIME_INVALID   = 'REASSIGNMENT_RELAY_TIME_INVALID';
    public const ASSIGNMENT_CHANGED   = 'REASSIGNMENT_ASSIGNMENT_CHANGED';
    public const NO_CHANGE            = 'REASSIGNMENT_NO_CHANGE';

    /**
     * The modes available right now, the first one being the one to suggest. Empty when the
     * mission cannot be reassigned (see blockedReason()).
     *
     * @return list<string>
     */
    public static function modes(Mission $mission, \DateTimeImmutable $now): array
    {
        if (self::blockedReason($mission) !== null) {
            return [];
        }

        $started = $mission->getStartAt() !== null && $mission->getStartAt() < $now;
        $relayPossible = self::relayWindow($mission) !== null;

        if (MissionEncodingDataGuard::hasEncodingData($mission)) {
            return $relayPossible ? [self::MODE_RELAY] : [];
        }

        return $started && $relayPossible ? [self::MODE_DIRECT, self::MODE_RELAY] : [self::MODE_DIRECT];
    }

    /** @return array{code: string, message: string}|null why the instrumentist cannot be changed */
    public static function blockedReason(Mission $mission): ?array
    {
        $status = $mission->getStatus();

        $reason = match (true) {
            $status === MissionStatus::SUBMITTED => [self::ENCODING_SUBMITTED,
                "Le changement d'instrumentiste n'est plus autorisé pour une mission soumise. Renvoyez d'abord l'encodage en correction ; le relais deviendra alors possible, sans rien perdre de ce qui a été encodé."],
            $status === MissionStatus::VALIDATED => [self::ENCODING_VALIDATED,
                "Le changement d'instrumentiste n'est plus autorisé pour une mission validée. Si l'affectation est réellement erronée, rouvrez d'abord l'encodage (correction contrôlée et tracée)."],
            $status === MissionStatus::CLOSED => [self::MISSION_CLOSED,
                "Cette mission est clôturée : son affectation et son encodage ne peuvent plus être modifiés."],
            !in_array($status, self::REASSIGNABLE_STATUSES, true) || $mission->getInstrumentist() === null => [self::NOT_REASSIGNABLE,
                "Cette mission n'a aucune affectation à remplacer."],
            $mission->getExecution()?->getPresence() !== null => [self::SHARED_PRESENCE,
                "Cette mission partage des heures communes avec d'autres salles de la prestation : détachez-la d'abord de la présence commune (correction contrôlée), puis organisez le relais."],
            default => null,
        };

        return $reason !== null ? ['code' => $reason[0], 'message' => $reason[1]] : null;
    }

    /**
     * Bounds (exclusive) within which a relay time may fall: strictly after the start and
     * strictly before the planned end. Null when the mission has no usable schedule.
     *
     * @return array{from: \DateTimeImmutable, to: \DateTimeImmutable}|null
     */
    public static function relayWindow(Mission $mission): ?array
    {
        $start = $mission->getStartAt();
        $end   = $mission->getEndAt();
        if ($start === null || $end === null || $end <= $start) {
            return null;
        }

        return ['from' => $start, 'to' => $end];
    }

    public static function isValidRelayTime(Mission $mission, \DateTimeImmutable $relayAt): bool
    {
        $window = self::relayWindow($mission);

        return $window !== null && $relayAt > $window['from'] && $relayAt < $window['to'];
    }
}
