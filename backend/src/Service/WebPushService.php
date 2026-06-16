<?php

namespace App\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class WebPushService implements WebPushServiceInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'monolog.logger.push')]
        private readonly LoggerInterface $logger,
        private readonly string $vapidPublicKey,
        private readonly string $vapidPrivateKey,
        private readonly string $vapidSubject,
    ) {}

    public function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        $subscriptions = $this->em->getRepository(PushSubscription::class)->findBy(['user' => $user]);
        if (empty($subscriptions)) {
            return;
        }

        $this->sendToSubscriptions($subscriptions, $title, $body, $data);
    }

    /** @param User[] $users */
    public function sendToUsers(array $users, string $title, string $body, array $data = []): void
    {
        if (empty($users)) {
            return;
        }

        $subscriptions = $this->em->getRepository(PushSubscription::class)->findBy(['user' => $users]);
        if (empty($subscriptions)) {
            return;
        }

        $this->sendToSubscriptions($subscriptions, $title, $body, $data);
    }

    public function sendToSiteInstrumentists(Mission $mission, string $title, string $body, array $data = []): void
    {
        $site = $mission->getSite();
        if (!$site instanceof Hospital) {
            return;
        }

        $memberships = $this->em->getRepository(\App\Entity\SiteMembership::class)->findBy([
            'site'     => $site,
            'siteRole' => 'INSTRUMENTIST',
        ]);

        if (empty($memberships)) {
            return;
        }

        $users = [];
        foreach ($memberships as $membership) {
            $user = $membership->getUser();
            if ($user instanceof User && $user->isActive()) {
                $users[] = $user;
            }
        }

        if (empty($users)) {
            return;
        }

        $subscriptions = $this->em->getRepository(PushSubscription::class)->findBy(['user' => $users]);
        if (empty($subscriptions)) {
            return;
        }

        $this->sendToSubscriptions($subscriptions, $title, $body, $data);
    }

    /** @param PushSubscription[] $pushSubscriptions */
    private function sendToSubscriptions(array $pushSubscriptions, string $title, string $body, array $data = []): void
    {
        $webPush = new WebPush([
            'VAPID' => [
                'subject'    => $this->vapidSubject,
                'publicKey'  => $this->vapidPublicKey,
                'privateKey' => $this->vapidPrivateKey,
            ],
        ]);

        $payload = json_encode(['title' => $title, 'body' => $body, 'data' => $data]);

        foreach ($pushSubscriptions as $sub) {
            $subscription = Subscription::create([
                'endpoint'        => (string) $sub->getEndpoint(),
                'keys'            => [
                    'p256dh' => (string) $sub->getPublicKey(),
                    'auth'   => (string) $sub->getAuthToken(),
                ],
                'contentEncoding' => $sub->getContentEncoding() ?? 'aes128gcm',
            ]);

            $webPush->queueNotification($subscription, $payload);
        }

        $sent    = 0;
        $failed  = 0;
        $expired = 0;

        try {
            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    ++$sent;
                    continue;
                }

                ++$failed;
                $this->logger->warning('push.send_failed', [
                    'endpoint' => $report->getEndpoint(),
                    'reason'   => $report->getReason(),
                    'title'    => $title,
                    'type'     => $data['type'] ?? null,
                ]);

                if ($report->isSubscriptionExpired()) {
                    ++$expired;
                    $this->em->getRepository(PushSubscription::class)
                        ->createQueryBuilder('ps')
                        ->delete()
                        ->where('ps.endpoint = :endpoint')
                        ->setParameter('endpoint', $report->getEndpoint())
                        ->getQuery()
                        ->execute();
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('push.flush_failed', [
                'error' => $e->getMessage(),
                'title' => $title,
                'type'  => $data['type'] ?? null,
                'subscriptions_count' => count($pushSubscriptions),
            ]);

            return;
        }

        if ($sent > 0 || $failed > 0) {
            $this->logger->info('push.batch_done', [
                'sent'    => $sent,
                'failed'  => $failed,
                'expired' => $expired,
                'title'   => $title,
                'type'    => $data['type'] ?? null,
            ]);
        }
    }
}
