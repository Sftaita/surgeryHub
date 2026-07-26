<?php

namespace App\Tests\Functional;

use App\Entity\OutboundNotification;
use App\Entity\OutboundNotificationAttempt;
use App\Entity\User;
use App\Enum\OutboundNotificationChannel;
use App\Enum\OutboundNotificationStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-084 — first functional coverage of AdminOutboundNotificationController: RBAC
 * (ADMIN only), pagination, filters, detail, and the "never a secret in the response"
 * guarantee.
 */
final class AdminOutboundNotificationControllerTest extends WebTestCase
{
    private const PASSWORD = 'AdminOutboundTest123!';

    private EntityManagerInterface $em;
    private array $createdIds = ['users' => [], 'notifications' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['notifications'] as $id) {
                $e = $this->em->find(OutboundNotification::class, $id);
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
        $user->setEmail('admin-outbound-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $user->setRoles([$role]);
        $user->setActive(true);
        $user->setFirstname('Test');
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
        $this->createdIds['users'][] = $user->getId();

        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, (string) $client->getResponse()->getContent());

        return ['user' => $user, 'token' => $data['token']];
    }

    private function auth(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    private function makeNotification(User $recipient, OutboundNotificationChannel $channel, OutboundNotificationStatus $status, string $type = 'ENCODING_REMINDER_D1'): OutboundNotification
    {
        $n = (new OutboundNotification())
            ->setRecipientUser($recipient)
            ->setChannel($channel)
            ->setNotificationType($type)
            ->setStatus($status)
            ->setTitle($channel === OutboundNotificationChannel::PUSH ? 'Encodage à finaliser' : null)
            ->setSubject($channel === OutboundNotificationChannel::EMAIL ? 'SurgicalHub — Encodage à finaliser' : null)
            ->setBodyText('La mission d\'hier n\'a pas encore été soumise.')
            ->setPayload(['missionId' => 690, 'url' => '/app/i/missions/690']);
        $n->addAttempt((new OutboundNotificationAttempt())->setSuccess(true)->setStatusCode(201)->setProvider('FCM'));

        $this->em->persist($n);
        $this->em->flush();
        $this->createdIds['notifications'][] = $n->getId();

        return $n;
    }

    // ── RBAC ─────────────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_admin_can_list(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $admin] = $this->authenticate($client, 'ROLE_ADMIN');
        $this->makeNotification($admin, OutboundNotificationChannel::PUSH, OutboundNotificationStatus::SENT);

        $client->request('GET', '/api/admin/outbound-notifications', server: $this->auth($token));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_manager_is_forbidden(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $client->request('GET', '/api/admin/outbound-notifications', server: $this->auth($token));

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_instrumentist_is_forbidden(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $client->request('GET', '/api/admin/outbound-notifications', server: $this->auth($token));

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_unauthenticated_request_is_rejected(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $client->request('GET', '/api/admin/outbound-notifications');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    // ── pagination / filtres ────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_pagination_envelope_and_real_total(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $admin] = $this->authenticate($client, 'ROLE_ADMIN');
        $this->makeNotification($admin, OutboundNotificationChannel::PUSH, OutboundNotificationStatus::SENT);
        $this->makeNotification($admin, OutboundNotificationChannel::EMAIL, OutboundNotificationStatus::QUEUED);

        $client->request('GET', '/api/admin/outbound-notifications?page=1&limit=1', server: $this->auth($token));
        $body = $this->json($client->getResponse());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $body['items']);
        self::assertSame(1, $body['page']);
        self::assertSame(1, $body['limit']);
        self::assertGreaterThanOrEqual(2, $body['total'], 'total must be a real count, not just count(page)');
    }

    #[WithoutErrorHandler]
    public function test_filter_by_channel(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $admin] = $this->authenticate($client, 'ROLE_ADMIN');
        $push = $this->makeNotification($admin, OutboundNotificationChannel::PUSH, OutboundNotificationStatus::SENT);
        $this->makeNotification($admin, OutboundNotificationChannel::EMAIL, OutboundNotificationStatus::QUEUED);

        $client->request('GET', '/api/admin/outbound-notifications?channel=PUSH&recipientUserId=' . $admin->getId(), server: $this->auth($token));
        $body = $this->json($client->getResponse());

        foreach ($body['items'] as $item) {
            self::assertSame('PUSH', $item['channel']);
        }
        self::assertContains($push->getId(), array_column($body['items'], 'id'));
    }

    #[WithoutErrorHandler]
    public function test_filter_by_status(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $admin] = $this->authenticate($client, 'ROLE_ADMIN');
        $this->makeNotification($admin, OutboundNotificationChannel::PUSH, OutboundNotificationStatus::SENT);
        $queued = $this->makeNotification($admin, OutboundNotificationChannel::EMAIL, OutboundNotificationStatus::QUEUED);

        $client->request('GET', '/api/admin/outbound-notifications?status=QUEUED&recipientUserId=' . $admin->getId(), server: $this->auth($token));
        $body = $this->json($client->getResponse());

        foreach ($body['items'] as $item) {
            self::assertSame('QUEUED', $item['status']);
        }
        self::assertContains($queued->getId(), array_column($body['items'], 'id'));
    }

    // ── détail ───────────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_detail_returns_full_content_and_attempts(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $admin] = $this->authenticate($client, 'ROLE_ADMIN');
        $n = $this->makeNotification($admin, OutboundNotificationChannel::PUSH, OutboundNotificationStatus::SENT);

        $client->request('GET', '/api/admin/outbound-notifications/' . $n->getId(), server: $this->auth($token));
        $body = $this->json($client->getResponse());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame($n->getId(), $body['id']);
        self::assertSame('La mission d\'hier n\'a pas encore été soumise.', $body['bodyText']);
        self::assertEqualsCanonicalizing(['missionId' => 690, 'url' => '/app/i/missions/690'], $body['payload']);
        self::assertCount(1, $body['attempts']);
        self::assertSame('FCM', $body['attempts'][0]['provider']);
    }

    #[WithoutErrorHandler]
    public function test_detail_404_for_unknown_id(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_ADMIN');

        $client->request('GET', '/api/admin/outbound-notifications/999999999', server: $this->auth($token));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    // ── aucune donnée sensible ───────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_response_never_contains_a_push_endpoint_or_secret(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $admin] = $this->authenticate($client, 'ROLE_ADMIN');
        $n = $this->makeNotification($admin, OutboundNotificationChannel::PUSH, OutboundNotificationStatus::SENT);

        $client->request('GET', '/api/admin/outbound-notifications/' . $n->getId(), server: $this->auth($token));
        $raw = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('fcm.googleapis.com/fcm/send/', $raw);
        self::assertStringNotContainsString('p256dh', $raw);
        self::assertStringNotContainsString('endpoint', $raw);
        self::assertStringNotContainsString('VAPID', $raw);
    }
}
