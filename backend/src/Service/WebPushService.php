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

class WebPushService implements WebPushServiceInterface
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
        $this->sendToUserAndReportSuccess($user, $title, $body, $data);
    }

    /**
     * Same as sendToUser(), but reports whether the push was actually deliverable — at
     * least one subscription existed AND at least one transport report came back
     * successful. Needed by callers that must fall back to another channel (e.g. email,
     * D-083) when Push isn't usable, since sendToUser() itself stays void and swallows
     * per-subscription outcomes (they're only ever logged, never surfaced to the caller).
     */
    public function sendToUserAndReportSuccess(User $user, string $title, string $body, array $data = []): bool
    {
        $subscriptions = $this->em->getRepository(PushSubscription::class)->findBy(['user' => $user]);
        if (empty($subscriptions)) {
            return false;
        }

        return $this->sendToSubscriptions($subscriptions, $title, $body, $data)['sent'] > 0;
    }

    /**
     * Same as sendToUserAndReportSuccess(), but returns one entry per subscription
     * actually attempted (provider/success/statusCode/reason) instead of collapsing to a
     * single bool — needed by OutboundNotificationService (D-084) to record one
     * OutboundNotificationAttempt per real transport attempt. Never includes an endpoint,
     * only the provider derived from its host.
     *
     * @return array{sent:int, attempts:list<array{provider:string, success:bool, statusCode:?int, reason:?string}>}
     */
    public function sendToUserWithAttempts(User $user, string $title, string $body, array $data = []): array
    {
        $subscriptions = $this->em->getRepository(PushSubscription::class)->findBy(['user' => $user]);
        if (empty($subscriptions)) {
            return ['sent' => 0, 'attempts' => []];
        }

        return $this->sendToSubscriptions($subscriptions, $title, $body, $data);
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

    /**
     * @param PushSubscription[] $pushSubscriptions
     * @return array{sent:int, attempts:list<array{provider:string, success:bool, statusCode:?int, reason:?string}>}
     */
    private function sendToSubscriptions(array $pushSubscriptions, string $title, string $body, array $data = []): array
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
        $attempts = [];

        try {
            foreach ($webPush->flush() as $report) {
                $statusCode = $report->getResponse()?->getStatusCode();
                $provider = self::providerForEndpoint($report->getEndpoint());

                if ($report->isSuccess()) {
                    ++$sent;
                    $attempts[] = ['provider' => $provider, 'success' => true, 'statusCode' => $statusCode, 'reason' => null];
                    continue;
                }

                ++$failed;

                // Endpoint gone (404/410): expected and handled — auto-cleanup below, no need
                // to alarm anyone. Anything else is an unexpected send failure (bad VAPID
                // signature, quota exceeded, payload rejected, transient push-service error) —
                // logged at error so it reaches Sentry (see monolog.yaml `sentry` handler,
                // Lot 1 / audit 24-07-2026) instead of disappearing silently as before.
                $endpointHint = $this->hintForLog($report->getEndpoint());
                $normalizedReason = self::normalizeReason($report->getReason());
                if ($report->isSubscriptionExpired()) {
                    ++$expired;
                    $attempts[] = ['provider' => $provider, 'success' => false, 'statusCode' => $statusCode, 'reason' => 'expired'];
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
                    $attempts[] = ['provider' => $provider, 'success' => false, 'statusCode' => $statusCode, 'reason' => $normalizedReason];
                    // Reason logged normalized, not raw: minishlink/web-push's own
                    // getReason() embeds the full push endpoint URL in its message
                    // ("Client error: `POST https://...` resulted in...") — logging it
                    // verbatim would leak exactly what hintForLog() above exists to
                    // prevent. Found while building D-084's "no secret in any log"
                    // guarantee; fixed here since it's the same log line.
                    $this->logger->error('push.send_failed', [
                        'endpoint_hint' => $endpointHint,
                        'reason'        => $normalizedReason,
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

            return ['sent' => 0, 'attempts' => []];
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

        return ['sent' => $sent, 'attempts' => $attempts];
    }

    /** Derived from the endpoint's host only — never logs or persists the full endpoint. */
    private static function providerForEndpoint(?string $endpoint): string
    {
        $host = $endpoint !== null ? (string) (parse_url($endpoint, PHP_URL_HOST) ?: '') : '';

        return match (true) {
            str_contains($host, 'fcm.googleapis.com') => 'FCM',
            str_contains($host, 'web.push.apple.com') => 'APPLE',
            default => 'OTHER',
        };
    }

    /**
     * minishlink/web-push's MessageSentReport::getReason() embeds the full push endpoint
     * URL in its message string — never store or log that raw. Extracts a short,
     * endpoint-free code instead: the provider's own JSON "reason" field if present (e.g.
     * "BadJwtToken"), else the HTTP status phrase, else a generic fallback.
     */
    private static function normalizeReason(?string $rawReason): ?string
    {
        if ($rawReason === null || $rawReason === '') {
            return null;
        }

        if (preg_match('/"reason":"([^"]+)"/', $rawReason, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/resulted in a `(\d{3} [^`]+)`/', $rawReason, $m) === 1) {
            return $m[1];
        }

        return 'send_failed';
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
