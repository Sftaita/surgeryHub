<?php

namespace App\Service;

use App\Entity\AuditEvent;
use App\Service\Prestation\PrestationGrouping;
use App\Service\Prestation\PrestationGroupResolver;
use App\Entity\Mission;
use App\Entity\MissionEncodingReminder;
use App\Entity\NotificationDispatch;
use App\Entity\OutboundNotification;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\EncodingReminderStage;
use App\Enum\EncodingSituation;
use App\Enum\MissionStatus;
use App\Enum\NotificationEmailPolicy;
use App\Enum\NotificationType;
use App\Enum\OutboundNotificationChannel;
use App\Enum\OutboundNotificationStatus;
use App\Notification\EmailContent;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Notification\PushContent;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * D-143 — relance progressive d'encodage (remplace le rappel unique D+1 08 h de D-083 et
 * le « rappel de 19 h » jamais implémenté) :
 *
 *   | Échéance (09 h Europe/Brussels) | Instrumentiste                         | Managers            |
 *   | J+1                             | push si livré, sinon e-mail            | —                   |
 *   | J+2                             | push si livré, sinon e-mail            | —                   |
 *   | J+4                             | push si livré + e-mail systématique    | in-app + e-mail     |
 *
 * J+N = N-ième jour civil suivant la date LOCALE de fin de mission. Le centre de
 * notifications de l'instrumentiste reçoit chaque relance (NotificationEvent IN_APP).
 *
 * Une échéance n'est traitée que pendant sa fenêtre [échéance, échéance + 24 h[ : jamais
 * avant (règle produit), et jamais une rafale de relances anciennes au premier passage du
 * cron ou après une panne de plusieurs jours (un rattrapage de 24 h absorbe un cron
 * manqué). Les fenêtres ne se chevauchent pas (J+1, J+2, J+4).
 *
 * Idempotence : réservation par INSERT dans mission_encoding_reminder (UNIQUE mission +
 * échéance) AVANT tout envoi — une seconde exécution (double cron, worker redémarré,
 * redéploiement) échoue sur la contrainte et n'envoie rien. Même compromis assumé que
 * D-083 : si l'envoi échoue après la réservation, la relance de CETTE échéance est perdue
 * plutôt que dupliquée (l'échec est historisé sur la ligne, l'échéance suivante reste due).
 *
 * L'état est recontrôlé juste avant l'envoi (relecture de la mission) : une mission
 * soumise — y compris en attente de validation manager — n'est jamais relancée.
 *
 * Aucune donnée patient : date de la mission, prénom, lien. Préférences : ces relances
 * sont une obligation de service (validation, rémunération) — non configurables
 * (NotificationType::isOperationalObligation()). Le push n'est tenté que vers les appareils
 * réellement abonnés et n'est compté comme livré que si le transport l'a accepté ; les
 * abonnements expirés sont nettoyés par WebPushService (D-081).
 *
 * Intégration H1–H8 : tous les envois (instrumentiste, managers, relance manuelle) passent
 * par NotificationDispatcher (D-128/D-131) — une intention par destinataire et par épisode,
 * idempotence par épisode en plus de la réservation, chaque canal tracé dans
 * OutboundNotification + notification_dispatch + activity_event. Aucun envoi direct.
 */
class EncodingReminderService
{
    private const TIMEZONE = 'Europe/Brussels';
    public const SEND_HOUR = 9;

    /** Statuts où l'encodage est encore attendu de l'instrumentiste (soumission possible). */
    private const PENDING_STATUSES = [
        MissionStatus::ASSIGNED,
        MissionStatus::IN_PROGRESS,
        MissionStatus::ENCODING_IN_PROGRESS,
        MissionStatus::DECLARED,
    ];

