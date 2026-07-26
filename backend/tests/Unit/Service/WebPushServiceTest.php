<?php

namespace App\Tests\Unit\Service;

use App\Entity\PushSubscription;
use App\Entity\User;
use App\Service\WebPushService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Lot 1 (fiabilisation du socle Web Push, audit PWA/push 24-07-2026) — first-ever unit
 * coverage of the real WebPushService (previously only exercised via
 * WebPushServiceInterface doubles in handler tests).
 *
 * Exercises sendToSubscriptions() against real (mocked) HTTP responses through the
 * `httpClientOptions` seam (a Guzzle MockHandler injected as `handler`), instead of
 * mocking minishlink/web-push itself — this is the actual HTTP contract (2xx/404/410/5xx)
 * production traffic produces.
 *
 * A syntactically valid VAPID keypair is required for WebPush::queueNotification() to
 * sign requests at all — see VAPID_PUBLIC_KEY/VAPID_PRIVATE_KEY below.
 */
final class WebPushServiceTest extends TestCase
{
    /** Test-only VAPID key pair. Never use in dev or production. */
    private const VAPID_PUBLIC_KEY  = 'BLiIGxqZdtCBQ4lOGR-CR1fqlE1BL9pMj1K5P2iKzfgnvCOv7e3umyG2ruAnyPP1aQNvt7XiEqk4K8Fvk472ZDE';
    private const VAPID_PRIVATE_KEY = 'bG2PI77Lh7YoYcZLGYi219Ep1F1r7QjFElNGc3wIQmg';
    private const VAPID_SUBJECT     = 'mailto:test@surgicalhub.invalid';

    /**
     * Not a real subscriber's key — a throwaway EC P-256 keypair generated once for this
     * test file (openssl_pkey_new, uncompressed point, base64url) so payload encryption
     * (aes128gcm) has syntactically and cryptographically valid material to work with.
     * `auth` is 16 random bytes, base64url. Neither value is used outside this test.
     */
    private const SUBSCRIBER_P256DH = 'BIU71jF27lGRVeJSQ1Bg82JpaC7r71OOff55wBTFM8CAEowOuj1udpNHJOMFm53Hm1FLLAQH5QrAfdCRutwiWuc';
    private const SUBSCRIBER_AUTH   = 'fEIRI1HhX0nIGF4RXV9-dA';

    private EntityManagerInterface&MockObject $em;
    private LoggerInterface&MockObject $logger;
    private EntityRepository&MockObject $repo;

    protected function setUp(): void
    {
        $this->em   = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->repo = $this->createMock(EntityRepository::class);

        $this->em->method('getRepository')
            ->with(PushSubscription::class)
            ->willReturn($this->repo);
    }

    private function service(MockHandler $mockHandler): WebPushService
    {
        return new WebPushService(
            $this->em,
            $this->logger,
            self::VAPID_PUBLIC_KEY,
            self::VAPID_PRIVATE_KEY,
            self::VAPID_SUBJECT,
            ['handler' => HandlerStack::create($mockHandler)],
        );
    }

    private function subscription(string $endpoint): PushSubscription
    {
        return (new PushSubscription())
            ->setUser(new User())
            ->setEndpoint($endpoint)
            ->setPublicKey(self::SUBSCRIBER_P256DH)
            ->setAuthToken(self::SUBSCRIBER_AUTH)
            ->setContentEncoding('aes128gcm');
    }

    /** Stubs the delete-on-expiry query builder chain used for one specific endpoint. */
    private function expectDeleteFor(string $endpoint): void
    {
        $query = $this->createMock(Query::class);
        $query->expects($this->once())->method('execute');

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('delete')->willReturnSelf();
        $qb->method('where')->with('ps.endpoint = :endpoint')->willReturnSelf();
        $qb->expects($this->once())
            ->method('setParameter')
            ->with('endpoint', $endpoint)
            ->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->repo->method('createQueryBuilder')->with('ps')->willReturn($qb);
    }

    private function expectNoDelete(): void
    {
        $this->repo->expects($this->never())->method('createQueryBuilder');
    }

    // ── validation VAPID_SUBJECT (D-081 addendum, Apple 403 BadJwtToken) ───────

    public function test_constructor_rejects_mailto_subject_on_local_domain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/VAPID_SUBJECT/');

