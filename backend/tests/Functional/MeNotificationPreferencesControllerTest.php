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
    /** @param string|list<string> $role un rôle, ou plusieurs (utilisateur multi-rôles) */
    private function authenticate(KernelBrowser $client, string|array $role): array
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('me-notif-pref-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $user->setRoles((array) $role);
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
        // Préférences selon le rôle : uniquement les types configurables qu'une instrumentiste reçoit.
        $configurable = array_filter(NotificationType::cases(), static fn (NotificationType $t) => $t->isConfigurable() && $t->concernsRoles(['ROLE_INSTRUMENTIST']));
        self::assertCount(count($configurable), $body['items']);
        self::assertNotContains('ACCOUNT_INVITATION', array_column($body['items'], 'type'), 'H5a: mandatory email, never offered as a switch');
        self::assertNotContains('ABSENCE_POOL_MISSION_AVAILABLE', array_column($body['items'], 'type'), 'H3: retired type, never offered as a switch');
        // D-143 — les relances d'encodage (obligations de service) ne sont pas configurables,
        // et l'ancien type ENCODING_REMINDER_D1 (retiré) non plus.
        self::assertNotContains('ENCODING_REMINDER', array_column($body['items'], 'type'));
        self::assertNotContains('ENCODING_ESCALATION', array_column($body['items'], 'type'));
        self::assertNotContains('ENCODING_REMINDER_D1', array_column($body['items'], 'type'));

        // PLANNING_ALERT ne concerne que les managers : jamais proposé à une instrumentiste.
        self::assertNotContains('PLANNING_ALERT', array_column($body['items'], 'type'));

        $openMission = current(array_filter($body['items'], fn ($i) => $i['type'] === 'OPEN_MISSION_AVAILABLE'));
        // H3 (D-129) — a mission becoming available is actionable: every channel on by default.
        self::assertTrue($openMission['emailEnabled'], 'OPEN_MISSION_AVAILABLE defaults to email=true (H3)');
        self::assertTrue($openMission['pushEnabled'], 'OPEN_MISSION_AVAILABLE defaults to push=true (H3)');
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
            content: json_encode(['emailEnabled' => false]),
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = $this->json($client->getResponse());
        self::assertFalse($body['emailEnabled']);
        self::assertTrue($body['inAppEnabled'], 'Untouched channel must keep its resolved default (in-app was already true)');
        self::assertTrue($body['pushEnabled'], 'Untouched channel must keep its resolved default (push on since H3)');

        $this->em->clear();
        $row = $this->em->getRepository(NotificationPreference::class)->findOneBy([
            'user' => $user->getId(),
            'notificationType' => NotificationType::OPEN_MISSION_AVAILABLE,
        ]);
        self::assertNotNull($row);
        $this->createdIds['preferences'][] = $row->getId();
        self::assertFalse($row->isEmailEnabled());
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

    // ── Préférences selon le rôle (types réellement reçus, NotificationType::audienceRoles()) ──

    /** @return list<string> */
    private function listedTypes(KernelBrowser $client, string $token): array
    {
        $client->request('GET', '/api/me/notification-preferences', server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        return array_column($this->json($client->getResponse())['items'], 'type');
    }

    #[WithoutErrorHandler]
    public function test_each_role_only_sees_the_types_it_actually_receives(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $surgeon = $this->listedTypes($client, $this->authenticate($client, 'ROLE_SURGEON')['token']);
        foreach (['ENCODING_SUBMITTED', 'ENCODING_CORRECTION_REQUESTED', 'SURGEON_POST_COVERED', 'ENCODING_ANOMALY_RESOLVED'] as $type) {
            self::assertContains($type, $surgeon, "chirurgien : $type");
        }
        foreach (['ENCODING_UPDATED_BY_SURGEON', 'ASSIGNMENT_REFUSED_BY_SURGEON', 'ASSIGNMENT_REVIEW_REQUESTED', 'PLANNING_ALERT', 'OPEN_MISSION_AVAILABLE'] as $type) {
            self::assertNotContains($type, $surgeon, "chirurgien : jamais $type");
        }

        $instrumentist = $this->listedTypes($client, $this->authenticate($client, 'ROLE_INSTRUMENTIST')['token']);
        foreach (['ENCODING_UPDATED_BY_SURGEON', 'ENCODING_CORRECTION_REQUESTED', 'OPEN_MISSION_AVAILABLE', 'PLANNING_MISSION_RELEASED'] as $type) {
            self::assertContains($type, $instrumentist, "instrumentiste : $type");
        }
        foreach (['ASSIGNMENT_REFUSED_BY_SURGEON', 'ENCODING_SUBMITTED', 'PLANNING_ALERT'] as $type) {
            self::assertNotContains($type, $instrumentist, "instrumentiste : jamais $type");
        }

        $manager = $this->listedTypes($client, $this->authenticate($client, 'ROLE_MANAGER')['token']);
        foreach (['ASSIGNMENT_REFUSED_BY_SURGEON', 'ASSIGNMENT_REVIEW_REQUESTED', 'PLANNING_ALERT', 'CATALOGUE_REQUEST_CREATED', 'CATALOGUE_REQUEST_RESOLVED'] as $type) {
            self::assertContains($type, $manager, "manager : $type");
        }
        foreach (['ENCODING_UPDATED_BY_SURGEON', 'ENCODING_SUBMITTED', 'OPEN_MISSION_AVAILABLE'] as $type) {
            self::assertNotContains($type, $manager, "manager : jamais $type");
        }

        $admin = $this->listedTypes($client, $this->authenticate($client, 'ROLE_ADMIN')['token']);
        self::assertSame($manager, $admin, 'un admin voit exactement les types manager');
    }

    #[WithoutErrorHandler]
    public function test_a_person_with_several_roles_sees_the_union_and_stored_preferences_are_kept(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        ['token' => $multiToken] = $this->authenticate($client, ['ROLE_SURGEON', 'ROLE_MANAGER']);
        $multi = $this->listedTypes($client, $multiToken);
        $expected = array_values(array_map(static fn (NotificationType $t) => $t->value, array_filter(
            NotificationType::cases(),
            static fn (NotificationType $t) => $t->isConfigurable() && ($t->concernsRoles(['ROLE_SURGEON']) || $t->concernsRoles(['ROLE_MANAGER'])),
        )));
        self::assertSame($expected, $multi, 'union des types chirurgien et manager, dans l\'ordre du catalogue');
        self::assertContains('ENCODING_SUBMITTED', $multi);
        self::assertContains('ASSIGNMENT_REFUSED_BY_SURGEON', $multi);

        // Une préférence déjà enregistrée sur un type désormais non proposé n'est ni supprimée ni modifiée.
        ['user' => $user, 'token' => $token] = $this->authenticate($client, 'ROLE_SURGEON');
        $pref = (new NotificationPreference())->setUser($this->em->getReference(User::class, $user->getId()))->setNotificationType(NotificationType::OPEN_MISSION_AVAILABLE)
            ->setInAppEnabled(false)->setEmailEnabled(false)->setPushEnabled(false);
        $this->em->persist($pref);
        $this->em->flush();
        $this->createdIds['preferences'][] = $pref->getId();

        self::assertNotContains('OPEN_MISSION_AVAILABLE', $this->listedTypes($client, $token));
        $this->em->clear();
        $kept = $this->em->find(NotificationPreference::class, $pref->getId());
        self::assertNotNull($kept, 'préférence enregistrée conservée');
        self::assertFalse($kept->isInAppEnabled());
    }

    #[WithoutErrorHandler]
    public function test_types_no_code_produces_any_more_are_hidden_for_every_role_and_their_stored_preferences_kept(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $retired = ['PLANNING_MISSION_ADDED', 'PLANNING_MISSION_UPDATED', 'ABSENCE_INSTRUMENTIST_RELEASED',
            'ABSENCE_OCCURRENCE_CANCELLED_MGR', 'ABSENCE_OCCURRENCE_RESTORED_MGR', 'MISSION_RESTORED_MGR'];

        ['user' => $user, 'token' => $token] = $this->authenticate($client, ['ROLE_SURGEON', 'ROLE_INSTRUMENTIST', 'ROLE_MANAGER']);
        $pref = (new NotificationPreference())->setUser($user)->setNotificationType(NotificationType::PLANNING_MISSION_ADDED)
            ->setInAppEnabled(false)->setEmailEnabled(true)->setPushEnabled(false);
        $this->em->persist($pref);
        $this->em->flush();
        $this->createdIds['preferences'][] = $pref->getId();

        $listed = $this->listedTypes($client, $token);
        self::assertSame([], array_values(array_intersect($retired, $listed)), 'aucun type sans producteur proposé, même à tous les rôles');
        self::assertContains('PLANNING_CHANGE_SUMMARY', $listed);
        self::assertContains('ABSENCE_IMPACT_SUMMARY', $listed);

        $this->em->clear();
        $kept = $this->em->find(NotificationPreference::class, $pref->getId());
        self::assertNotNull($kept, 'préférence historique conservée en base');
        self::assertFalse($kept->isInAppEnabled());
        self::assertTrue($kept->isEmailEnabled());
    }
}
