<?php

namespace App\Service;

use App\Entity\Mission;
use App\Enum\MissionStatus;
use App\Service\Prestation\MissionEncodingDataGuard;

/**
 * D-182 — when a manager may cancel a mission, in pure functions (no database access), shared
 * by MissionPostDeployService (the guard), MissionReplacementService, the allowedActions and
 * the mission detail served to the client. Never recomputed client-side.
 *
 * A cancelled mission is a mission that did not take place: no hours, no intervention, no
 * material, no billing, no statistics. Its passed start time, the automatic IN_PROGRESS status
 * (app:missions:start-due) or an encoding already started never prevent it: whatever was
 * encoded stays archived on the cancelled mission, for traceability only (never validated,
 * never billed — billing only ever reads VALIDATED missions).
 *
 * A submitted, validated or closed encoding is protected: blockedReason() explains the explicit
 * correction to make first (send back to correction, reopen), never a silent cancellation that
 * would leave validated figures behind. A mission whose financial calculation is locked
 * (billed) is never cancelled.
 *
 * Reasons: PROGRAM_CANCELLED (the programme did not take place) or REPLACED_BY_MISSION (the
 * mission is a duplicate of an existing mission that is kept — MissionReplacementService).
 */
final class MissionCancellationPolicy
{
    public const REASON_PROGRAM_CANCELLED   = 'PROGRAM_CANCELLED';
    public const REASON_REPLACED_BY_MISSION = 'REPLACED_BY_MISSION';

    public const CANCELLABLE_STATUSES = [
        MissionStatus::DRAFT,
        MissionStatus::OPEN,
        MissionStatus::ASSIGNED,
        MissionStatus::IN_PROGRESS,
        MissionStatus::ENCODING_IN_PROGRESS,
    ];

    /** Statuses a replacement mission may have: kept as is with everything encoded on it. */
    public const REPLACEMENT_STATUSES = [
        MissionStatus::DECLARED,
        MissionStatus::ASSIGNED,
        MissionStatus::IN_PROGRESS,
        MissionStatus::ENCODING_IN_PROGRESS,
        MissionStatus::SUBMITTED,
        MissionStatus::VALIDATED,
        MissionStatus::CLOSED,
    ];

    public const ENCODING_SUBMITTED  = 'CANCELLATION_ENCODING_SUBMITTED';
    public const ENCODING_VALIDATED  = 'CANCELLATION_ENCODING_VALIDATED';
    public const MISSION_BILLED      = 'CANCELLATION_MISSION_BILLED';
    public const MISSION_CLOSED      = 'CANCELLATION_MISSION_CLOSED';
    public const SHARED_PRESENCE     = 'CANCELLATION_SHARED_PRESENCE';
    public const PRESTATION_CONTENT  = 'CANCELLATION_PRESTATION_CONTENT';
    public const NOT_CANCELLABLE     = 'CANCELLATION_NOT_POSSIBLE';
    public const REASON_INVALID      = 'CANCELLATION_REASON_INVALID';
    public const REPLACEMENT_INVALID = 'CANCELLATION_REPLACEMENT_INCOMPATIBLE';
    public const REPLACEMENT_CHANGED = 'CANCELLATION_REPLACEMENT_CHANGED';

    private const TZ = 'Europe/Brussels';

