<?php

namespace App\MessageHandler;

use App\Entity\User;
use App\Enum\NotificationType;
use App\Message\SurgeonMissionRequestCreatedMessage;
use App\Service\NotificationService;
use App\Service\NotificationTargetResolver;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Notification\PushContent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Lot 5 (D-099) — prévient les managers/admins actifs qu'un chirurgien vient de
 * demander une mission. Même ciblage que CatalogueRequestCreatedMessageHandler
 * (destinataires figés sur le message, pas de scoping par site — voir docblock de
 * SurgeonMissionRequestCreatedMessage).
 *
 * Email opt-in : défaut email=false (une nouvelle demande n'est pas urgente, le manager la
 * retrouve sur MissionsListPage, onglet "Demandes chirurgien"), mais un manager qui coche
 * "E-mail" pour ce type dans ses préférences le reçoit (WHEN_ENABLED, canal indépendant).
 *
 * Failure isolation par destinataire — l'échec d'un manager ne doit jamais empêcher
 * les autres d'être notifiés.
 *
 * H5b (D-132) — through NotificationDispatcher (in-app + email + push per preferences),
 * every channel traced, idempotent per request and manager.
 */
#[AsMessageHandler]
final class SurgeonMissionRequestCreatedMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationTargetResolver $targetResolver,
        private readonly NotificationService $notificationService,
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
            $type = NotificationType::SURGEON_MISSION_REQUEST_CREATED;
            $this->dispatcher->dispatch(new NotificationIntent(
                recipient: $manager,
                type: $type,
                inAppPayload: [
                    'requestId'   => $message->requestId,
                    'surgeonName' => $message->surgeonName,
                    'siteName'    => $message->siteName,
                    'startAt'     => $message->startAt,
                    'endAt'       => $message->endAt,
                ],
                email: $this->notificationService->surgeonMissionRequestCreatedEmail($manager, $message->surgeonName, $message->siteName, $message->startAt, $message->endAt),
                push: new PushContent(
                    'Nouvelle demande de mission',
                    sprintf('Dr %s a demandé une mission le %s à %s.', $message->surgeonName, (new \DateTimeImmutable($message->startAt))->format('d/m/Y'), $message->siteName),
                    ['requestId' => $message->requestId, 'url' => $this->targetResolver->resolve($type, null, $manager)],
                ),
                episodeRef: 'surgeon_mission_request_created:' . $message->requestId,
                subjectType: 'SurgeonMissionRequest',
                subjectId: $message->requestId,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('SurgeonMissionRequestCreated: manager notification failed', [
                'requestId' => $message->requestId,
                'managerId' => $manager->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
