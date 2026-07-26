<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * D-083 — rappel unique d'encodage D+1 à 08 h Europe/Brussels (remplace le TODO "rappel
 * de 19 h" jamais implémenté, docs/decisions.md). Décide et envoie ; la commande
 * (SendEncodingRemindersCommand) ne fait qu'orchestrer.
 */
class EncodingReminderService
{
    private const TIMEZONE = 'Europe/Brussels';

    /** Statuts où l'encodage est encore ouvert/soumissible (miroir de MissionActionsService::allowedActions()'submit'). */
    private const SUBMITTABLE_STATUSES = [
        MissionStatus::ASSIGNED,
        MissionStatus::IN_PROGRESS,
        MissionStatus::ENCODING_IN_PROGRESS,
        MissionStatus::DECLARED,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WebPushService $webPushService,
        private readonly NotificationService $notificationService,
        #[Autowire(service: 'monolog.logger.push')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Missions dont la fin planifiée tombe "hier" (jour métier Europe/Brussels par
     * rapport à $now), toujours non soumises, pas encore rappelées.
     *
     * @return Mission[]
     */
    public function findEligibleMissions(\DateTimeImmutable $now): array
    {
        $today = $now->setTime(0, 0, 0);
        $yesterday = $today->modify('-1 day');

        return $this->em->createQueryBuilder()
            ->select('m')
            ->from(Mission::class, 'm')
            ->where('m.instrumentist IS NOT NULL')
            ->andWhere('m.submittedAt IS NULL')
            ->andWhere('m.encodingLockedAt IS NULL')
            ->andWhere('m.invoiceGeneratedAt IS NULL')
            ->andWhere('m.encodingReminderSentAt IS NULL')
            ->andWhere('m.status IN (:statuses)')
            ->andWhere('m.endAt >= :yesterday')
            ->andWhere('m.endAt < :today')
            ->setParameter('statuses', self::SUBMITTABLE_STATUSES)
            ->setParameter('yesterday', $yesterday)
            ->setParameter('today', $today)
            ->getQuery()
            ->getResult();
    }

    /**
     * Réserve la mission (marque encodingReminderSentAt via une UPDATE conditionnelle
     * atomique — "au plus un rappel par mission" tient même sous double exécution/deux
     * workers, sans dépendre d'un verrou applicatif), puis tente Push, avec repli email
     * si Push n'est pas réellement livrable.
     *
     * Compromis assumé : la réservation a lieu AVANT l'envoi, pas après. Si l'envoi
     * échoue après la réservation (exception inattendue), le rappel de cette mission est
     * perdu plutôt que retenté le lendemain — préféré à l'alternative (envoyer puis
     * marquer), qui risquerait un double envoi si le process meurt entre les deux. Le
     * repli email étant un simple dépôt en file Messenger (échoue seulement en cas de
     * panne d'infrastructure), ce risque de perte est en pratique très faible.
     *
     * @return 'push'|'email'|'skipped'
     */
    public function processMission(Mission $mission, \DateTimeImmutable $sentAt): string
    {
        $affected = $this->em->createQueryBuilder()
            ->update(Mission::class, 'm')
            ->set('m.encodingReminderSentAt', ':sentAt')
            ->where('m.id = :id')
            ->andWhere('m.encodingReminderSentAt IS NULL')
            ->setParameter('sentAt', $sentAt)
            ->setParameter('id', $mission->getId())
            ->getQuery()
            ->execute();

        if ($affected === 0) {
            // Déjà réclamée par une exécution concurrente entre la sélection et ce traitement.
            $this->logger->info('encoding_reminder.skipped', [
                'missionId' => $mission->getId(),
                'reason'    => 'already_claimed_concurrently',
            ]);

            return 'skipped';
        }

        $mission->setEncodingReminderSentAt($sentAt);

        $instrumentist = $mission->getInstrumentist();
        if (!$instrumentist instanceof User) {
            // Ne devrait jamais arriver (filtré par la requête d'éligibilité) — garde défensive.
            $this->logger->info('encoding_reminder.skipped', [
                'missionId' => $mission->getId(),
                'reason'    => 'no_instrumentist',
            ]);

            return 'skipped';
        }

        $title = 'Encodage à finaliser';
        $body = "La mission d'hier n'a pas encore été soumise. Pensez à finaliser votre encodage lorsque vous êtes disponible.";
        $data = [
            'missionId' => $mission->getId(),
            'url'       => sprintf('/app/i/missions/%d', $mission->getId()),
        ];

        $pushDelivered = $this->webPushService->sendToUserAndReportSuccess($instrumentist, $title, $body, $data);

        if ($pushDelivered) {
            $this->logger->info('encoding_reminder.sent_push', [
                'missionId' => $mission->getId(),
                'userId'    => $instrumentist->getId(),
            ]);

            return 'push';
        }

        $this->notificationService->missionEncodingReminderNotifyInstrumentist($mission);

        $this->logger->info('encoding_reminder.sent_email', [
            'missionId' => $mission->getId(),
            'userId'    => $instrumentist->getId(),
        ]);

        return 'email';
    }
}
