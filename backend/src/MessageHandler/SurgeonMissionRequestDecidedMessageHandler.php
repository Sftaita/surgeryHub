<?php

namespace App\MessageHandler;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\NotificationEmailPolicy;
use App\Enum\NotificationType;
use App\Message\SurgeonMissionRequestDecidedMessage;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Notification\PushContent;
use App\Service\NotificationService;
use App\Service\NotificationTargetResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Lot 5 (D-099) — prévient le chirurgien de l'issue de sa SurgeonMissionRequest une fois le
 * manager passé par MissionsListPage (onglet "Demandes chirurgien").
 *
 * H5a — via NotificationDispatcher : in-app, push d'abord, repli email (politique
 * FALLBACK_WHEN_PUSH_NOT_SENT, orchestration D-083 inchangée), chaque canal tracé,
 * idempotent par demande. Une acceptation sans Mission retrouvée n'a pas d'email (comme avant).
 */
#[AsMessageHandler]
final class SurgeonMissionRequestDecidedMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationService $notificationService,
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
            $date = (new \DateTimeImmutable($message->startAt))->format('d/m/Y');

            $email = match (true) {
                $message->accepted && $mission !== null => $this->notificationService->surgeonMissionRequestAcceptedEmail($mission, $surgeon),
                !$message->accepted => $this->notificationService->surgeonMissionRequestRejectedEmail(
                    $surgeon, $message->siteName, new \DateTimeImmutable($message->startAt), $message->reviewComment ?? '',
                ),
                default => null,
            };

            $this->dispatcher->dispatch(new NotificationIntent(
                recipient: $surgeon,
                type: $type,
                inAppPayload: [
                    'requestId'     => $message->requestId,
                    'missionId'     => $message->missionId,
                    'siteName'      => $message->siteName,
                    'startAt'       => $message->startAt,
                    'reviewComment' => $message->reviewComment,
                ],
                mission: $mission,
                email: $email,
                push: new PushContent(
                    $message->accepted ? 'Demande de mission acceptée' : 'Demande de mission refusée',
                    $message->accepted
                        ? sprintf('Votre demande de mission du %s à %s a été acceptée.', $date, $message->siteName)
                        : sprintf('Votre demande de mission du %s à %s n\'a pas été retenue.', $date, $message->siteName),
                    [
                        'requestId' => $message->requestId,
                        'missionId' => $message->missionId,
                        'url' => $this->targetResolver->resolve($type, $mission, $surgeon),
                    ],
                ),
                episodeRef: 'surgeon_mission_request:' . $message->requestId,
                subjectType: $mission === null ? 'SurgeonMissionRequest' : null,
                subjectId: $mission === null ? $message->requestId : null,
                emailPolicy: NotificationEmailPolicy::FALLBACK_WHEN_PUSH_NOT_SENT,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('SurgeonMissionRequestDecided: notification failed', [
                'requestId' => $message->requestId,
                'surgeonId' => $message->surgeonId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
