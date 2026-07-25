<?php

namespace App\Tests\Functional;

use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot 1 (fiabilisation du socle Web Push, audit PWA/push 24-07-2026) — first-ever
 * functional coverage of PushSubscriptionController (previously untested).
 */
final class PushSubscriptionControllerTest extends WebTestCase
{
    private const PASSWORD = 'PushCtrlTest123!';

    private EntityManagerInterface $em;
    private array $createdIds = ['users' => [], 'subscriptions' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['subscriptions'] as $id) {
                $e = $this->em->find(PushSubscription::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['users'] as $id) {
                $e = $this->em->find(User::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    /** @return array{user: User, token: string} */
    private function authenticate(KernelBrowser $client, string $role): array
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('push-ctrl-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $user->setRoles([$role]);
        $user->setActive(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
        $this->createdIds['users'][] = $user->getId();

        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, (string) $client->getResponse()->getContent());

        return ['user' => $user, 'token' => $data['token']];
    }

    private function auth(string $token, array $extra = []): array
    {
        return array_merge(['HTTP_AUTHORIZATION' => 'Bearer ' . $token], $extra);
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    private function randomEndpoint(): string
    {
        return 'https://push.example.test/' . bin2hex(random_bytes(16));
    }

    private function validSubscriptionPayload(?string $endpoint = null): array
    {
        return [
            'endpoint' => $endpoint ?? $this->randomEndpoint(),
            'keys' => ['p256dh' => 'p256dh-' . bin2hex(random_bytes(8)), 'auth' => 'auth-' . bin2hex(random_bytes(8))],
        ];
    }

    private function findSubscription(string $endpoint): ?PushSubscription
    {
        return $this->em->getRepository(PushSubscription::class)->findOneBy(['endpoint' => $endpoint]);
    }

    // ── AuthZ ────────────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_subscribe_rejects_unauthenticated_request(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $client->request('POST', '/api/push/subscribe', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($this->validSubscriptionPayload()));

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_unsubscribe_rejects_unauthenticated_request(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $client->request('DELETE', '/api/push/unsubscribe', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['endpoint' => $this->randomEndpoint()]));

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    // ── vapid-public-key ─────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_vapid_public_key_is_returned_for_an_authenticated_user(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $client->request('GET', '/api/push/vapid-public-key', server: $this->auth($token));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = $this->json($client->getResponse());
        self::assertArrayHasKey('publicKey', $body);
        self::assertNotSame('', $body['publicKey'], 'The test env must have a VAPID_PUBLIC_KEY configured (falls back to .env)');
    }

    // ── subscribe — validation ──────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_subscribe_rejects_empty_endpoint(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $payload = $this->validSubscriptionPayload();
        $payload['endpoint'] = '';
        $client->request('POST', '/api/push/subscribe', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode($payload));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_subscribe_rejects_missing_keys(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $client->request('POST', '/api/push/subscribe', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['endpoint' => $this->randomEndpoint()]));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_subscribe_rejects_endpoint_exceeding_column_length(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $payload = $this->validSubscriptionPayload('https://push.example.test/' . str_repeat('a', 500));
        $client->request('POST', '/api/push/subscribe', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode($payload));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    // ── subscribe — create / idempotent update / reassignment ──────────────

    #[WithoutErrorHandler]
    public function test_subscribe_creates_a_new_subscription(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $user, 'token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $payload = $this->validSubscriptionPayload();
        $client->request('POST', '/api/push/subscribe', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode($payload));

        self::assertSame(204, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $sub = $this->findSubscription($payload['endpoint']);
        self::assertNotNull($sub);
        $this->createdIds['subscriptions'][] = $sub->getId();
        self::assertSame($user->getId(), $sub->getUser()->getId());
        self::assertSame($payload['keys']['p256dh'], $sub->getPublicKey());
        self::assertSame($payload['keys']['auth'], $sub->getAuthToken());
    }

    #[WithoutErrorHandler]
    public function test_subscribe_is_idempotent_for_the_same_user_and_endpoint_no_duplicate_row(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $payload = $this->validSubscriptionPayload();
        $client->request('POST', '/api/push/subscribe', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode($payload));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        // Resubmit the exact same subscription (browser re-registering on reload).
        $client->request('POST', '/api/push/subscribe', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode($payload));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(ps.id)')->from(PushSubscription::class, 'ps')
            ->where('ps.endpoint = :endpoint')->setParameter('endpoint', $payload['endpoint'])
            ->getQuery()->getSingleScalarResult();
        self::assertSame(1, $count, 'Resubmitting the same endpoint must never create a duplicate row');

        $sub = $this->findSubscription($payload['endpoint']);
        $this->createdIds['subscriptions'][] = $sub->getId();
    }

    #[WithoutErrorHandler]
    public function test_subscribe_refreshes_keys_for_an_existing_endpoint_of_the_same_user(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $endpoint = $this->randomEndpoint();
        $first = $this->validSubscriptionPayload($endpoint);
        $client->request('POST', '/api/push/subscribe', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode($first));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        // Browser rotated its keys for the same endpoint (can legitimately happen).
        $second = $this->validSubscriptionPayload($endpoint);
        $client->request('POST', '/api/push/subscribe', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode($second));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $sub = $this->findSubscription($endpoint);
        $this->createdIds['subscriptions'][] = $sub->getId();
        self::assertSame($second['keys']['p256dh'], $sub->getPublicKey());
        self::assertSame($second['keys']['auth'], $sub->getAuthToken());
    }

    #[WithoutErrorHandler]
    public function test_subscribe_explicitly_reassigns_an_endpoint_owned_by_another_user(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $userA, 'token' => $tokenA] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $endpoint = $this->randomEndpoint();
        $client->request('POST', '/api/push/subscribe', server: $this->auth($tokenA, ['CONTENT_TYPE' => 'application/json']), content: json_encode($this->validSubscriptionPayload($endpoint)));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        // Shared-device scenario: a different user's browser reuses the same push
        // endpoint (same physical browser subscription) without user A ever calling
        // /unsubscribe first (e.g. session expiry rather than an explicit logout).
        ['user' => $userB, 'token' => $tokenB] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');
        $client->request('POST', '/api/push/subscribe', server: $this->auth($tokenB, ['CONTENT_TYPE' => 'application/json']), content: json_encode($this->validSubscriptionPayload($endpoint)));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(ps.id)')->from(PushSubscription::class, 'ps')
            ->where('ps.endpoint = :endpoint')->setParameter('endpoint', $endpoint)
            ->getQuery()->getSingleScalarResult();
        self::assertSame(1, $count, 'Reassignment must reuse the same row, never duplicate it');

        $sub = $this->findSubscription($endpoint);
        $this->createdIds['subscriptions'][] = $sub->getId();
        self::assertSame($userB->getId(), $sub->getUser()->getId(), 'Endpoint must now belong to the reassigning user');
        self::assertNotSame($userA->getId(), $sub->getUser()->getId());
    }

    // ── unsubscribe ──────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_unsubscribe_removes_the_caller_own_subscription(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $endpoint = $this->randomEndpoint();
        $client->request('POST', '/api/push/subscribe', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode($this->validSubscriptionPayload($endpoint)));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $client->request('DELETE', '/api/push/unsubscribe', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['endpoint' => $endpoint]));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $this->em->clear();
        self::assertNull($this->findSubscription($endpoint));
    }

    #[WithoutErrorHandler]
    public function test_unsubscribe_is_idempotent_when_already_absent(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $client->request('DELETE', '/api/push/unsubscribe', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['endpoint' => $this->randomEndpoint()]));

        self::assertSame(204, $client->getResponse()->getStatusCode(), 'Unsubscribing a non-existent endpoint must succeed, not error');
    }

    #[WithoutErrorHandler]
    public function test_unsubscribe_never_removes_another_user_subscription(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $tokenA] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $endpoint = $this->randomEndpoint();
        $client->request('POST', '/api/push/subscribe', server: $this->auth($tokenA, ['CONTENT_TYPE' => 'application/json']), content: json_encode($this->validSubscriptionPayload($endpoint)));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        ['token' => $tokenB] = $this->authenticate($client, 'ROLE_SURGEON');
        $client->request('DELETE', '/api/push/unsubscribe', server: $this->auth($tokenB, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['endpoint' => $endpoint]));
        self::assertSame(204, $client->getResponse()->getStatusCode(), 'Idempotent 204 even though nothing belonging to B was found');

        $this->em->clear();
        $sub = $this->findSubscription($endpoint);
        self::assertNotNull($sub, "User A's subscription must survive user B's unsubscribe attempt");
        $this->createdIds['subscriptions'][] = $sub->getId();
    }

    // ── Every role can manage its own subscription ──────────────────────────

    /** @return string[] */
    public static function rolesProvider(): array
    {
        return [
            'instrumentiste' => ['ROLE_INSTRUMENTIST'],
            'chirurgien'     => ['ROLE_SURGEON'],
            'manager'        => ['ROLE_MANAGER'],
            'admin'          => ['ROLE_ADMIN'],
        ];
    }

    #[WithoutErrorHandler]
    #[\PHPUnit\Framework\Attributes\DataProvider('rolesProvider')]
    public function test_every_role_can_subscribe_and_unsubscribe_its_own_endpoint(string $role): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, $role);

        $endpoint = $this->randomEndpoint();
        $client->request('POST', '/api/push/subscribe', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode($this->validSubscriptionPayload($endpoint)));
        self::assertSame(204, $client->getResponse()->getStatusCode(), "subscribe must succeed for $role");

        $client->request('DELETE', '/api/push/unsubscribe', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['endpoint' => $endpoint]));
        self::assertSame(204, $client->getResponse()->getStatusCode(), "unsubscribe must succeed for $role");

        $this->em->clear();
        self::assertNull($this->findSubscription($endpoint));
    }
}
