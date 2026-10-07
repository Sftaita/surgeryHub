<?php

namespace App\MessageHandler;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\OutboundNotificationStatus;
use App\Message\MissionHoursReminderMessage;
use App\Message\SendTemplatedEmailMessage;
use App\Service\MissionHoursReminderService;
use App\Service\OutboundNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-136 — envoie le rappel des heures réelles à l'instrumentiste : Push d'abord, repli email
 * uniquement si le Push n'est pas livrable (même orchestration D-083/D-084 que la relance
 * d'encodage), chaque canal tracé dans OutboundNotification (historique des notifications).
 *
 * Pas de filtrage par préférences : action explicite et ponctuelle d'un manager, même
 * précédent que la relance manuelle D-120 et PLANNING_RESENT_MANUAL.
 *
 * Aucune donnée patient : date de la mission et lien uniquement. Toute erreur est
 * journalisée et absorbée — le rappel a déjà été audité côté requête manager.
 */
#[AsMessageHandler]
final class MissionHoursReminderMessageHandler
{
    private const TIMEZONE = 'Europe/Brussels';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OutboundNotificationService $outboundNotificationService,
        private readonly MessageBusInterface $bus,
        #[Autowire(service: 'monolog.logger.push')]
        private readonly LoggerInterface $logger,
        #[Autowire('%env(string:FRONTEND_URL)%')]
        private readonly string $frontendUrl,
        #[Autowire('%env(string:MAILER_FROM_ADDRESS)%')]
        private readonly string $fromAddress,
        #[Autowire('%env(string:MAILER_FROM_NAME)%')]
        private readonly string $fromName,
    ) {
    }

    public function __invoke(MissionHoursReminderMessage $message): void
    {
        $mission = $this->em->find(Mission::class, $message->missionId);
        $instrumentist = $mission?->getInstrumentist();

        // Réassignée (ou désassignée) entre la demande et le traitement : ne jamais prévenir
        // quelqu'un qui n'est plus sur la mission.
        if (!$mission instanceof Mission || !$instrumentist instanceof User || $instrumentist->getId() !== $message->instrumentistId) {
            $this->logger->info('hours_reminder.skipped', [
                'missionId' => $message->missionId,
                'reason'    => 'instrumentist_changed_or_mission_missing',
            ]);
            return;
        }

        try {
            $day = $mission->getStartAt()?->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('d/m/Y');
            $title = 'Heures réelles à renseigner';
            $body = $day !== null
                ? sprintf('Merci de renseigner les heures réellement prestées pour votre mission du %s.', $day)
                : 'Merci de renseigner les heures réellement prestées pour cette mission.';
            $path = sprintf('/app/i/missions/%d', $mission->getId());

            $push = $this->outboundNotificationService->recordPushSend(
                $instrumentist,
                MissionHoursReminderService::NOTIFICATION_TYPE,
                $title,
                $body,
                ['missionId' => $mission->getId(), 'url' => $path],
                $mission,
            );

            if ($push->getStatus() === OutboundNotificationStatus::SENT) {
                $this->logger->info('hours_reminder.sent_push', ['missionId' => $mission->getId(), 'userId' => $instrumentist->getId()]);
                return;
            }

            $missionUrl = $this->frontendUrl . $path;
            $subject = 'SurgicalHub — Heures réelles à renseigner';
            $email = $this->outboundNotificationService->recordEmailQueued(
                $instrumentist,
                MissionHoursReminderService::NOTIFICATION_TYPE,
                $subject,
                rawData: ['missionId' => $mission->getId(), 'url' => $missionUrl],
                mission: $mission,
                fallbackOf: $push,
                fallbackReason: OutboundNotificationService::fallbackReasonFor($push),
            );

            $this->bus->dispatch(new SendTemplatedEmailMessage(
                to: (string) $instrumentist->getEmail(),
                subject: $subject,
                fromAddress: $this->fromAddress,
                fromName: $this->fromName,
                htmlTemplate: 'emails/mission_hours_reminder.html.twig',
                context: [
                    'firstname'  => $instrumentist->getFirstname(),
                    'missionDay' => $day,
                    'missionUrl' => $missionUrl,
                ],
                outboundNotificationId: $email->getId(),
            ));

            $this->logger->info('hours_reminder.sent_email', ['missionId' => $mission->getId(), 'userId' => $instrumentist->getId()]);
        } catch (\Throwable $e) {
            $this->logger->error('hours_reminder.failed', [
                'missionId' => $mission->getId(),
                'userId'    => $instrumentist->getId(),
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
