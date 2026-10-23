<?php

namespace App\Service;

use App\Entity\Mission;
use App\Enum\MissionStatus;

/**
 * D-166 — définition UNIQUE de « cette mission peut-elle recevoir une relance d'encodage ? »,
 * pour tous les chemins de relance :
 *
 *  - relance manuelle d'encodage (D-120, POST .../encoding/remind) : MissionVoter::ENCODING_REMIND
 *    et 'remind' dans allowedActions[] (MissionActionsService) lisent canSendManualReminder() ;
 *  - relances automatiques J+1/J+2/J+4 (D-143, app:encoding:send-reminders) :
 *    EncodingReminderService::isEncodingPending() part de hasReminderRecipient(), à la sélection
 *    ET juste avant l'envoi (mission relue en base) ;
 *  - rappel des heures (D-136, POST .../execution/remind) : MissionHoursReminderPolicy part de
 *    hasReminderRecipient() ; le handler asynchrone revérifie l'affectation réelle à l'envoi.
 *
 * Règle métier : une relance d'encodage s'adresse à l'instrumentiste affectée. Sans
 * instrumentiste affectée (mission non pourvue, désaffectée, ou activité réalisée sans
 * instrumentiste — D-155), aucune relance n'est possible : ni bouton, ni envoi, ni relance
 * automatique, ni notification / e-mail / push. Dès qu'une instrumentiste est (ré)affectée, la
 * mission redevient éligible selon les règles habituelles. L'affectation est toujours lue sur
 * la mission telle qu'elle est en base au moment de l'envoi, jamais sur une sélection passée.
 *
 * Ne concerne PAS les alertes de couverture du planning (missions non pourvues), qui suivent
 * leurs propres règles.
 */
final class EncodingReminderPolicy
{
    /**
     * Statuts où l'encodage est encore attendu de l'instrumentiste (D-120 / D-143) : mêmes
     * statuts pour la relance manuelle et les relances automatiques.
     */
    public const REMINDABLE_STATUSES = [
        MissionStatus::DECLARED,
        MissionStatus::ASSIGNED,
        MissionStatus::IN_PROGRESS,
        MissionStatus::ENCODING_IN_PROGRESS,
    ];

    /** Une relance a-t-elle un destinataire ? (une instrumentiste est affectée à la mission) */
    public static function hasReminderRecipient(Mission $mission): bool
    {
        return $mission->getInstrumentist() !== null;
    }

    /**
     * Relance manuelle d'encodage (D-120) : instrumentiste affectée et encodage encore attendu.
     * La relance manuelle reste possible à tout moment de cette fenêtre (c'est le manager qui
     * juge) ; l'anti-répétition de 10 minutes (D-143) est appliquée par le contrôleur (409).
     */
    public static function canSendManualReminder(Mission $mission): bool
    {
        return self::hasReminderRecipient($mission)
            && in_array($mission->getStatus(), self::REMINDABLE_STATUSES, true);
    }
}
