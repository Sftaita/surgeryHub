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
        /**
         * Forwarded as-is to minishlink/web-push's underlying Guzzle client (e.g. a
         * `handler` => MockHandler stack in tests). Empty in production — the library
         * builds its own default HTTP client. This is the minimal seam needed to unit-test
         * sendToSubscriptions() against real (mocked) HTTP responses instead of only via
         * WebPushServiceInterface doubles in handler tests (Lot 1 / audit 24-07-2026).
         */
        private readonly array $httpClientOptions = [],
    ) {
        self::assertValidVapidSubject($vapidSubject);
    }

    /**
     * Apple's Web Push endpoint (web.push.apple.com) validates the JWT `sub` claim
     * strictly and rejects a ".local" mailto domain with `403 BadJwtToken` — FCM does
     * not validate it, so this only surfaces on iOS. Confirmed on a real device
     * 25-07-2026 (D-081 addendum): reproduced with VAPID_SUBJECT=mailto:admin@surgicalhub.local,
     * fixed by switching to a real, monitored domain.
     */
    private static function assertValidVapidSubject(string $subject): void
    {
        if (str_starts_with($subject, 'https://')) {
            return;
        }

        if (str_starts_with($subject, 'mailto:')) {
            $domain = strtolower((string) strrchr($subject, '@'));
            if ($domain !== '' && !str_ends_with($domain, '.local')) {
                return;
            }
        }

        throw new \InvalidArgumentException(
            '[WebPushService] Invalid VAPID_SUBJECT: must be a "mailto:" address on a real, ' .
            'non-".local" domain, or an "https://" URL — Apple Web Push rejects anything else.'
        );
    }

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
        ], [], 30, $this->httpClientOptions);

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

                // Endpoint gone (404/410): expected and handled — auto-cleanup below, no need
                // to alarm anyone. Anything else is an unexpected send failure (bad VAPID
                // signature, quota exceeded, payload rejected, transient push-service error) —
                // logged at error so it reaches Sentry (see monolog.yaml `sentry` handler,
                // Lot 1 / audit 24-07-2026) instead of disappearing silently as before.
                $endpointHint = $this->hintForLog($report->getEndpoint());
                if ($report->isSubscriptionExpired()) {
                    ++$expired;
                    $this->logger->info('push.subscription_expired', [
                        'endpoint_hint' => $endpointHint,
                        'type'          => $data['type'] ?? null,
                    ]);
                    $this->em->getRepository(PushSubscription::class)
                        ->createQueryBuilder('ps')
                        ->delete()
                        ->where('ps.endpoint = :endpoint')
                        ->setParameter('endpoint', $report->getEndpoint())
                        ->getQuery()
                        ->execute();
                } else {
                    $this->logger->error('push.send_failed', [
                        'endpoint_hint' => $endpointHint,
                        'reason'        => $report->getReason(),
                        'title'         => $title,
                        'type'          => $data['type'] ?? null,
                    ]);
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

    /**
     * Never log a full push endpoint (it's effectively a per-device bearer credential) —
     * only enough of it to correlate log lines by eye, e.g. "https://push.example/…xyz".
     */
    private function hintForLog(?string $endpoint): string
    {
        if ($endpoint === null || $endpoint === '') {
            return '(none)';
        }

        return substr($endpoint, 0, 24) . '…' . substr($endpoint, -6);
    }
}
