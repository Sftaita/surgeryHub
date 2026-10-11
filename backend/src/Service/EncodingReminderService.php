<?php

namespace App\Service;

use App\Entity\AuditEvent;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Entity\NotificationDispatch;
use App\Enum\NotificationEmailPolicy;
use App\Enum\NotificationType;
use App\Enum\OutboundNotificationChannel;
use App\Enum\OutboundNotificationStatus;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Notification\PushContent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * D-083 — rappel unique d'encodage D+1 à 08 h Europe/Brussels (remplace le TODO "rappel
 * de 19 h" jamais implémenté, docs/decisions.md). Décide et envoie ; la commande
 * (SendEncodingRemindersCommand) ne fait qu'orchestrer.
 *
 * H5a — l'envoi passe par NotificationDispatcher (type ENCODING_REMINDER_D1 : push d'abord,
 * email en repli, pas d'item in-app) : les préférences de la personne sont désormais
 * réellement appliquées (auparavant jamais consultées) et chaque canal est tracé. Rappel
 * automatique idempotent par mission (épisode `encoding_reminder:auto:{id}`, en plus du
 * garde-fou encodingReminderSentAt) ; relance manuelle : un épisode par lot d'action du manager.
 *
 * Décision H5a validée : le rappel AUTOMATIQUE respecte les préférences (push d'abord, email
 * en repli) ; la relance MANUELLE explicite d'un manager rend l'email obligatoire
 * (NotificationEmailPolicy::MANDATORY — communication opérationnelle demandée, pas un
 * abonnement facultatif), le push restant soumis aux préférences. Le type reste configurable :
 * seule l'intention de la relance manuelle porte la politique MANDATORY.
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
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationService $notificationService,
        private readonly AuditService $audit,
        #[Autowire(service: 'monolog.logger.push')]
        private readonly LoggerInterface $logger,
        /** true seulement là où la commande D-083 est réellement planifiée (cron). */
        #[Autowire('%env(bool:ENCODING_REMINDER_AUTO_ENABLED)%')]
        private readonly bool $automaticRemindersScheduled = false,
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

        $channel = $this->remind(
            $mission, $instrumentist,
            "La mission d'hier n'a pas encore été soumise. Pensez à finaliser votre encodage lorsque vous êtes disponible.",
            'encoding_reminder:auto:' . $mission->getId(),
        );

        $this->logger->info('encoding_reminder.sent_' . $channel, [
            'missionId' => $mission->getId(),
            'userId'    => $instrumentist->getId(),
        ]);

        return $channel === 'none' ? 'skipped' : $channel;
    }

    /**
     * D-120 — relance manuelle depuis le cockpit "Suivi des encodages". Contrairement à
     * processMission(), ne touche jamais Mission.encodingReminderSentAt (réservé au garde-fou
     * "au plus un rappel automatique" de D-083) : un manager peut relancer manuellement à
     * tout moment, y compris avant ou après le rappel automatique, sans jamais le supprimer
     * ni le dupliquer côté planification. Même canal (Push avec repli email) et même contenu
     * mission que le rappel automatique — seule la trace d'audit distingue les deux.
     *
     * H5a — the email is MANDATORY for this explicit manager action (push per preferences).
     * $batchId: the manager's action — a retried request with the same batch never sends a
     * second reminder; absent → the request's correlation (one action, one reminder).
     *
     * @return 'push+email'|'push'|'email'|'none'
     */
    public function sendManualReminder(Mission $mission, User $actor, ?string $batchId = null): string
    {
        $instrumentist = $mission->getInstrumentist();
        if (!$instrumentist instanceof User) {
            throw new \LogicException('Cannot remind a mission with no assigned instrumentist');
        }

        // One episode per manager action (batch, or the operation's correlation): a deliberate
        // second relaunch is a new reminder, a retry of the same one is not.
        $channel = $this->remind(
            $mission, $instrumentist, "Un manager vous rappelle de finaliser l'encodage de cette mission.",
            $batchId !== null ? sprintf('encoding_reminder:manual:%d:%s', $mission->getId(), $batchId) : null,
            NotificationEmailPolicy::MANDATORY,
        );

        $this->audit->record($mission, $actor, AuditEventType::MISSION_ENCODING_MANUAL_REMINDER_SENT, [
            'actorId'   => $actor->getId(),
            'actorName' => trim(($actor->getFirstname() ?? '') . ' ' . ($actor->getLastname() ?? '')),
            'channel'   => $channel,
        ]);
        $this->em->flush();

        $this->logger->info('encoding_reminder.sent_manual', [
            'missionId' => $mission->getId(),
            'actorId'   => $actor->getId(),
            'channel'   => $channel,
        ]);

        return $channel;
    }

    /**
     * H5a — push first, email fallback (D-083), through the dispatcher.
     *
     * @return 'push'|'email'|'none' the channel that actually carried the reminder
     */
    private function remind(
        Mission $mission,
        User $instrumentist,
        string $pushBody,
        ?string $episodeRef,
        NotificationEmailPolicy $emailPolicy = NotificationEmailPolicy::FALLBACK_WHEN_PUSH_NOT_SENT,
    ): string {
        $dispatch = $this->dispatcher->dispatch(new NotificationIntent(
            recipient: $instrumentist,
            type: NotificationType::ENCODING_REMINDER_D1,
            inAppPayload: ['missionId' => $mission->getId()],
            mission: $mission,
            email: $this->notificationService->missionEncodingReminderEmail($mission, $instrumentist),
            push: new PushContent('Encodage à finaliser', $pushBody, [
                'missionId' => $mission->getId(),
                'url'       => sprintf('/app/i/missions/%d', $mission->getId()),
            ]),
            episodeRef: $episodeRef,
            emailPolicy: $emailPolicy,
            inApp: false,
        ));

        return self::channelUsed($dispatch);
    }

    /** @return 'push+email'|'push'|'email'|'none' the channels that actually carried it */
    private static function channelUsed(NotificationDispatch $dispatch): string
    {
        $push = $email = false;
        foreach ($dispatch->getDeliveries() as $delivery) {
            if ($delivery->getChannel() === OutboundNotificationChannel::PUSH && $delivery->getStatus() === OutboundNotificationStatus::SENT) {
                $push = true;
            }
            if ($delivery->getChannel() === OutboundNotificationChannel::EMAIL
                && in_array($delivery->getStatus(), [OutboundNotificationStatus::QUEUED, OutboundNotificationStatus::SENT], true)) {
                $email = true;
            }
        }

        return match (true) {
            $push && $email => 'push+email',
            $push           => 'push',
            $email          => 'email',
            default         => 'none',
        };
    }

    /**
     * D-120 — date/heure de la prochaine relance automatique D+1 08h Europe/Brussels pour
     * cette mission, réutilisant EXACTEMENT les critères d'éligibilité de
     * findEligibleMissions() (jamais réimplémentés côté frontend). `null` si le rappel
     * automatique a déjà été envoyé, si la mission n'est plus éligible (soumise, verrouillée,
     * facturée), si elle n'a pas de date de fin, ou si ce moment est déjà passé :
     * findEligibleMissions() ne retient que les missions terminées la veille, une mission
     * non relancée à J+1 08h ne le sera donc plus jamais automatiquement (jamais une date
     * passée présentée comme "prochaine relance"). Toujours null tant que la planification
     * n'est pas activée (ENCODING_REMINDER_AUTO_ENABLED, défaut 0).
     */
    public function nextAutomaticReminderAt(Mission $mission, ?\DateTimeImmutable $now = null): ?string
    {
        // Jamais annoncer une relance que rien n'enverra : sans planification active de
        // app:notifications:send-encoding-reminders (ENCODING_REMINDER_AUTO_ENABLED=0),
        // il n'y a pas de "prochaine relance automatique".
        if (!$this->automaticRemindersScheduled) {
            return null;
        }
        if ($mission->getInstrumentist() === null) {
            return null;
        }
        if ($mission->getSubmittedAt() !== null) {
            return null;
        }
        if ($mission->getEncodingLockedAt() !== null) {
            return null;
        }
        if ($mission->getInvoiceGeneratedAt() !== null) {
            return null;
        }
        if ($mission->getEncodingReminderSentAt() !== null) {
            return null;
        }
        if (!in_array($mission->getStatus(), self::SUBMITTABLE_STATUSES, true)) {
            return null;
        }

        $endAt = $mission->getEndAt();
        if ($endAt === null) {
            return null;
        }

        $reminderDay = $endAt
            ->setTimezone(new \DateTimeZone(self::TIMEZONE))
            ->modify('+1 day')
            ->setTime(8, 0, 0);

        if ($reminderDay <= ($now ?? new \DateTimeImmutable())) {
            return null;
        }

        return $reminderDay->format(\DateTimeInterface::ATOM);
    }

    /**
     * D-120 — dernière relance manuelle journalisée pour cette mission (toutes, pas
     * seulement la plus récente d'une éventuelle série), ou null si aucune n'a jamais été
     * envoyée. Lu depuis AuditEvent — jamais un nouveau champ dupliqué sur Mission.
     *
     * @return array{at: ?string, byName: string}|null
     */
    public function lastManualReminder(Mission $mission): ?array
    {
        /** @var AuditEvent|null $event */
        $event = $this->em->createQuery(
            'SELECT a, actor FROM App\Entity\AuditEvent a
             JOIN a.actor actor
             WHERE a.mission = :mission AND a.eventType = :type
             ORDER BY a.createdAt DESC'
        )
            ->setParameter('mission', $mission)
            ->setParameter('type', AuditEventType::MISSION_ENCODING_MANUAL_REMINDER_SENT)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        if ($event === null) {
            return null;
        }

        $actor = $event->getActor();

        return [
            'at'     => $event->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'byName' => trim(($actor?->getFirstname() ?? '') . ' ' . ($actor?->getLastname() ?? '')),
        ];
    }
}