        new WebPushService(
            $this->em,
            $this->logger,
            self::VAPID_PUBLIC_KEY,
            self::VAPID_PRIVATE_KEY,
            'mailto:admin@surgicalhub.local',
        );
    }

    public function test_constructor_accepts_mailto_subject_on_real_domain(): void
    {
        $service = new WebPushService(
            $this->em,
            $this->logger,
            self::VAPID_PUBLIC_KEY,
            self::VAPID_PRIVATE_KEY,
            'mailto:notifications@surgicalhub.be',
        );

        $this->assertInstanceOf(WebPushService::class, $service);
    }

    /**
     * Guards against ever reintroducing the dev VAPID key that leaked into a committed
     * `backend/.env` (security lot, docs/decisions.md) — compares by SHA-256 fingerprint
     * only, so the leaked value itself never needs to be stored here to detect it.
     */
    public function test_vapid_constants_are_not_the_leaked_dev_keypair(): void
    {
        self::assertNotSame(
            'd247c2e093dceaa1530d4bf684c208308844d6c8349528298d9286f37649a104',
            hash('sha256', self::VAPID_PUBLIC_KEY),
            'This is the fingerprint of the leaked dev VAPID public key — never reuse it in tests.',
        );
        self::assertNotSame(
            '6febeb88c4600403ab3f2cc2f02e0af5857cba8f146d3556a28421fcc551eccf',
            hash('sha256', self::VAPID_PRIVATE_KEY),
            'This is the fingerprint of the leaked dev VAPID private key — never reuse it in tests.',
        );
    }

    public function test_constructor_accepts_https_subject(): void
    {
        $service = new WebPushService(
            $this->em,
            $this->logger,
            self::VAPID_PUBLIC_KEY,
            self::VAPID_PRIVATE_KEY,
            'https://surgicalhub.be',
        );

        $this->assertInstanceOf(WebPushService::class, $service);
    }

    // ── sendToUserAndReportSuccess (D-083) ──────────────────────────────────────

    public function test_report_success_returns_true_when_at_least_one_subscription_receives_it(): void
    {
        $user = new User();
        $sub  = $this->subscription('https://push.example.test/report-success-aaaaaaaaaaaa');
        $this->repo->method('findBy')->with(['user' => $user])->willReturn([$sub]);
        $this->expectNoDelete();

        $service = $this->service(new MockHandler([new Response(201)]));

        self::assertTrue($service->sendToUserAndReportSuccess($user, 'Titre', 'Corps'));
    }

    public function test_report_success_returns_false_when_user_has_no_subscription(): void
    {
        $user = new User();
        $this->repo->method('findBy')->with(['user' => $user])->willReturn([]);

        $service = $this->service(new MockHandler([]));

        self::assertFalse($service->sendToUserAndReportSuccess($user, 'Titre', 'Corps'));
    }

    public function test_report_success_returns_false_when_every_attempt_fails(): void
    {
        $user = new User();
        $sub  = $this->subscription('https://push.example.test/report-failure-bbbbbbbbbbbb');
        $this->repo->method('findBy')->with(['user' => $user])->willReturn([$sub]);
        $this->expectNoDelete();

        // 500: an unexpected failure, not an expired subscription — no cleanup expected.
        $service = $this->service(new MockHandler([new Response(500)]));

        self::assertFalse($service->sendToUserAndReportSuccess($user, 'Titre', 'Corps'));
    }

    // ── sendToUserWithAttempts (D-084) ──────────────────────────────────────────

    public function test_with_attempts_returns_no_subscription_when_user_has_none(): void
    {
        $user = new User();
        $this->repo->method('findBy')->with(['user' => $user])->willReturn([]);

        $service = $this->service(new MockHandler([]));
        $result = $service->sendToUserWithAttempts($user, 'Titre', 'Corps');

        self::assertSame(['sent' => 0, 'attempts' => []], $result);
    }

    public function test_with_attempts_reports_provider_and_success_on_fcm_success(): void
    {
        $user = new User();
        $sub  = $this->subscription('https://fcm.googleapis.com/fcm/send/aaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->repo->method('findBy')->with(['user' => $user])->willReturn([$sub]);
        $this->expectNoDelete();

        $service = $this->service(new MockHandler([new Response(201)]));
        $result = $service->sendToUserWithAttempts($user, 'Titre', 'Corps');

        self::assertSame(1, $result['sent']);
        self::assertCount(1, $result['attempts']);
        self::assertSame('FCM', $result['attempts'][0]['provider']);
        self::assertTrue($result['attempts'][0]['success']);
        self::assertSame(201, $result['attempts'][0]['statusCode']);
        self::assertNull($result['attempts'][0]['reason']);
    }

    public function test_with_attempts_normalizes_apple_failure_reason_without_leaking_endpoint(): void
    {
        $user = new User();
        $sub  = $this->subscription('https://web.push.apple.com/aVeryLongSecretEndpointToken1234567890');
        $this->repo->method('findBy')->with(['user' => $user])->willReturn([$sub]);
        $this->expectNoDelete();

        $service = $this->service(new MockHandler([
            new Response(403, [], '{"reason":"BadJwtToken"}'),
        ]));
        $result = $service->sendToUserWithAttempts($user, 'Titre', 'Corps');

        self::assertSame(0, $result['sent']);
        self::assertCount(1, $result['attempts']);
        self::assertSame('APPLE', $result['attempts'][0]['provider']);
        self::assertFalse($result['attempts'][0]['success']);
        self::assertSame('BadJwtToken', $result['attempts'][0]['reason']);
        self::assertStringNotContainsString('aVeryLongSecretEndpointToken1234567890', (string) $result['attempts'][0]['reason']);
    }

    public function test_with_attempts_marks_expired_subscription_with_expired_reason(): void
    {
        $user = new User();
        $sub  = $this->subscription('https://fcm.googleapis.com/fcm/send/bbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $this->repo->method('findBy')->with(['user' => $user])->willReturn([$sub]);
        $this->expectDeleteFor('https://fcm.googleapis.com/fcm/send/bbbbbbbbbbbbbbbbbbbbbbbbbbbb');

        $service = $this->service(new MockHandler([new Response(410)]));
        $result = $service->sendToUserWithAttempts($user, 'Titre', 'Corps');

        self::assertSame(0, $result['sent']);
        self::assertSame('expired', $result['attempts'][0]['reason']);
    }

    // ── envoi réussi ─────────────────────────────────────────────────────────

    public function test_successful_send_logs_batch_done_without_any_error(): void
    {
        $user = new User();
        $sub  = $this->subscription('https://push.example.test/success-endpoint-aaaaaaaaaaaa');
        $this->repo->method('findBy')->with(['user' => $user])->willReturn([$sub]);
        $this->expectNoDelete();

        $this->logger->expects($this->never())->method('error');
        $this->logger->expects($this->never())->method('warning');
        $this->logger->expects($this->once())
            ->method('info')
            ->with('push.batch_done', $this->callback(
                fn (array $ctx) => $ctx['sent'] === 1 && $ctx['failed'] === 0 && $ctx['expired'] === 0,
            ));

        $service = $this->service(new MockHandler([new Response(201)]));
        $service->sendToUser($user, 'Titre', 'Corps');
    }

    // ── échec isolé, poursuite vers les abonnements suivants ────────────────

    public function test_one_failing_subscription_does_not_block_the_others_in_the_batch(): void
    {
        $users = [new User(), new User()];
        $subs  = [
            $this->subscription('https://push.example.test/failing-endpoint-bbbbbbbbbbbb'),
            $this->subscription('https://push.example.test/working-endpoint-cccccccccccc'),
        ];
        $this->repo->method('findBy')->willReturn($subs);
        $this->expectDeleteFor('https://push.example.test/failing-endpoint-bbbbbbbbbbbb');

        // First subscription: gone (410) → expected, isolated. Second: succeeds (201).
        $service = $this->service(new MockHandler([new Response(410), new Response(201)]));

        $infoCalls = [];
        $this->logger->method('info')->willReturnCallback(function (string $message, array $context) use (&$infoCalls) {
            $infoCalls[$message] = $context;
        });
        $this->logger->expects($this->never())->method('error');

        $service->sendToUsers($users, 'Titre', 'Corps');

        self::assertArrayHasKey('push.batch_done', $infoCalls);
        self::assertSame(1, $infoCalls['push.batch_done']['sent']);
        self::assertSame(1, $infoCalls['push.batch_done']['failed']);
        self::assertSame(1, $infoCalls['push.batch_done']['expired']);
    }

    // ── suppression sur 404 / 410 ────────────────────────────────────────────

    public function test_expired_subscription_returning_404_is_deleted(): void
    {
        $user = new User();
        $sub  = $this->subscription('https://push.example.test/gone-404-dddddddddddd');
        $this->repo->method('findBy')->willReturn([$sub]);
        $this->expectDeleteFor('https://push.example.test/gone-404-dddddddddddd');

        $service = $this->service(new MockHandler([new Response(404)]));
        $service->sendToUser($user, 'Titre', 'Corps');
    }

    public function test_expired_subscription_returning_410_is_deleted(): void
    {
        $user = new User();
        $sub  = $this->subscription('https://push.example.test/gone-410-eeeeeeeeeeee');
        $this->repo->method('findBy')->willReturn([$sub]);
        $this->expectDeleteFor('https://push.example.test/gone-410-eeeeeeeeeeee');

        $service = $this->service(new MockHandler([new Response(410)]));
        $service->sendToUser($user, 'Titre', 'Corps');
    }

    // ── absence de suppression sur erreur temporaire ─────────────────────────

    public function test_temporary_server_error_does_not_delete_the_subscription(): void
    {
        $user = new User();
        $sub  = $this->subscription('https://push.example.test/temp-error-ffffffffffff');
        $this->repo->method('findBy')->willReturn([$sub]);
        $this->expectNoDelete();

        $this->logger->expects($this->once())->method('error')->with('push.send_failed', $this->anything());

        $service = $this->service(new MockHandler([new Response(503)]));
        $service->sendToUser($user, 'Titre', 'Corps');
    }

    // ── log d'erreur pour un échec non expiré ────────────────────────────────

    public function test_non_expired_failure_is_logged_at_error_level_with_reason_and_type(): void
    {
        $user = new User();
        $sub  = $this->subscription('https://push.example.test/bad-payload-gggggggggggg');
        $this->repo->method('findBy')->willReturn([$sub]);
        $this->expectNoDelete();

        $this->logger->expects($this->once())
            ->method('error')
            ->with('push.send_failed', $this->callback(
                fn (array $ctx) => $ctx['type'] === 'REMINDER'
                    && array_key_exists('reason', $ctx)
                    && $ctx['reason'] !== '',
            ));

        $service = $this->service(new MockHandler([new Response(500)]));
        $service->sendToUser($user, 'Titre', 'Corps', ['type' => 'REMINDER']);
    }

    // ── aucune propagation bloquante vers le flux métier ─────────────────────

    public function test_send_failures_never_throw_out_of_the_service(): void
    {
        $user = new User();
        $sub  = $this->subscription('https://push.example.test/throws-nothing-hhhhhhhhhhhh');
        $this->repo->method('findBy')->willReturn([$sub]);

        $service = $this->service(new MockHandler([new Response(500)]));

        $service->sendToUser($user, 'Titre', 'Corps');
        $this->addToAssertionCount(1); // reaching here without a thrown exception is the assertion
    }

    // ── aucun secret ni endpoint complet dans les logs ───────────────────────

    public function test_full_endpoint_and_keys_never_appear_in_error_logs(): void
    {
        $fullEndpoint = 'https://push.example.test/very-long-secret-endpoint-path-should-not-leak-anywhere-in-logs';
        $user = new User();
        $sub  = $this->subscription($fullEndpoint);
        $this->repo->method('findBy')->willReturn([$sub]);
        $this->expectNoDelete();

        $loggedContexts = [];
        $this->logger->method('error')->willReturnCallback(function (string $message, array $context) use (&$loggedContexts) {
            $loggedContexts[] = $context;
        });

        $service = $this->service(new MockHandler([new Response(500)]));
        $service->sendToUser($user, 'Titre', 'Corps');

        self::assertNotEmpty($loggedContexts);
        foreach ($loggedContexts as $context) {
            $encoded = json_encode($context);
            self::assertStringNotContainsString($fullEndpoint, (string) $encoded);
            self::assertStringNotContainsString(self::SUBSCRIBER_P256DH, (string) $encoded);
            self::assertStringNotContainsString(self::SUBSCRIBER_AUTH, (string) $encoded);
        }
    }

    // ── aucun envoi si aucun abonnement ──────────────────────────────────────

    public function test_no_subscriptions_means_no_http_call_and_no_log(): void
    {
        $user = new User();
        $this->repo->method('findBy')->willReturn([]);

        $this->logger->expects($this->never())->method('info');
        $this->logger->expects($this->never())->method('error');
        $this->logger->expects($this->never())->method('warning');

        $service = $this->service(new MockHandler([]));
        $service->sendToUser($user, 'Titre', 'Corps');
    }
}
