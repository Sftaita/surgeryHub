<?php

namespace App\MessageHandler;

use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\OutboundNotificationStatus;
use App\Enum\PublicationChannel;
use App\Message\SurgeonMissionRequestDecidedMessage;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use App\Service\NotificationService;
use App\Service\NotificationTargetResolver;
use App\Service\OutboundNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Lot 5 (D-099) — prévient le chirurgien de l'issue de sa SurgeonMissionRequest une
 * fois le manager passé par MissionsListPage (onglet "Demandes chirurgien"). Push
 * d'abord (si la préférence du destinataire l'autorise et qu'une souscription
 * existe), repli email uniquement si le push n'a pas été réellement livrable — même
 * orchestration que CatalogueRequestProcessedMessageHandler (D-093).
 *
 * Failure isolation : toute l'opération est dans un seul try/catch — un seul
 * destinataire, un seul événement, rien d'autre à isoler entre eux.
 */
#[AsMessageHandler]
final class SurgeonMissionRequestDecidedMessageHandler
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

    public function __invoke(SurgeonMissionRequestDecidedMessage $message): void
    {
        $surgeon = $this->em->find(User::class, $message->surgeonId);
        if (!$surgeon instanceof User) {
            $this->logger->warning('SurgeonMissionRequestDecided: surgeon not found', [
                'surgeonId' => $message->surgeonId,
            ]);
            return;
        }

        $mission = $message->missionId !== null ? $this->em->find(Mission::class, $message->missionId) : null;

        try {
            $type = $message->accepted ? NotificationType::SURGEON_MISSION_REQUEST_ACCEPTED : NotificationType::SURGEON_MISSION_REQUEST_REJECTED;
            $channels = $this->resolveChannelsSafely($surgeon, $type);

            if ($channels->inApp) {
                $evt = (new NotificationEvent())
                    ->setUser($surgeon)
                    ->setMission($mission)
                    ->setEventType($type->value)
                    ->setChannel(PublicationChannel::IN_APP)
                    ->setSentAt(new \DateTimeImmutable())
                    ->setPayload([
                        'requestId'      => $message->requestId,
                        'missionId'      => $message->missionId,
                        'siteName'       => $message->siteName,
                        'startAt'        => $message->startAt,
                        'reviewComment'  => $message->reviewComment,
                    ]);
                $this->em->persist($evt);
                $this->em->flush();
            }

            if ($channels->push) {
                $title = $message->accepted ? 'Demande de mission acceptée' : 'Demande de mission refusée';
                $body = $message->accepted
                    ? sprintf('Votre demande de mission du %s à %s a été acceptée.', (new \DateTimeImmutable($message->startAt))->format('d/m/Y'), $message->siteName)
                    : sprintf('Votre demande de mission du %s à %s n\'a pas été retenue.', (new \DateTimeImmutable($message->startAt))->format('d/m/Y'), $message->siteName);
                $data = [
                    'requestId' => $message->requestId,
                    'missionId' => $message->missionId,
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
                    $this->sendEmail($message, $mission, $surgeon, $pushNotification, OutboundNotificationService::fallbackReasonFor($pushNotification));
                }
            } elseif ($channels->email) {
                // Push désactivé par préférence (jamais tenté) — repli email direct.
                $this->sendEmail($message, $mission, $surgeon);
            }
        } catch (\Throwable $e) {
            $this->logger->error('SurgeonMissionRequestDecided: notification failed', [
                'requestId' => $message->requestId,
                'surgeonId' => $message->surgeonId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendEmail(
        SurgeonMissionRequestDecidedMessage $message,
        ?Mission $mission,
        User $surgeon,
        ?\App\Entity\OutboundNotification $fallbackOf = null,
        ?\App\Enum\OutboundNotificationFallbackReason $fallbackReason = null,
    ): void {
        if ($message->accepted && $mission !== null) {
            $this->notificationService->surgeonMissionRequestAcceptedNotifySurgeon(
                $mission, $surgeon, $fallbackOf, $fallbackReason,
            );
        } elseif (!$message->accepted) {
            $this->notificationService->surgeonMissionRequestRejectedNotifySurgeon(
                $surgeon,
                $message->siteName,
                new \DateTimeImmutable($message->startAt),
                $message->reviewComment ?? '',
                $fallbackOf,
                $fallbackReason,
            );
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
