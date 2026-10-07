<?php

namespace App\Message;

/**
 * D-136 — rappel des heures réelles manquantes, demandé par un manager depuis le cockpit
 * Suivi des encodages. Dispatché APRÈS l'audit (MissionHoursReminderService::request()) :
 * l'envoi Push/email est asynchrone et un échec d'envoi ne remonte jamais à l'action manager.
 *
 * instrumentistId est figé au moment de la demande : si la mission est réassignée entre la
 * demande et le traitement, le handler n'envoie rien plutôt que de prévenir la mauvaise personne.
 */
final class MissionHoursReminderMessage
{
    public function __construct(
        public readonly int $missionId,
        public readonly int $instrumentistId,
    ) {
    }
}
