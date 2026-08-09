<?php

namespace App\MessageHandler;

use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\OutboundNotificationStatus;
use App\Enum\PublicationChannel;
use App\Message\EncodingAnomalyReportResolvedMessage;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use App\Service\NotificationService;
use App\Service\NotificationTargetResolver;
use App\Service\OutboundNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Lot 6 (D-100) — prévient le chirurgien que son signalement d'anomalie d'encodage a
 * été résolu. Même orchestration que SurgeonMissionRequestDecidedMessageHandler
 * (push d'abord, repli email uniquement si non livrable).
 */
#[AsMessageHandler]
final class EncodingAnomalyReportResolvedMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OutboundNotificationService $outboundNotificationService,
        private readonly NotificationService $notificationService,
        private readonly NotificationPreferenceResolver $preferenceResolver,
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
            $channels = $this->resolveChannelsSafely($surgeon, $type);

            if ($channels->inApp) {
                $evt = (new NotificationEvent())
                    ->setUser($surgeon)
                    ->setMission($mission)
                    ->setEventType($type->value)
                    ->setChannel(PublicationChannel::IN_APP)
                    ->setSentAt(new \DateTimeImmutable())
                    ->setPayload([
                        'reportId'          => $message->reportId,
                        'missionId'         => $mission->getId(),
                        'resolutionComment' => $message->resolutionComment,
                    ]);
                $this->em->persist($evt);
                $this->em->flush();
            }

            if ($channels->push) {
                $title = 'Signalement traité';
                $body = 'Votre signalement sur l\'encodage a été traité.';
                $data = [
                    'missionId' => $mission->getId(),
                    'url' => $this->targetResolver->resolve($type, $mission, $surgeon),
                ];

                $pushNotification = $this->outboundNotificationService->recordPushSend(
                    $surgeon,
                    $type->value,
                    $title,
                    $body,
                    $data,
                    $mission,
                );

                if ($pushNotification->getStatus() !== OutboundNotificationStatus::SENT && $channels->email) {
                    $this->notificationService->encodingAnomalyResolvedNotifySurgeon(
                        $mission, $surgeon, $message->resolutionComment,
                        $pushNotification, OutboundNotificationService::fallbackReasonFor($pushNotification),
                    );
                }
            } elseif ($channels->email) {
                $this->notificationService->encodingAnomalyResolvedNotifySurgeon($mission, $surgeon, $message->resolutionComment);
            }
        } catch (\Throwable $e) {
            $this->logger->error('EncodingAnomalyReportResolved: notification failed', [
                'reportId' => $message->reportId,
                'surgeonId' => $message->surgeonId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveChannelsSafely(User $user, NotificationType $type): NotificationChannels
    {
        try {
            return $this->preferenceResolver->resolve($user, $type);
        } catch (\Throwable) {
            return new NotificationChannels(inApp: true, email: false, push: false);
        }
    }
}
