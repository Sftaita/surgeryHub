<?php

namespace App\MessageHandler;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\NotificationEmailPolicy;
use App\Enum\NotificationType;
use App\Message\EncodingAnomalyReportResolvedMessage;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Notification\PushContent;
use App\Service\NotificationService;
use App\Service\NotificationTargetResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Lot 6 (D-100) — prévient le chirurgien que son signalement d'anomalie d'encodage a été
 * résolu. H5a — via NotificationDispatcher : in-app, push d'abord, repli email (politique
 * FALLBACK_WHEN_PUSH_NOT_SENT), chaque canal tracé, idempotent par signalement.
 */
#[AsMessageHandler]
final class EncodingAnomalyReportResolvedMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationService $notificationService,
        private readonly NotificationTargetResolver $targetResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(EncodingAnomalyReportResolvedMessage $message): void
    {
        $surgeon = $this->em->find(User::class, $message->surgeonId);
        if (!$surgeon instanceof User) {
            $this->logger->warning('EncodingAnomalyReportResolved: surgeon not found', [
                'surgeonId' => $message->surgeonId,
            ]);
            return;
        }

        $mission = $this->em->find(Mission::class, $message->missionId);
        if (!$mission instanceof Mission) {
            $this->logger->warning('EncodingAnomalyReportResolved: mission not found', [
                'missionId' => $message->missionId,
            ]);
            return;
        }

        try {
            $type = NotificationType::ENCODING_ANOMALY_RESOLVED;
            $this->dispatcher->dispatch(new NotificationIntent(
                recipient: $surgeon,
                type: $type,
                inAppPayload: [
                    'reportId'          => $message->reportId,
                    'missionId'         => $mission->getId(),
                    'resolutionComment' => $message->resolutionComment,
                ],
                mission: $mission,
                email: $this->notificationService->encodingAnomalyResolvedEmail($mission, $surgeon, $message->resolutionComment),
                push: new PushContent('Signalement traité', 'Votre signalement sur l\'encodage a été traité.', [
                    'missionId' => $mission->getId(),
                    'url' => $this->targetResolver->resolve($type, $mission, $surgeon),
                ]),
                episodeRef: 'encoding_anomaly_report:' . $message->reportId,
                emailPolicy: NotificationEmailPolicy::FALLBACK_WHEN_PUSH_NOT_SENT,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('EncodingAnomalyReportResolved: notification failed', [
                'reportId' => $message->reportId,
                'surgeonId' => $message->surgeonId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
