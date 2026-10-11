<?php

namespace App\MessageHandler;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\NotificationEmailPolicy;
use App\Enum\NotificationType;
use App\Message\MissionHoursReminderMessage;
use App\Notification\EmailContent;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Notification\PushContent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * D-136 — envoie le rappel des heures réelles à l'instrumentiste : Push d'abord, repli email
 * uniquement si le Push n'est pas livrable (même orchestration D-083/D-084 que la relance
 * d'encodage), chaque canal tracé dans OutboundNotification (historique des notifications).
 *
 * Pas de filtrage par préférences : action explicite et ponctuelle d'un manager, même
 * précédent que la relance manuelle D-120 et PLANNING_RESENT_MANUAL.
 *
 * Intégration H1–H8 — via NotificationDispatcher (type MISSION_HOURS_REMINDER, non
 * configurable) : même comportement que D-136 (push d'abord, e-mail en secours
 * FALLBACK_WHEN_PUSH_NOT_SENT, pas d'élément in-app), chaque canal tracé, idempotent par
 * demande (rejeu Messenger sans doublon).
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
        private readonly NotificationDispatcher $dispatcher,
        #[Autowire(service: 'monolog.logger.push')]
        private readonly LoggerInterface $logger,
        #[Autowire('%env(string:FRONTEND_URL)%')]
        private readonly string $frontendUrl,
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
            $path = sprintf('/app/i/missions/%d/encoding', $mission->getId());

            $this->dispatcher->dispatch(new NotificationIntent(
                recipient: $instrumentist,
                type: NotificationType::MISSION_HOURS_REMINDER,
                inAppPayload: ['missionId' => $mission->getId(), 'missionDate' => $day],
                mission: $mission,
                email: new EmailContent('SurgicalHub — Heures réelles à renseigner', 'emails/mission_hours_reminder.html.twig', [
                    'firstname'  => $instrumentist->getFirstname(),
                    'missionDay' => $day,
                    'missionUrl' => $this->frontendUrl . $path,
                ]),
                push: new PushContent($title, $body, ['missionId' => $mission->getId(), 'url' => $path]),
                episodeRef: sprintf('hours_reminder:%d:%s', (int) $mission->getId(), $message->requestId ?? 'legacy'),
                emailPolicy: NotificationEmailPolicy::FALLBACK_WHEN_PUSH_NOT_SENT,
                inApp: false,
            ));
            $this->logger->info('hours_reminder.dispatched', ['missionId' => $mission->getId(), 'userId' => $instrumentist->getId()]);
        } catch (\Throwable $e) {
            $this->logger->error('hours_reminder.failed', [
                'missionId' => $mission->getId(),
                'userId'    => $instrumentist->getId(),
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
