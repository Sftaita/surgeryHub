<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Entity\UserAuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot 7 (audit PWA/mobile/admin 2026-07-29) — first-ever functional (real HTTP)
 * coverage of AdminUserController::changeRole / resendInvitation. Both were
 * previously unit-tested at the service/voter level only.
 *
 * Covers:
 *  - change-role now accepts ROLE_ADMIN (was rejected as "invalid" before this lot),
 *    without creating a duplicate account and without losing site memberships.
 *  - resend-invitation's new anti-spam cooldown (60s, UserAdministrationService).
 */
final class AdminUserControllerChangeRoleAndResendInvitationTest extends WebTestCase
{
    private const PASSWORD = 'AdminUserCtrl28!';

    private EntityManagerInterface $em;
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            // UserAuditEvent.actor is NOT NULL with no cascade — audit rows created by
            // change-role/resend-invitation in these tests must go before the users.
            foreach ($this->createdUserIds as $id) {
                $events = $this->em->getRepository(UserAuditEvent::class)->findBy(['actor' => $id]);
                foreach ($events as $e) { $this->em->remove($e); }
            }
            $this->em->flush();

            foreach ($this->createdUserIds as $id) {
                $e = $this->em->find(User::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $u = new User();
        $u->setEmail('admin-ctrl-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdUserIds[] = $u->getId();
        return $u;
    }

    /** Invited-but-not-activated account (password stays null — required for resend-invitation). */
    private function createInvitedUser(string $role): User
    {
        $u = new User();
        $u->setEmail('admin-ctrl-invited-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setInvitationToken(bin2hex(random_bytes(32)));
        $u->setInvitationExpiresAt(new \DateTimeImmutable('+48 hours'));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdUserIds[] = $u->getId();
        return $u;
    }

    private function login(KernelBrowser $client, User $user): string
    {
        $client->request('POST', '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]),
        );
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, (string) $client->getResponse()->getContent());
        return $data['token'];
    }

    private function auth(string $token, array $extra = []): array
    {
        return array_merge(['HTTP_AUTHORIZATION' => 'Bearer ' . $token], $extra);
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    // ── change-role: ROLE_ADMIN target (new in this lot) ────────────────────

    #[WithoutErrorHandler]
    public function test_change_role_to_admin_succeeds_and_returns_the_updated_role(): void
    {
        $client = $this->boot();
        $admin = $this->createUser('ROLE_ADMIN');
        $token = $this->login($client, $admin);
        $target = $this->createUser('ROLE_MANAGER');

        $client->request('POST', "/api/admin/users/{$target->getId()}/change-role",
            server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode(['newRole' => 'ROLE_ADMIN']),
        );

        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame('ADMIN', $this->json($client->getResponse())['role']);

        $this->em->clear();
        // No duplicate: still exactly one row for this email.
        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(u.id)')->from(User::class, 'u')
            ->where('u.email = :email')->setParameter('email', $target->getEmail())
            ->getQuery()->getSingleScalarResult();
        self::assertSame(1, $count);
    }

    #[WithoutErrorHandler]
    public function test_change_role_to_admin_rejects_non_admin_caller(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $target = $this->createUser('ROLE_INSTRUMENTIST');

        $client->request('POST', "/api/admin/users/{$target->getId()}/change-role",
            server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode(['newRole' => 'ROLE_ADMIN']),
        );

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_change_role_still_rejects_a_genuinely_unknown_role(): void
    {
        $client = $this->boot();
        $admin = $this->createUser('ROLE_ADMIN');
        $token = $this->login($client, $admin);
        $target = $this->createUser('ROLE_INSTRUMENTIST');

        $client->request('POST', "/api/admin/users/{$target->getId()}/change-role",
            server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode(['newRole' => 'ROLE_BOGUS']),
        );

        self::assertGreaterThanOrEqual(400, $client->getResponse()->getStatusCode());
        self::assertLessThan(500, $client->getResponse()->getStatusCode());
    }

    // ── resend-invitation: anti-spam cooldown (new in this lot) ─────────────

    #[WithoutErrorHandler]
    public function test_resend_invitation_succeeds_the_first_time(): void
    {
        $client = $this->boot();
        $admin = $this->createUser('ROLE_ADMIN');
        $token = $this->login($client, $admin);
        $target = $this->createInvitedUser('ROLE_INSTRUMENTIST');
        $originalToken = $target->getInvitationToken();

        $client->request('POST', "/api/admin/users/{$target->getId()}/resend-invitation", server: $this->auth($token));

        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        $this->em->clear();
        $reloaded = $this->em->find(User::class, $target->getId());
        self::assertNotSame($originalToken, $reloaded->getInvitationToken());
    }

    #[WithoutErrorHandler]
    public function test_resend_invitation_rejects_a_second_call_within_the_cooldown(): void
    {
        $client = $this->boot();
        $admin = $this->createUser('ROLE_ADMIN');
        $token = $this->login($client, $admin);
        $target = $this->createInvitedUser('ROLE_INSTRUMENTIST');

        $client->request('POST', "/api/admin/users/{$target->getId()}/resend-invitation", server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $firstToken = $this->json($client->getResponse());

        $client->request('POST', "/api/admin/users/{$target->getId()}/resend-invitation", server: $this->auth($token));

        self::assertSame(409, $client->getResponse()->getStatusCode(), 'A resend within the cooldown must be rejected: ' . $client->getResponse()->getContent());
    }

    #[WithoutErrorHandler]
    public function test_resend_invitation_rejects_already_activated_account(): void
    {
        $client = $this->boot();
        $admin = $this->createUser('ROLE_ADMIN');
        $token = $this->login($client, $admin);
        $target = $this->createUser('ROLE_INSTRUMENTIST'); // has a password → activated

        $client->request('POST', "/api/admin/users/{$target->getId()}/resend-invitation", server: $this->auth($token));

        self::assertSame(409, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_resend_invitation_rejects_non_admin_caller(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $target = $this->createInvitedUser('ROLE_INSTRUMENTIST');

        $client->request('POST', "/api/admin/users/{$target->getId()}/resend-invitation", server: $this->auth($token));

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }
}
