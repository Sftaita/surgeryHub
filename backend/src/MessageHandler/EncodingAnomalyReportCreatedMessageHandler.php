<?php

namespace App\MessageHandler;

use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\PublicationChannel;
use App\Message\EncodingAnomalyReportCreatedMessage;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use App\Service\NotificationTargetResolver;
use App\Service\OutboundNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Lot 6 (D-100) — prévient les managers/admins actifs qu'un chirurgien vient de
 * signaler une anomalie d'encodage. Même famille que
 * SurgeonMissionRequestCreatedMessageHandler (D-099) : in-app + push uniquement,
 * jamais d'email, failure isolation par destinataire.
 */
#[AsMessageHandler]
final class EncodingAnomalyReportCreatedMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OutboundNotificationService $outboundNotificationService,
        private readonly NotificationPreferenceResolver $preferenceResolver,
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
    }

    private function notifyManager(User $manager, Mission $mission, EncodingAnomalyReportCreatedMessage $message): void
    {
        try {
            $channels = $this->resolveChannelsSafely($manager, NotificationType::ENCODING_ANOMALY_REPORTED);

            if ($channels->inApp) {
                $evt = (new NotificationEvent())
                    ->setUser($manager)
                    ->setMission($mission)
                    ->setEventType(NotificationType::ENCODING_ANOMALY_REPORTED->value)
                    ->setChannel(PublicationChannel::IN_APP)
                    ->setSentAt(new \DateTimeImmutable())
                    ->setPayload([
                        'reportId'    => $message->reportId,
                        'missionId'   => $mission->getId(),
                        'surgeonName' => $message->surgeonName,
                        'type'        => $message->type,
                    ]);
                $this->em->persist($evt);
                $this->em->flush();
            }

            if ($channels->push) {
                $title = 'Anomalie d\'encodage signalée';
                $body = sprintf('Dr %s a signalé une anomalie sur l\'encodage d\'une mission.', $message->surgeonName);
                $data = [
                    'missionId' => $mission->getId(),
                    'url' => $this->targetResolver->resolve(NotificationType::ENCODING_ANOMALY_REPORTED, $mission, $manager),
                ];

                // Pas de repli email en cas d'échec — voir docblock de classe.
                $this->outboundNotificationService->recordPushSend(
                    $manager,
                    NotificationType::ENCODING_ANOMALY_REPORTED->value,
                    $title,
                    $body,
                    $data,
                    $mission,
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('EncodingAnomalyReportCreated: manager notification failed', [
                'reportId' => $message->reportId,
                'managerId' => $manager->getId(),
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
