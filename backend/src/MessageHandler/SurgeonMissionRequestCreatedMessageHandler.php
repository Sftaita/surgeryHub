<?php

namespace App\MessageHandler;

use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\PublicationChannel;
use App\Message\SurgeonMissionRequestCreatedMessage;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use App\Service\NotificationTargetResolver;
use App\Service\OutboundNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Lot 5 (D-099) — prévient les managers/admins actifs qu'un chirurgien vient de
 * demander une mission. Même ciblage que CatalogueRequestCreatedMessageHandler
 * (destinataires figés sur le message, pas de scoping par site — voir docblock de
 * SurgeonMissionRequestCreatedMessage).
 *
 * In-app + push uniquement, JAMAIS d'email — une nouvelle demande n'est pas urgente
 * (le manager la retrouve sur MissionsListPage, onglet "Demandes chirurgien"), même
 * raisonnement que CatalogueRequestCreatedMessageHandler.
 *
 * Failure isolation par destinataire — l'échec d'un manager ne doit jamais empêcher
 * les autres d'être notifiés.
 */
#[AsMessageHandler]
final class SurgeonMissionRequestCreatedMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OutboundNotificationService $outboundNotificationService,
        private readonly NotificationPreferenceResolver $preferenceResolver,
        private readonly NotificationTargetResolver $targetResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SurgeonMissionRequestCreatedMessage $message): void
    {
        foreach ($message->recipientUserIds as $managerId) {
            $manager = $this->em->find(User::class, $managerId);
            if (!$manager instanceof User) {
                continue;
            }
            $this->notifyManager($manager, $message);
        }
    }

    private function notifyManager(User $manager, SurgeonMissionRequestCreatedMessage $message): void
    {
        try {
            $channels = $this->resolveChannelsSafely($manager, NotificationType::SURGEON_MISSION_REQUEST_CREATED);

            if ($channels->inApp) {
                $evt = (new NotificationEvent())
                    ->setUser($manager)
                    ->setMission(null)
                    ->setEventType(NotificationType::SURGEON_MISSION_REQUEST_CREATED->value)
                    ->setChannel(PublicationChannel::IN_APP)
                    ->setSentAt(new \DateTimeImmutable())
                    ->setPayload([
                        'requestId'   => $message->requestId,
                        'surgeonName' => $message->surgeonName,
                        'siteName'    => $message->siteName,
                        'startAt'     => $message->startAt,
                        'endAt'       => $message->endAt,
                    ]);
                $this->em->persist($evt);
                $this->em->flush();
            }

            if ($channels->push) {
                $title = 'Nouvelle demande de mission';
                $body = sprintf(
                    'Dr %s a demandé une mission le %s à %s.',
                    $message->surgeonName,
                    (new \DateTimeImmutable($message->startAt))->format('d/m/Y'),
                    $message->siteName,
                );
                $data = [
                    'requestId' => $message->requestId,
                    'url' => $this->targetResolver->resolve(NotificationType::SURGEON_MISSION_REQUEST_CREATED, null, $manager),
                ];

                // Pas de repli email en cas d'échec — voir docblock de classe.
                $this->outboundNotificationService->recordPushSend(
                    $manager,
                    NotificationType::SURGEON_MISSION_REQUEST_CREATED->value,
                    $title,
                    $body,
                    $data,
                    null,
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('SurgeonMissionRequestCreated: manager notification failed', [
                'requestId' => $message->requestId,
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
