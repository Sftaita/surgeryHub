<?php

namespace App\Tests\Functional;

use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Enum\NotificationType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot 3 (audit PWA/mobile/admin 2026-07-29) — first-ever functional coverage of
 * GET/PATCH /api/me/notification-preferences. `NotificationPreference` (Batch 15A) had
 * a resolver used internally by notification dispatch, but no reader/writer API existed
 * — this is the first UI-facing entry point for it.
 */
final class MeNotificationPreferencesControllerTest extends WebTestCase
{
    private const PASSWORD = 'MeNotifPref28!';

    private EntityManagerInterface $em;
    private array $createdIds = ['users' => [], 'preferences' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['preferences'] as $id) {
                $e = $this->em->find(NotificationPreference::class, $id);
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
        $user->setEmail('me-notif-pref-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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

    // ── AuthZ ────────────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_list_rejects_unauthenticated_request(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $client->request('GET', '/api/me/notification-preferences');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    // ── GET — defaults ───────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_list_returns_every_notification_type_with_product_defaults_when_no_row_exists(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $client->request('GET', '/api/me/notification-preferences', server: $this->auth($token));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = $this->json($client->getResponse());
        self::assertCount(count(NotificationType::cases()), $body['items']);

        $planningAlert = current(array_filter($body['items'], fn ($i) => $i['type'] === 'PLANNING_ALERT'));
        self::assertNotFalse($planningAlert);
        self::assertTrue($planningAlert['inAppEnabled']);
        self::assertTrue($planningAlert['emailEnabled'], 'PLANNING_ALERT defaults to email=true per DefaultNotificationPreferenceResolver');
        self::assertFalse($planningAlert['pushEnabled']);

        $openMission = current(array_filter($body['items'], fn ($i) => $i['type'] === 'OPEN_MISSION_AVAILABLE'));
        self::assertFalse($openMission['emailEnabled'], 'OPEN_MISSION_AVAILABLE defaults to email=false (informational)');
    }

    // ── PATCH — creates a row on first write ────────────────────────────────

    #[WithoutErrorHandler]
    public function test_patch_creates_a_preference_row_seeded_from_resolved_defaults(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $user, 'token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $client->request(
            'PATCH',
            '/api/me/notification-preferences/OPEN_MISSION_AVAILABLE',
            server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode(['emailEnabled' => true]),
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = $this->json($client->getResponse());
        self::assertTrue($body['emailEnabled']);
        self::assertTrue($body['inAppEnabled'], 'Untouched channel must keep its resolved default (in-app was already true)');
        self::assertFalse($body['pushEnabled']);

        $this->em->clear();
        $row = $this->em->getRepository(NotificationPreference::class)->findOneBy([
            'user' => $user->getId(),
            'notificationType' => NotificationType::OPEN_MISSION_AVAILABLE,
        ]);
        self::assertNotNull($row);
        $this->createdIds['preferences'][] = $row->getId();
        self::assertTrue($row->isEmailEnabled());
    }

    #[WithoutErrorHandler]
    public function test_patch_only_updates_provided_channels_on_an_existing_row(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $user, 'token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $pref = new NotificationPreference();
        $pref->setUser($user)->setNotificationType(NotificationType::PLANNING_ALERT)
            ->setInAppEnabled(true)->setEmailEnabled(true)->setPushEnabled(false);
        $this->em->persist($pref);
        $this->em->flush();
        $this->createdIds['preferences'][] = $pref->getId();

        $client->request(
            'PATCH',
            '/api/me/notification-preferences/PLANNING_ALERT',
            server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode(['pushEnabled' => true]),
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = $this->json($client->getResponse());
        self::assertTrue($body['pushEnabled']);
        self::assertTrue($body['emailEnabled'], 'Untouched channel on an existing row must be preserved');
        self::assertTrue($body['inAppEnabled']);
    }

    #[WithoutErrorHandler]
    public function test_patch_never_creates_a_duplicate_row_for_the_same_user_and_type(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $user, 'token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $client->request('PATCH', '/api/me/notification-preferences/PLANNING_ALERT', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['pushEnabled' => true]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->request('PATCH', '/api/me/notification-preferences/PLANNING_ALERT', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['pushEnabled' => false]));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(p.id)')->from(NotificationPreference::class, 'p')
            ->where('p.user = :user')->andWhere('p.notificationType = :type')
            ->setParameter('user', $user->getId())->setParameter('type', NotificationType::PLANNING_ALERT)
            ->getQuery()->getSingleScalarResult();
        self::assertSame(1, $count);

        $row = $this->em->getRepository(NotificationPreference::class)->findOneBy(['user' => $user->getId(), 'notificationType' => NotificationType::PLANNING_ALERT]);
        $this->createdIds['preferences'][] = $row->getId();
        self::assertFalse($row->isPushEnabled(), 'Second PATCH must have updated the same row, not left the first value');
    }

    #[WithoutErrorHandler]
    public function test_patch_rejects_unknown_notification_type(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $client->request('PATCH', '/api/me/notification-preferences/NOT_A_REAL_TYPE', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['pushEnabled' => true]));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_patch_never_affects_another_user_preferences(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $userA, 'token' => $tokenA] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');
        ['user' => $userB, 'token' => $tokenB] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $client->request('PATCH', '/api/me/notification-preferences/PLANNING_ALERT', server: $this->auth($tokenB, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['pushEnabled' => true]));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $rowA = $this->em->getRepository(NotificationPreference::class)->findOneBy(['user' => $userA->getId(), 'notificationType' => NotificationType::PLANNING_ALERT]);
        self::assertNull($rowA, "User B's PATCH must never create/affect a row for user A");

        $rowB = $this->em->getRepository(NotificationPreference::class)->findOneBy(['user' => $userB->getId(), 'notificationType' => NotificationType::PLANNING_ALERT]);
        self::assertNotNull($rowB);
        $this->createdIds['preferences'][] = $rowB->getId();
    }

    // ── Every role can manage its own preferences ───────────────────────────

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
    public function test_every_role_can_read_and_update_its_own_preferences(string $role): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $user, 'token' => $token] = $this->authenticate($client, $role);

        $client->request('GET', '/api/me/notification-preferences', server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode(), "GET must succeed for $role");

        $client->request('PATCH', '/api/me/notification-preferences/PLANNING_ALERT', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['pushEnabled' => true]));
        self::assertSame(200, $client->getResponse()->getStatusCode(), "PATCH must succeed for $role");

        $this->em->clear();
        $row = $this->em->getRepository(NotificationPreference::class)->findOneBy(['user' => $user->getId(), 'notificationType' => NotificationType::PLANNING_ALERT]);
        self::assertNotNull($row);
        $this->createdIds['preferences'][] = $row->getId();
    }
}