    /**
     * @param int $prestationSize missions of its multi-room prestation (D-156,
     *        PrestationGroupResolver), 1 when alone or unknown
     * @return array{code: string, message: string}|null why a manager cannot cancel the mission right now
     */
    public static function blockedReason(Mission $mission, int $prestationSize = 1): ?array
    {
        $status = $mission->getStatus();

        $reason = match (true) {
            $status === MissionStatus::SUBMITTED => [self::ENCODING_SUBMITTED,
                "L'encodage de cette mission a déjà été soumis : elle ne peut pas être annulée telle quelle. Renvoyez d'abord l'encodage en correction ; l'annulation deviendra alors possible, sans aucune heure ni prestation comptabilisée."],
            $status === MissionStatus::VALIDATED && MissionEncodingCorrectionPolicy::reopenBlockedReason($mission) !== null => [self::MISSION_BILLED,
                "Cette mission est déjà facturée (calcul financier verrouillé) : elle ne peut plus être annulée. Une correction financière explicite est nécessaire."],
            $status === MissionStatus::VALIDATED => [self::ENCODING_VALIDATED,
                "L'encodage de cette mission est validé : elle ne peut pas être annulée après coup. Si la mission n'a réellement pas eu lieu, rouvrez d'abord l'encodage (correction contrôlée et tracée), puis annulez-la."],
            $status === MissionStatus::CLOSED => [self::MISSION_CLOSED,
                "Cette mission est clôturée : elle ne peut plus être annulée."],
            $status === MissionStatus::DECLARED => [self::NOT_CANCELLABLE,
                "Cette mission est une déclaration en attente : refusez la déclaration plutôt que de l'annuler."],
            !in_array($status, self::CANCELLABLE_STATUSES, true) => [self::NOT_CANCELLABLE,
                $status === MissionStatus::CANCELLED ? 'Cette mission est déjà annulée.' : "Cette mission ne peut pas être annulée."],
            $mission->getExecution()?->getPresence() !== null => [self::SHARED_PRESENCE,
                "Cette mission partage des heures communes avec d'autres salles de la prestation : détachez-la d'abord de la présence commune (correction contrôlée), puis annulez-la."],
            // D-156 — the interventions / material of a multi-room prestation are encoded once,
            // on one of its missions: cancelling it would take them out of billing for every room.
            $prestationSize > 1 && self::carriesContent($mission) => [self::PRESTATION_CONTENT,
                "Cette mission porte les interventions et le matériel de toute la prestation multi-salles : l'annuler les retirerait de la facturation des autres salles. Faites d'abord corriger l'encodage de la prestation, puis annulez-la."],
            default => null,
        };

        return $reason !== null ? ['code' => $reason[0], 'message' => $reason[1]] : null;
    }

    private static function carriesContent(Mission $mission): bool
    {
        $f = MissionEncodingDataGuard::facts($mission);

        return $f['interventionCount'] > 0 || $f['materialLineCount'] > 0 || $f['interventionRequestCount'] > 0;
    }

    /**
     * Why $replacement cannot be kept as the replacement of $cancelled (same programme: same
     * day in Europe/Brussels, same site, same surgeon, overlapping hours, someone on it).
     * Null when compatible. The "already used" check needs the database (the service).
     */
    public static function replacementIncompatibility(Mission $cancelled, Mission $replacement): ?string
    {
        if ($cancelled->getId() !== null && $cancelled->getId() === $replacement->getId()) {
            return 'Une mission ne peut pas se remplacer elle-même.';
        }
        if (!in_array($replacement->getStatus(), self::REPLACEMENT_STATUSES, true)) {
            return sprintf('La mission de remplacement est « %s » : elle ne peut pas être conservée comme remplacement.', $replacement->getStatus()->label());
        }
        if ($replacement->getInstrumentist() === null) {
            return "La mission de remplacement n'a pas d'instrumentiste.";
        }
        if ($cancelled->getSite()?->getId() !== $replacement->getSite()?->getId()) {
            return "La mission de remplacement n'est pas sur le même site.";
        }
        if ($cancelled->getSurgeon()?->getId() !== $replacement->getSurgeon()?->getId()) {
            return "La mission de remplacement ne concerne pas le même chirurgien.";
        }
        $aStart = $cancelled->getStartAt();
        $aEnd   = $cancelled->getEndAt();
        $bStart = $replacement->getStartAt();
        $bEnd   = $replacement->getEndAt();
        if ($aStart === null || $aEnd === null || $bStart === null || $bEnd === null) {
            return "Les horaires d'une des deux missions sont incomplets.";
        }
        $tz = new \DateTimeZone(self::TZ);
        if ($aStart->setTimezone($tz)->format('Y-m-d') !== $bStart->setTimezone($tz)->format('Y-m-d')) {
            return "La mission de remplacement n'est pas le même jour.";
        }
        if (!($bStart < $aEnd && $bEnd > $aStart)) {
            return 'Les horaires de la mission de remplacement ne recouvrent pas ceux de la mission à annuler.';
        }

        return null;
    }
}