    /** Plus ancienne fin de mission encore concernée : J+4 + 24 h de rattrapage. */
    private const LOOKBACK_DAYS = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationService $notificationService,
        private readonly AuditService $audit,
        private readonly EncodingCompletenessService $completeness,
        private readonly UserRepository $userRepository,
        private readonly NotificationTargetResolver $targetResolver,
        #[Autowire(service: 'monolog.logger.push')]
        private readonly LoggerInterface $logger,
        #[Autowire('%env(string:FRONTEND_URL)%')]
        private readonly string $frontendUrl,
        /** true seulement là où la commande est réellement planifiée (cron). */
        #[Autowire('%env(bool:ENCODING_REMINDER_AUTO_ENABLED)%')]
        private readonly bool $automaticRemindersScheduled = false,
        ?PrestationGroupResolver $prestationGroups = null,
    ) {
        $this->prestationGroups = $prestationGroups ?? new PrestationGroupResolver($em);
    }

    private readonly PrestationGroupResolver $prestationGroups;

    // ── Calendrier ──────────────────────────────────────────────────────────────

    /** Échéance théorique d'une relance pour cette mission (09:00 Europe/Brussels). */
    public static function dueAt(Mission $mission, EncodingReminderStage $stage): ?\DateTimeImmutable
    {
        $endAt = $mission->getEndAt();
        if ($endAt === null) {
            return null;
        }

        // Construit depuis la DATE locale de fin : setDate/setTime sur une heure locale
        // garde 09:00 heure de Bruxelles quel que soit le changement d'heure intermédiaire.
        $localEnd = $endAt->setTimezone(new \DateTimeZone(self::TIMEZONE));

        return $localEnd
            ->setTime(0, 0)
            ->modify(sprintf('+%d days', $stage->dayOffset()))
            ->setTime(self::SEND_HOUR, 0);
    }

    /**
     * Échéance à traiter maintenant pour cette mission, ou null : celle dont la fenêtre
     * [échéance, échéance + 1 jour[ contient $now.
     */
    public static function dueStage(Mission $mission, \DateTimeImmutable $now): ?EncodingReminderStage
    {
        foreach ([EncodingReminderStage::D4, EncodingReminderStage::D2, EncodingReminderStage::D1] as $stage) {
            $due = self::dueAt($mission, $stage);
            if ($due !== null && $now >= $due && $now < $due->modify('+1 day')) {
                return $stage;
            }
        }

        return null;
    }

    /** L'encodage de cette mission est-il encore attendu de l'instrumentiste ? */
    public static function isEncodingPending(Mission $mission): bool
    {
        return $mission->getInstrumentist() !== null
            && in_array($mission->getStatus(), self::PENDING_STATUSES, true)
            && $mission->getEncodingLockedAt() === null
            && $mission->getInvoiceGeneratedAt() === null;
    }

    /**
     * Missions dont une échéance est due maintenant, encore en attente d'encodage.
     * La sélection SQL est volontairement large (fin de mission dans les 5 derniers
     * jours civils) ; la décision fine (fenêtre, échéance) est faite par dueStage().
     *
     * @return list<array{mission: Mission, stage: EncodingReminderStage}>
     */
    public function findDueReminders(\DateTimeImmutable $now): array
    {
        $localNow = $now->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $today = $localNow->setTime(0, 0);

        /** @var Mission[] $missions */
        $missions = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Mission::class, 'm')
            ->where('m.instrumentist IS NOT NULL')
            ->andWhere('m.encodingLockedAt IS NULL')
            ->andWhere('m.invoiceGeneratedAt IS NULL')
            ->andWhere('m.status IN (:statuses)')
            ->andWhere('m.endAt >= :from')
            ->andWhere('m.endAt < :to')
            ->setParameter('statuses', self::PENDING_STATUSES)
            ->setParameter('from', $today->modify(sprintf('-%d days', self::LOOKBACK_DAYS)))
            ->setParameter('to', $today)
            ->orderBy('m.endAt', 'ASC')
            ->getQuery()
            ->getResult();

        $due = [];
        foreach ($missions as $mission) {
            $stage = self::dueStage($mission, $now);
            if ($stage !== null) {
                $due[] = ['mission' => $mission, 'stage' => $stage];
            }
        }

        return $this->onePerPrestation($due);
    }

    /**
     * D-156 — une prestation regroupée multi-salles est UNE journée de travail, encodée et
     * soumise en une fois : une seule relance par échéance, portée par la première mission
     * encore en attente du groupe (le lien ouvre l'encodage commun). Les missions sœurs ne
     * sont jamais relancées séparément tant que cette mission porte la relance.
     *
     * @param list<array{mission: Mission, stage: EncodingReminderStage}> $due
     * @return list<array{mission: Mission, stage: EncodingReminderStage}>
     */
    private function onePerPrestation(array $due): array
    {
        if (count($due) < 2) {
            return $due;
        }
        $groups = $this->prestationGroups->groupsFor(array_map(static fn (array $d) => $d['mission'], $due));

        $kept = [];
        $seen = [];
        foreach ($due as $entry) {
            $group = $groups[(int) $entry['mission']->getId()] ?? [$entry['mission']];
            $pending = array_values(array_filter($group, [self::class, 'isEncodingPending']));
            $anchor = $pending[0] ?? $entry['mission'];
            $key = PrestationGrouping::key($group) . '|' . $entry['stage']->value;
            if (isset($seen[$key]) || $anchor->getId() !== $entry['mission']->getId() && $this->isDueAnchorIn($anchor, $due, $entry['stage'])) {
                continue;
            }
            $seen[$key] = true;
            $kept[] = $entry;
        }

        return $kept;
    }

    /** @param list<array{mission: Mission, stage: EncodingReminderStage}> $due */
    private function isDueAnchorIn(Mission $anchor, array $due, EncodingReminderStage $stage): bool
    {
        foreach ($due as $d) {
            if ($d['mission']->getId() === $anchor->getId() && $d['stage'] === $stage) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lecture seule (--dry-run de la commande) : ce que la relance ferait, sans rien réserver
     * ni envoyer.
     *
     * @return array{dueAt: ?string, instrumentistId: ?int, situation: string, alreadySent: string, managers: string}
     */
    public function describeDue(Mission $mission, EncodingReminderStage $stage): array
    {
        $alreadySent = $this->em->getRepository(MissionEncodingReminder::class)->findOneBy(['mission' => $mission, 'stage' => $stage]) !== null;

        return [
            'dueAt'           => self::dueAt($mission, $stage)?->format('Y-m-d H:i'),
            'instrumentistId' => $mission->getInstrumentist()?->getId(),
            'situation'       => $this->completeness->situation($mission)->value,
            'alreadySent'     => $alreadySent ? 'oui' : 'non',
            'managers'        => $stage->isEscalation() ? (string) count($this->userRepository->findOperationalManagers()) : '—',
        ];
    }

    // ── Envoi ───────────────────────────────────────────────────────────────────

    /**
     * Traite UNE échéance d'UNE mission. Retourne 'sent' ou 'skipped' (déjà envoyée,
     * plus en attente, plus due). Une exception inattendue après réservation est
     * historisée sur la ligne de relance puis relancée vers la commande (isolation par
     * mission, comptée « failed »).
     *
     * @return 'sent'|'skipped'
     */
    public function processReminder(Mission $mission, EncodingReminderStage $stage, \DateTimeImmutable $now): string
    {
        // Recontrôle juste avant l'envoi : l'état peut avoir changé depuis la sélection.
        $this->em->refresh($mission);
        if (!self::isEncodingPending($mission)) {
            $this->logSkip($mission, $stage, 'no_longer_pending');
            return 'skipped';
        }
        if (self::dueStage($mission, $now) !== $stage) {
            $this->logSkip($mission, $stage, 'not_due');
            return 'skipped';
        }

        /** @var User $instrumentist */
        $instrumentist = $mission->getInstrumentist();
        $situation = $this->completeness->situation($mission);
        $missing = $this->completeness->missingForSubmission($mission);

        $reminderId = $this->reserve($mission, $stage, $instrumentist, $situation, $missing, $now);
        if ($reminderId === null) {
            $this->logSkip($mission, $stage, 'already_sent');
            return 'skipped';
        }

        try {
            [$channels, $emailReason] = $this->notifyInstrumentist($mission, $instrumentist, $stage, $situation);

            $managersNotified = null;
            if ($stage->isEscalation()) {
                $managersNotified = $this->notifyManagers($mission, $instrumentist, $situation, $missing);
            }

            $reminder = $this->em->find(MissionEncodingReminder::class, $reminderId);
            $reminder?->setInstrumentistChannels($channels)
                ->setEmailReason($emailReason)
                ->setManagersNotified($managersNotified);

            $this->audit->record($mission, $this->systemActor() ?? $instrumentist, AuditEventType::MISSION_ENCODING_AUTO_REMINDER_SENT, [
                'stage'            => $stage->value,
                'situation'        => $situation->value,
                'channels'         => $channels,
                'emailReason'      => $emailReason,
                'managersNotified' => $managersNotified,
            ]);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->recordFailure($reminderId, $e);
            throw $e;
        }

        $this->logger->info('encoding_reminder.sent', [
            'missionId' => $mission->getId(),
            'userId'    => $instrumentist->getId(),
            'stage'     => $stage->value,
            'channels'  => $channels,
        ]);

        return 'sent';
    }

    /**
     * INSERT de réservation hors UnitOfWork (une violation d'unicité ne doit jamais
     * fermer l'EntityManager au milieu du lot). Retourne l'id réservé, ou null si cette
     * échéance a déjà été réservée par une autre exécution.
     *
     * @param list<string> $missing
     */
    private function reserve(
        Mission $mission,
        EncodingReminderStage $stage,
        User $instrumentist,
        EncodingSituation $situation,
        array $missing,
        \DateTimeImmutable $now,
    ): ?int {
        $connection = $this->em->getConnection();
        try {
            $connection->insert('mission_encoding_reminder', [
                'mission_id'             => $mission->getId(),
                'instrumentist_id'       => $instrumentist->getId(),
                'stage'                  => $stage->value,
                'due_at'                 => self::dueAt($mission, $stage),
                'sent_at'                => $now,
                'situation'              => $situation->value,
                'missing_items'          => json_encode($missing, JSON_THROW_ON_ERROR),
                'instrumentist_channels' => '[]',
            ], [
                'due_at'  => 'business_datetime_immutable',
                'sent_at' => 'business_datetime_immutable',
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return (int) $connection->lastInsertId();
    }

    /**
     * Relance à l'instrumentiste, à travers NotificationDispatcher (D-128/D-131) : une seule
     * intention, l'idempotence par épisode (`encoding_reminder:{D1|D2|D4}:{missionId}`, en plus
     * de la réservation mission_encoding_reminder), chaque canal tracé.
     *
     *  - centre de notifications : toujours ;
     *  - push : vers les appareils réellement abonnés (ENCODING_REMINDER : push par défaut,
     *    non configurable) — « livré » = accepté par le transport, jamais un simple abonnement ;
     *  - e-mail : J+1/J+2 en secours d'un push non livré (FALLBACK_WHEN_PUSH_NOT_SENT),
     *    J+4 toujours (MANDATORY).
     *
     * @return array{0: list<string>, 1: ?string} canaux réellement utilisés, raison de l'e-mail
     */
    private function notifyInstrumentist(Mission $mission, User $instrumentist, EncodingReminderStage $stage, EncodingSituation $situation): array
    {
        $missionDate = $this->missionDate($mission);
        $content = self::instrumentistContent($stage, $missionDate);
        $path = $this->targetResolver->resolve(NotificationType::ENCODING_REMINDER, $mission, $instrumentist)
            ?? sprintf('/app/i/missions/%d/encoding', $mission->getId());

        $dispatch = $this->dispatcher->dispatch(new NotificationIntent(
            recipient: $instrumentist,
            type: NotificationType::ENCODING_REMINDER,
            inAppPayload: [
                'missionId'   => $mission->getId(),
                'missionDate' => $missionDate,
                'stage'       => $stage->value,
                'situation'   => $situation->value,
                'title'       => $content['title'],
                'body'        => $content['push'],
            ],
            mission: $mission,
            email: new EmailContent($content['subject'], sprintf('emails/encoding_reminder_%s.html.twig', strtolower($stage->value)), [
                'firstname'    => $instrumentist->getFirstname(),
                'missionDate'  => $missionDate,
                'missionUrl'   => $this->frontendUrl . $path,
                'hoursMissing' => $situation !== EncodingSituation::NOT_SUBMITTED,
            ]),
            push: new PushContent($content['title'], $content['push'], [
                'missionId'        => $mission->getId(),
                'url'              => $path,
                'notificationType' => NotificationType::ENCODING_REMINDER->value,
            ]),
            episodeRef: sprintf('encoding_reminder:%s:%d', $stage->value, $mission->getId()),
            emailPolicy: $stage->isEscalation() ? NotificationEmailPolicy::MANDATORY : NotificationEmailPolicy::FALLBACK_WHEN_PUSH_NOT_SENT,
        ));

        $channels = self::channelsUsed($dispatch);
        $emailReason = null;
        if (in_array(MissionEncodingReminder::CHANNEL_EMAIL, $channels, true)) {
            $email = self::delivery($dispatch, OutboundNotificationChannel::EMAIL);
            $emailReason = $stage->isEscalation()
                ? 'MANDATORY'
                : 'PUSH_' . ($email?->getFallbackReason()?->value ?? self::delivery($dispatch, OutboundNotificationChannel::PUSH)?->getSkipReason()?->value ?? 'NOT_SENT');
        }

        return [$channels, $emailReason];
    }

    /**
     * J+4 — chaque manager/admin actif : centre de notifications + e-mail obligatoire, à
     * travers le dispatcher (épisode `encoding_escalation:{missionId}`). Isolation par
     * destinataire : un échec n'empêche jamais les autres. Retourne le nombre de managers
     * effectivement alertés.
     *
     * @param list<string> $missing
     */
    private function notifyManagers(Mission $mission, User $instrumentist, EncodingSituation $situation, array $missing): int
    {
        $missionDate = $this->missionDate($mission);
        $instrumentistName = self::displayName($instrumentist);
        $surgeonName = $mission->getSurgeon() !== null ? self::displayName($mission->getSurgeon()) : null;
        $missingLabels = array_map(EncodingCompletenessService::label(...), $missing);
        $previous = $this->previousReminderDates($mission);

        $notified = 0;
        // D-144 — destinataires opérationnels : managers/admins actifs, hors comptes techniques.
        foreach ($this->userRepository->findOperationalManagers() as $manager) {
            try {
                $path = $this->targetResolver->resolve(NotificationType::ENCODING_ESCALATION, $mission, $manager)
                    ?? sprintf('/app/m/billing/encodings?missionId=%d', $mission->getId());
                $dispatch = $this->dispatcher->dispatch(new NotificationIntent(
                    recipient: $manager,
                    type: NotificationType::ENCODING_ESCALATION,
                    inAppPayload: [
                        'missionId'         => $mission->getId(),
                        'missionDate'       => $missionDate,
                        'instrumentistName' => $instrumentistName,
                        'surgeonName'       => $surgeonName,
                        'situation'         => $situation->value,
                        'situationLabel'    => $situation->label(),
                        'missing'           => $missingLabels,
                        'previousReminders' => $previous,
                    ],
                    mission: $mission,
                    email: new EmailContent('Encodage en attente depuis 4 jours', 'emails/encoding_escalation_manager.html.twig', [
                        'firstname'         => $manager->getFirstname(),
                        'instrumentistName' => $instrumentistName,
                        'missionDate'       => $missionDate,
                        'surgeonName'       => $surgeonName,
                        'situationLabel'    => $situation->label(),
                        'missing'           => $missingLabels,
                        'previousReminders' => $previous,
                        'trackingUrl'       => $this->frontendUrl . $path,
                        'missionUrl'        => $this->frontendUrl . $path,
                    ]),
                    episodeRef: sprintf('encoding_escalation:%d', $mission->getId()),
                    emailPolicy: NotificationEmailPolicy::MANDATORY,
                ));
                if (self::channelsUsed($dispatch) !== []) {
                    ++$notified;
                }
            } catch (\Throwable $e) {
                $this->logger->error('encoding_reminder.manager_failed', [
                    'missionId' => $mission->getId(),
                    'managerId' => $manager->getId(),
                    'error'     => $e::class,
                ]);
            }
        }

        return $notified;
    }

    /**
     * Textes validés (D-143 §LOT 4). Ton chaleureux J+1, explicatif J+2, dernier rappel
     * J+4 — jamais culpabilisant, jamais une donnée patient.
     *
     * @return array{title: string, push: string, subject: string}
     */
    public static function instrumentistContent(EncodingReminderStage $stage, ?string $missionDate): array
    {
        $date = $missionDate ?? 'récente';

        return match ($stage) {
            EncodingReminderStage::D1 => [
                'title'   => 'Gardez votre journée en mémoire !',
                'push'    => 'Les journées au bloc sont bien remplies ! Pensez à compléter votre encodage pendant que les détails sont encore frais dans votre mémoire. Vous pouvez reprendre votre brouillon à tout moment.',
                'subject' => 'Gardez votre journée en mémoire !',
            ],
            EncodingReminderStage::D2 => [
                'title'   => 'Votre encodage attend sa dernière touche',
                'push'    => sprintf('Un petit rappel pour finaliser votre mission du %s. Vos heures prestées et les informations nécessaires à votre prestation permettent sa validation et sa rémunération. Merci de prendre quelques instants pour terminer votre encodage !', $date),
                'subject' => 'Un petit rappel pour votre mission SurgicalHub',
            ],
            EncodingReminderStage::D4 => [
                'title'   => 'Votre mission est toujours à finaliser',
                'push'    => sprintf('Il reste quelques informations à compléter pour permettre la validation et la rémunération de votre prestation du %s. Notre équipe de coordination a également été informée et peut vous aider en cas de difficulté.', $date),
                'subject' => 'Votre mission est toujours à finaliser',
            ],
        };
    }

    // ── Relance manuelle (D-120, H5a, D-143) ────────────────────────────────────

    /** Délai minimal entre deux relances manuelles d'une même mission (anti double clic / répétition). */
    public const MANUAL_COOLDOWN_MINUTES = 10;

    /**
     * D-120 — relance manuelle depuis le cockpit "Suivi des encodages". Ne touche jamais
     * l'historique des relances automatiques, ni le statut, ni le brouillon.
     *
     * H5a (décision validée) — action explicite d'un manager : e-mail OBLIGATOIRE
     * (NotificationEmailPolicy::MANDATORY), push selon les appareils ; $batchId = l'action du
     * manager (une requête rejouée avec le même lot n'envoie jamais une seconde relance ;
     * absent → la corrélation de la requête).
     * D-143 — même type ENCODING_REMINDER que les relances automatiques (centre de
     * notifications, lien direct vers l'encodage) ; une nouvelle relance de la même mission
     * est refusée pendant MANUAL_COOLDOWN_MINUTES (le contrôleur traduit en 409 explicite).
     *
     * @return 'push+email'|'push'|'email'|'none' les canaux réellement utilisés
     */
    public function sendManualReminder(Mission $mission, User $actor, ?string $batchId = null): string
    {
        $instrumentist = $mission->getInstrumentist();
        if (!$instrumentist instanceof User) {
            throw new \LogicException('Cannot remind a mission with no assigned instrumentist');
        }

        $title = 'Encodage à finaliser';
        $body = "Un manager vous rappelle de finaliser l'encodage de cette mission.";
        $path = sprintf('/app/i/missions/%d/encoding', $mission->getId());

        $dispatch = $this->dispatcher->dispatch(new NotificationIntent(
            recipient: $instrumentist,
            type: NotificationType::ENCODING_REMINDER,
            inAppPayload: [
                'missionId'   => $mission->getId(),
                'missionDate' => $this->missionDate($mission),
                'stage'       => 'MANUAL',
                'title'       => $title,
                'body'        => $body,
            ],
            mission: $mission,
            email: $this->notificationService->missionEncodingReminderEmail($mission, $instrumentist),
            push: new PushContent($title, $body, ['missionId' => $mission->getId(), 'url' => $path]),
            episodeRef: $batchId !== null ? sprintf('encoding_reminder:manual:%d:%s', $mission->getId(), $batchId) : null,
            emailPolicy: NotificationEmailPolicy::MANDATORY,
        ));

        $used = self::channelsUsed($dispatch);
        $channel = match (true) {
            in_array(MissionEncodingReminder::CHANNEL_PUSH, $used, true) && in_array(MissionEncodingReminder::CHANNEL_EMAIL, $used, true) => 'push+email',
            in_array(MissionEncodingReminder::CHANNEL_PUSH, $used, true) => 'push',
            in_array(MissionEncodingReminder::CHANNEL_EMAIL, $used, true) => 'email',
            default => 'none',
        };

        $this->audit->record($mission, $actor, AuditEventType::MISSION_ENCODING_MANUAL_REMINDER_SENT, [
            'actorId'   => $actor->getId(),
            'actorName' => self::displayName($actor),
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
     * Canaux réellement utilisés pour une intention, lus sur ses livraisons tracées :
     * in-app créé, push accepté par le transport, e-mail en file ou envoyé.
     *
     * @return list<string>
     */
    private static function channelsUsed(NotificationDispatch $dispatch): array
    {
        $used = [];
        foreach ($dispatch->getDeliveries() as $delivery) {
            $status = $delivery->getStatus();
            $used[] = match ($delivery->getChannel()) {
                OutboundNotificationChannel::IN_APP => $status === OutboundNotificationStatus::SENT ? MissionEncodingReminder::CHANNEL_IN_APP : null,
                OutboundNotificationChannel::PUSH   => $status === OutboundNotificationStatus::SENT ? MissionEncodingReminder::CHANNEL_PUSH : null,
                OutboundNotificationChannel::EMAIL  => in_array($status, [OutboundNotificationStatus::QUEUED, OutboundNotificationStatus::SENT], true) ? MissionEncodingReminder::CHANNEL_EMAIL : null,
                default => null,
            };
        }

        $order = [MissionEncodingReminder::CHANNEL_IN_APP, MissionEncodingReminder::CHANNEL_PUSH, MissionEncodingReminder::CHANNEL_EMAIL];

        return array_values(array_intersect($order, $used));
    }

    private static function delivery(NotificationDispatch $dispatch, OutboundNotificationChannel $channel): ?OutboundNotification
    {
        foreach ($dispatch->getDeliveries() as $delivery) {
            if ($delivery->getChannel() === $channel) {
                return $delivery;
            }
        }

        return null;
    }

    /** Une relance manuelle a-t-elle déjà été envoyée pour cette mission il y a moins de MANUAL_COOLDOWN_MINUTES ? */
    public function isManualReminderOnCooldown(Mission $mission, ?\DateTimeImmutable $now = null): bool
    {
        $last = $this->lastManualReminder($mission);
        if ($last === null || $last['at'] === null) {
            return false;
        }

        $at = new \DateTimeImmutable($last['at']);

        return $at > ($now ?? new \DateTimeImmutable())->modify(sprintf('-%d minutes', self::MANUAL_COOLDOWN_MINUTES));
    }

    // ── Lecture (suivi des encodages) ───────────────────────────────────────────

    /**
     * D-120/D-143 — prochaine relance automatique de cette mission (ATOM), ou null : jamais
     * annoncée si la planification n'est pas active (ENCODING_REMINDER_AUTO_ENABLED=0), si
     * l'encodage n'est plus attendu, ou si toutes les échéances sont passées/envoyées.
     */
    public function nextAutomaticReminderAt(Mission $mission, ?\DateTimeImmutable $now = null): ?string
    {
        if (!$this->automaticRemindersScheduled || !self::isEncodingPending($mission)) {
            return null;
        }

        $now ??= new \DateTimeImmutable();
        $sent = array_map(static fn (MissionEncodingReminder $r) => $r->getStage(), $this->remindersFor($mission));

        foreach ([EncodingReminderStage::D1, EncodingReminderStage::D2, EncodingReminderStage::D4] as $stage) {
            if (in_array($stage, $sent, true)) {
                continue;
            }
            $due = self::dueAt($mission, $stage);
            if ($due !== null && $due->modify('+1 day') > $now) {
                return $due->format(\DateTimeInterface::ATOM);
            }
        }

        return null;
    }

    /** @return list<MissionEncodingReminder> relances automatiques déjà envoyées, par échéance */
    public function remindersFor(Mission $mission): array
    {
        return $this->em->getRepository(MissionEncodingReminder::class)
            ->findBy(['mission' => $mission], ['dueAt' => 'ASC']);
    }

    /** @return list<array{stage: string, sentAt: ?string, channels: list<string>}> */
    public function reminderHistory(Mission $mission): array
    {
        return array_map(static fn (MissionEncodingReminder $r) => [
            'stage'    => $r->getStage()?->value,
            'sentAt'   => $r->getSentAt()?->format(\DateTimeInterface::ATOM),
            'channels' => $r->getInstrumentistChannels(),
            'managersNotified' => $r->getManagersNotified(),
        ], $this->remindersFor($mission));
    }

    /**
     * D-120 — dernière relance manuelle journalisée pour cette mission, ou null. Lu depuis
     * AuditEvent — jamais un nouveau champ dupliqué sur Mission.
     *
     * @return array{at: ?string, byName: string, channel: ?string}|null
     */
    public function lastManualReminder(Mission $mission): ?array
    {
        /** @var AuditEvent|null $event */
        $event = $this->em->createQuery(
            'SELECT a, actor FROM App\Entity\AuditEvent a
             JOIN a.actor actor
             WHERE a.mission = :mission AND a.eventType = :type
             ORDER BY a.createdAt DESC, a.id DESC'
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
            'channel' => isset($event->getPayload()['channel']) ? (string) $event->getPayload()['channel'] : null,
        ];
    }

    // ── Utilitaires ─────────────────────────────────────────────────────────────

    public static function outboundType(EncodingReminderStage $stage): string
    {
        return 'ENCODING_REMINDER_' . $stage->value;
    }

    /** @return list<string> dates (d/m/Y) des relances automatiques déjà envoyées + manuelles */
    private function previousReminderDates(Mission $mission): array
    {
        $dates = [];
        foreach ($this->remindersFor($mission) as $reminder) {
            if ($reminder->getStage() !== EncodingReminderStage::D4 && $reminder->getSentAt() !== null) {
                $dates[] = sprintf('J+%d le %s', $reminder->getStage()->dayOffset(), $reminder->getSentAt()->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('d/m/Y'));
            }
        }
        $manual = $this->lastManualReminder($mission);
        if ($manual !== null && $manual['at'] !== null) {
            $dates[] = sprintf('relance manuelle le %s', (new \DateTimeImmutable($manual['at']))->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('d/m/Y'));
        }

        return $dates;
    }

    private function missionDate(Mission $mission): ?string
    {
        return $mission->getStartAt()?->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('d/m/Y');
    }

    private function systemActor(): ?User
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => 'system@surgicalhub.internal']);
    }

    private function recordFailure(int $reminderId, \Throwable $e): void
    {
        try {
            $this->em->getConnection()->update(
                'mission_encoding_reminder',
                ['failure' => substr((new \ReflectionClass($e))->getShortName(), 0, 64)],
                ['id' => $reminderId],
            );
        } catch (\Throwable) {
            // best-effort : la trace principale reste le log de la commande.
        }
    }

    private function logSkip(Mission $mission, EncodingReminderStage $stage, string $reason): void
    {
        $this->logger->info('encoding_reminder.skipped', [
            'missionId' => $mission->getId(),
            'stage'     => $stage->value,
            'reason'    => $reason,
        ]);
    }

    private static function displayName(User $user): string
    {
        return trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? '')) ?: (string) $user->getEmail();
    }
}
