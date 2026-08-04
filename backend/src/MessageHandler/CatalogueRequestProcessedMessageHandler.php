<?php

namespace App\MessageHandler;

use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\CatalogueRequestKind;
use App\Enum\NotificationType;
use App\Enum\OutboundNotificationStatus;
use App\Enum\PublicationChannel;
use App\Message\CatalogueRequestProcessedMessage;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use App\Service\NotificationService;
use App\Service\NotificationTargetResolver;
use App\Service\OutboundNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * D-093 — prévient l'instrumentiste de l'issue d'une proposition catalogue
 * (InterventionTypeRequest ou MaterialItemRequest) qu'il a soumise pendant l'encodage,
 * une fois le manager passé par CatalogueRequestsPage. Push d'abord (si la préférence
 * du destinataire l'autorise et qu'une souscription existe), repli email uniquement si
 * le push n'a pas été réellement livrable — même orchestration que
 * MissionPublishedMessageHandler::notifySurgeon() (D-083/D-084).
 *
 * Failure isolation : toute l'opération est dans un seul try/catch (contrairement à
 * MissionPublishedMessageHandler qui isole plusieurs destinataires indépendants) — ici
 * un seul destinataire, un seul événement, rien d'autre à isoler entre eux.
 */
#[AsMessageHandler]
final class CatalogueRequestProcessedMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OutboundNotificationService $outboundNotificationService,
        private readonly NotificationService $notificationService,
        private readonly NotificationPreferenceResolver $preferenceResolver,
        private readonly NotificationTargetResolver $targetResolver,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(CatalogueRequestProcessedMessage $message): void
    {
        $recipient = $this->em->find(User::class, $message->recipientUserId);
        if (!$recipient instanceof User) {
            $this->logger->warning('CatalogueRequestProcessed: recipient not found', [
                'recipientUserId' => $message->recipientUserId,
            ]);
            return;
        }

        $mission = $this->em->find(Mission::class, $message->missionId);
        if (!$mission instanceof Mission) {
            $this->logger->warning('CatalogueRequestProcessed: mission not found', [
                'missionId' => $message->missionId,
            ]);
            return;
        }

        try {
            $type = $message->accepted ? NotificationType::CATALOGUE_REQUEST_RESOLVED : NotificationType::CATALOGUE_REQUEST_IGNORED;
            $kindLabel = $message->kind === CatalogueRequestKind::INTERVENTION_TYPE ? 'intervention' : 'matériel';
            $channels = $this->resolveChannelsSafely($recipient, $type);

            if ($channels->inApp) {
                $evt = (new NotificationEvent())
                    ->setUser($recipient)
                    ->setMission($mission)
                    ->setEventType($type->value)
                    ->setChannel(PublicationChannel::IN_APP)
                    ->setSentAt(new \DateTimeImmutable())
                    ->setPayload([
                        'missionId' => $mission->getId(),
                        'requestId' => $message->requestId,
                        'label'     => $message->label,
                        'kind'      => $message->kind->value,
                    ]);
                $this->em->persist($evt);
                $this->em->flush();
            }

            if ($channels->push) {
                $title = $message->accepted ? 'Proposition acceptée' : 'Proposition non retenue';
                $body = $message->accepted
                    ? sprintf('Votre proposition de %s « %s » a été acceptée et ajoutée au catalogue.', $kindLabel, $message->label)
                    : sprintf('Votre proposition de %s « %s » n\'a pas été retenue.', $kindLabel, $message->label);
                $data = [
                    'missionId' => $mission->getId(),
                    'url' => $this->targetResolver->resolve($type, $mission, $recipient),
                ];

                $pushNotification = $this->outboundNotificationService->recordPushSend(
                    $recipient,
                    $type->value,
                    $title,
                    $body,
                    $data,
                    $mission,
                );

                if ($pushNotification->getStatus() !== OutboundNotificationStatus::SENT && $channels->email) {
                    $this->sendEmail($message, $mission, $recipient, $kindLabel, $pushNotification, OutboundNotificationService::fallbackReasonFor($pushNotification));
                }
            } elseif ($channels->email) {
                // Push désactivé par préférence (jamais tenté) — repli email direct.
                $this->sendEmail($message, $mission, $recipient, $kindLabel);
            }
        } catch (\Throwable $e) {
            $this->logger->error('CatalogueRequestProcessed: notification failed', [
                'requestId' => $message->requestId,
                'recipientUserId' => $message->recipientUserId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendEmail(
        CatalogueRequestProcessedMessage $message,
        Mission $mission,
        User $recipient,
        string $kindLabel,
        ?\App\Entity\OutboundNotification $fallbackOf = null,
        ?\App\Enum\OutboundNotificationFallbackReason $fallbackReason = null,
    ): void {
        if ($message->accepted) {
            $this->notificationService->catalogueRequestResolvedNotifyInstrumentist(
                $mission, $recipient, $message->label, $kindLabel, $fallbackOf, $fallbackReason,
            );
        } else {
            $this->notificationService->catalogueRequestIgnoredNotifyInstrumentist(
                $mission, $recipient, $message->label, $kindLabel, $fallbackOf, $fallbackReason,
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
