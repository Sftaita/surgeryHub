<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Message\MissionHoursReminderMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-133 — rappel manuel des heures réelles manquantes. Ne mute aucun statut (ce n'est pas
 * une transition) : trace d'audit synchrone, puis envoi asynchrone via Messenger
 * (MissionHoursReminderMessageHandler — Push d'abord, repli email). L'autorisation reste
 * dans MissionVoter::HOURS_REMIND, appelé par le contrôleur avant ce service.
 */
final class MissionHoursReminderService
{
    public const NOTIFICATION_TYPE = 'MISSION_HOURS_REMINDER';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditService $audit,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function request(Mission $mission, User $actor): void
    {
        $instrumentist = $mission->getInstrumentist();
        if (!$instrumentist instanceof User) {
            throw new \LogicException('Cannot remind hours for a mission with no assigned instrumentist');
        }

        // Audit + mise en file dans une même transaction (transport Messenger Doctrine, même
        // connexion) : jamais une trace "rappel envoyé" sans message en file, ni l'inverse.
        // L'envoi lui-même (Push/email) reste asynchrone et ne peut plus faire échouer l'action.
        $this->em->wrapInTransaction(function () use ($mission, $actor, $instrumentist): void {
            $this->audit->record($mission, $actor, AuditEventType::MISSION_HOURS_MANUAL_REMINDER_SENT, [
                'actorId'         => $actor->getId(),
                'actorName'       => trim(($actor->getFirstname() ?? '') . ' ' . ($actor->getLastname() ?? '')),
                'instrumentistId' => $instrumentist->getId(),
            ]);
            $this->em->flush();

            $this->bus->dispatch(new MissionHoursReminderMessage((int) $mission->getId(), (int) $instrumentist->getId()));
        });
    }
}
