<?php

namespace App\MessageHandler;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Message\EncodingAnomalyReportCreatedMessage;
use App\Service\NotificationTargetResolver;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Notification\PushContent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Lot 6 (D-100) — prévient les managers/admins actifs qu'un chirurgien vient de
 * signaler une anomalie d'encodage (D-143 : l'instrumentiste concernée aussi). Même famille que
 * SurgeonMissionRequestCreatedMessageHandler (D-099) : in-app + push uniquement,
 * jamais d'email, failure isolation par destinataire.
 *
 * H5b (D-132) — through NotificationDispatcher (in-app + push per preferences, no email
 * content — NOT_APPLICABLE), every channel traced, idempotent per report and manager.
 */
#[AsMessageHandler]
final class EncodingAnomalyReportCreatedMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationTargetResolver $targetResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(EncodingAnomalyReportCreatedMessage $message): void
    {
        $mission = $this->em->find(Mission::class, $message->missionId);
        if (!$mission instanceof Mission) {
            $this->logger->warning('EncodingAnomalyReportCreated: mission not found', [
                'missionId' => $message->missionId,
            ]);
            return;
        }

        foreach ($message->recipientUserIds as $managerId) {
            $manager = $this->em->find(User::class, $managerId);
            if (!$manager instanceof User) {
                continue;
            }
            $this->notifyManager($manager, $mission, $message);
        }

        $this->notifyInstrumentist($mission, $message);
    }

    /**
     * D-143 — l'instrumentiste concernée est prévenue qu'un point est à vérifier sur son
     * encodage. Jamais le commentaire libre du chirurgien (il peut contenir des éléments
     * cliniques) : seulement la catégorie et la date ; le suivi reste chez les managers.
     * Via NotificationDispatcher (in-app + push selon préférences, pas d'e-mail),
     * idempotent par signalement.
     */
    private function notifyInstrumentist(Mission $mission, EncodingAnomalyReportCreatedMessage $message): void
    {
        $instrumentist = $mission->getInstrumentist();
        if (!$instrumentist instanceof User) {
            return;
        }

        try {
            $type = NotificationType::ENCODING_ANOMALY_INSTRUMENTIST;
            $missionDate = $mission->getStartAt()?->format('d/m/Y');
            $this->dispatcher->dispatch(new NotificationIntent(
                recipient: $instrumentist,
                type: $type,
                inAppPayload: [
                    'reportId'    => $message->reportId,
                    'missionId'   => $mission->getId(),
                    'missionDate' => $missionDate,
                    'type'        => $message->type,
                ],
                mission: $mission,
                push: new PushContent(
                    'Point à vérifier sur votre encodage',
                    sprintf('Le chirurgien a signalé un point à vérifier sur votre mission du %s. La coordination reviendra vers vous si nécessaire.', $missionDate ?? '—'),
                    ['missionId' => $mission->getId(), 'url' => $this->targetResolver->resolve($type, $mission, $instrumentist)],
                ),
                episodeRef: 'encoding_anomaly_instrumentist:' . $message->reportId,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('EncodingAnomalyReportCreated: instrumentist notification failed', [
                'reportId'        => $message->reportId,
                'instrumentistId' => $instrumentist->getId(),
                'error'           => $e->getMessage(),
            ]);
        }
    }

    private function notifyManager(User $manager, Mission $mission, EncodingAnomalyReportCreatedMessage $message): void
    {
        try {
            $type = NotificationType::ENCODING_ANOMALY_REPORTED;
            $this->dispatcher->dispatch(new NotificationIntent(
                recipient: $manager,
                type: $type,
                inAppPayload: [
                    'reportId'    => $message->reportId,
                    'missionId'   => $mission->getId(),
                    'surgeonName' => $message->surgeonName,
                    'type'        => $message->type,
                ],
                mission: $mission,
                push: new PushContent(
                    'Anomalie d\'encodage signalée',
                    sprintf('Dr %s a signalé une anomalie sur l\'encodage d\'une mission.', $message->surgeonName),
                    ['missionId' => $mission->getId(), 'url' => $this->targetResolver->resolve($type, $mission, $manager)],
                ),
                episodeRef: 'encoding_anomaly_reported:' . $message->reportId,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('EncodingAnomalyReportCreated: manager notification failed', [
                'reportId' => $message->reportId,
                'managerId' => $manager->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
