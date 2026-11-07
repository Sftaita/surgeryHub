<?php

namespace App\MessageHandler;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Message\PlanningAlertRaisedMessage;
use App\Notification\EmailContent;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Notification\PushContent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Fans a single PlanningAlertRaisedMessage (planning conflict, PlanningConflictDetectionService)
 * out to every recipient already computed by its producer. Runs in the Messenger worker.
 *
 * H5a — through NotificationDispatcher: formerly an in-app row, a raw push and an untraced
 * SendBillingEmailMessage. Channels from preferences (PLANNING_ALERT), every delivery
 * recorded with an honest status, idempotent per alert and recipient; one recipient's
 * failure never stops the others.
 *
 * No patient data anywhere — the message only carries mission/site/date/type.
 */
#[AsMessageHandler]
final class PlanningAlertRaisedMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationDispatcher $dispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(PlanningAlertRaisedMessage $message): void
    {
        // The alert may have been resolved/ignored between dispatch and processing — we still
        // inform recipients an alert WAS raised, from the scalar fields of the message.
        $mission = $this->em->find(Mission::class, $message->missionId);

        foreach ($message->recipientUserIds as $userId) {
            $user = $this->em->find(User::class, $userId);
            if ($user === null) {
                continue;
            }

            try {
                $this->dispatcher->dispatch(new NotificationIntent(
                    recipient: $user,
                    type: NotificationType::PLANNING_ALERT,
                    inAppPayload: [
                        'alertId'     => $message->alertId,
                        'alertType'   => $message->alertType,
                        'missionId'   => $message->missionId,
                        'siteId'      => $message->siteId,
                        'siteName'    => $message->siteName,
                        'missionDate' => $message->missionDate,
                    ],
                    mission: $mission,
                    email: new EmailContent('Alerte planning', 'emails/planning_alert.html.twig', [
                        'recipientName' => self::displayName($user),
                        'alertType'     => $message->alertType,
                        'siteName'      => $message->siteName,
                        'missionDate'   => $message->missionDate,
                    ]),
                    push: new PushContent('Alerte planning', self::pushBody($message), ['type' => 'PLANNING_ALERT', 'alertId' => $message->alertId]),
                    episodeRef: 'planning_alert:' . $message->alertId,
                    subjectType: $mission === null ? 'PlanningAlert' : null,
                    subjectId: $mission === null ? $message->alertId : null,
                ));
            } catch (\Throwable $e) {
                $this->logger->error('PlanningAlertRaised: dispatch failed', [
                    'alertId' => $message->alertId, 'userId' => $userId, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private static function pushBody(PlanningAlertRaisedMessage $message): string
    {
        $site = $message->siteName ?? 'un site';
        return sprintf('Une alerte planning concerne une mission du %s à %s.', $message->missionDate, $site);
    }

    private static function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
