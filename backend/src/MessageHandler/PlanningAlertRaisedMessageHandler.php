<?php

namespace App\MessageHandler;

use App\Entity\User;
use App\Enum\NotificationType;
use App\Message\PlanningAlertRaisedMessage;
use App\Message\SendBillingEmailMessage;
use App\Service\NotificationPreferenceResolver;
use App\Service\NotificationService;
use App\Service\WebPushServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Fans a single PlanningAlertRaisedMessage out to every recipient already computed by
 * AbsenceImpactService, gating each channel (in-app/email/push) through
 * NotificationPreferenceResolver — never hardcoded. Runs in the Messenger worker, fully
 * decoupled from the HTTP request that created the absence.
 *
 * No patient data anywhere in this handler — the message itself only carries
 * mission/site/date/type (see PlanningAlertRaisedMessage).
 */
#[AsMessageHandler]
final class PlanningAlertRaisedMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationPreferenceResolver $preferenceResolver,
        private readonly NotificationService $notificationService,
        private readonly WebPushServiceInterface $webPushService,
        private readonly MessageBusInterface $bus,
        #[Autowire('%env(string:MAILER_FROM_ADDRESS)%')]
        private readonly string $fromAddress,
        #[Autowire('%env(string:MAILER_FROM_NAME)%')]
        private readonly string $fromName,
    ) {}

    public function __invoke(PlanningAlertRaisedMessage $message): void
    {
        // The alert may have been resolved/ignored between dispatch and processing (e.g.
        // a manager acted on it within seconds) — that's fine, we still inform recipients
        // an alert WAS raised; nothing here needs the live entity beyond the scalar fields
        // already carried on the message itself.
        foreach ($message->recipientUserIds as $userId) {
            $user = $this->em->find(User::class, $userId);
            if ($user === null) {
                continue;
            }

            $channels = $this->preferenceResolver->resolve($user, NotificationType::PLANNING_ALERT);

            if ($channels->inApp) {
                $this->notificationService->planningAlertRaisedNotifyInApp($user, $message);
            }

            if ($channels->email && $user->getEmail()) {
                $this->bus->dispatch(new SendBillingEmailMessage(
                    to: $user->getEmail(),
                    cc: [],
                    subject: 'Alerte planning',
                    fromAddress: $this->fromAddress,
                    fromName: $this->fromName,
                    htmlTemplate: 'emails/planning_alert.html.twig',
                    context: [
                        'recipientName' => $this->displayName($user),
                        'alertType'     => $message->alertType,
                        'siteName'      => $message->siteName,
                        'missionDate'   => $message->missionDate,
                    ],
                ));
            }

            if ($channels->push) {
                $this->webPushService->sendToUser(
                    $user,
                    'Alerte planning',
                    $this->pushBody($message),
                    ['type' => 'PLANNING_ALERT', 'alertId' => $message->alertId],
                );
            }
        }

        $this->em->flush();
    }

    private function pushBody(PlanningAlertRaisedMessage $message): string
    {
        $site = $message->siteName ?? 'un site';
        return sprintf('Une alerte planning concerne une mission du %s à %s.', $message->missionDate, $site);
    }

    private function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
