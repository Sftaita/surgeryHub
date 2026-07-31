<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Entity\UserAuditEvent;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot 7 (revue post-rapport, 2026-07-29) — preuve d'intégration réelle que
 * `UserAdministrationService::resendInvitation()` sérialise deux requêtes
 * concurrentes pour le même utilisateur via un verrou pessimiste MySQL
 * (`SELECT ... FOR UPDATE`), et pas seulement un contrôle applicatif racy
 * (check-then-act). Contrairement aux autres tests fonctionnels de ce projet,
 * celui-ci ouvre une VRAIE seconde connexion DBAL indépendante (pas la connexion du
 * kernel de test) pour tenir un verrou pendant que la requête HTTP normale est
 * envoyée sur la connexion du kernel — la seule façon de prouver une contention
 * réelle sans faire tourner deux process PHP séparés.
 */
final class ResendInvitationConcurrencyTest extends WebTestCase
{
    private const PASSWORD = 'ResendConcurrency28!';

    private EntityManagerInterface $em;
    private array $createdUserIds = [];
    private ?Connection $rivalConnection = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if ($this->rivalConnection !== null) {
            if ($this->rivalConnection->isTransactionActive()) {
                $this->rivalConnection->rollBack();
            }
            // Restore the server default (this test lowers it globally to fail fast on
            // lock contention instead of hanging — see openRivalConnection() usage below).
            try {
                $this->rivalConnection->executeStatement('SET GLOBAL innodb_lock_wait_timeout = 50');
            } catch (\Throwable) {
                // best-effort restore only
            }
            $this->rivalConnection->close();
        }

        if (isset($this->em) && $this->em->isOpen()) {
            // resendInvitation()/changeRole() create UserAuditEvent rows (actor is NOT
            // NULL, no cascade) referencing these users — must go before the users.
            foreach ($this->createdUserIds as $id) {
                $asActor = $this->em->getRepository(UserAuditEvent::class)->findBy(['actor' => $id]);
                foreach ($asActor as $e) { $this->em->remove($e); }
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

    /** Independent second DBAL connection — same DB, deliberately not the kernel's own. */
    private function openRivalConnection(): Connection
    {
        /** @var Connection $appConnection */
        $appConnection = static::getContainer()->get(Connection::class);
        $this->rivalConnection = DriverManager::getConnection($appConnection->getParams());
        return $this->rivalConnection;
    }

    private function createInvitedInstrumentist(): User
    {
        $u = new User();
        $u->setEmail('resend-concurrency-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles(['ROLE_INSTRUMENTIST']);
        $u->setActive(true);
        $u->setInvitationToken(bin2hex(random_bytes(32)));
        $u->setInvitationExpiresAt(new \DateTimeImmutable('+48 hours'));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdUserIds[] = $u->getId();
        return $u;
    }

    private function createAdmin(): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('resend-concurrency-admin-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles(['ROLE_ADMIN']);
        $u->setActive(true);
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
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

    private function auth(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    #[WithoutErrorHandler]
    public function test_a_rival_transaction_holding_the_row_lock_blocks_resend_invitation_until_released(): void
    {
        $client = $this->boot();
        $target = $this->createInvitedInstrumentist();
        $admin = $this->createAdmin();
        $token = $this->login($client, $admin);
        $originalToken = $target->getInvitationToken();

        // 1. Lower the *server default* lock-wait timeout before opening the rival
        //    transaction: the kernel's own DBAL connection is re-established per
        //    request by the test client (SESSION-level settings on a connection
        //    grabbed beforehand don't survive that reconnect — confirmed by an earlier
        //    run of this test taking ~118s despite a SESSION-level SET), so only a
        //    GLOBAL change reliably reaches the connection actually used for the
        //    blocked HTTP request. Restored in tearDown(). Local surgicalhub_test only.
        $rival = $this->openRivalConnection();
        $rival->executeStatement('SET GLOBAL innodb_lock_wait_timeout = 2');

        // 2. The rival connection takes and HOLDS a pessimistic write lock on this
        //    exact user row — simulates a concurrent resend-invitation request that
        //    got there first and hasn't committed yet.
        $rival->beginTransaction();
        $rival->executeQuery('SELECT id FROM user WHERE id = ? FOR UPDATE', [$target->getId()]);

        // 3. The HTTP request must be blocked by the rival's lock and fail — proving
        //    UserAdministrationService::resendInvitation() really does acquire a
        //    row-level lock on this user (SELECT ... FOR UPDATE), not just an
        //    application-level check-then-act that a second request could race past.
        $client->request('POST', "/api/admin/users/{$target->getId()}/resend-invitation", server: $this->auth($token));
        self::assertGreaterThanOrEqual(500, $client->getResponse()->getStatusCode(), 'The request must fail due to lock contention while the rival transaction holds the row: ' . $client->getResponse()->getContent());

        // 4. Nothing changed while blocked — the rival never committed anything, and the
        //    app's own transaction never got far enough to persist either.
        $this->em->clear();
        $stillLocked = $this->em->find(User::class, $target->getId());
        self::assertSame($originalToken, $stillLocked->getInvitationToken(), 'Token must be unchanged while the row was contended');
        self::assertNull($stillLocked->getInvitationLastSentAt());

        // 5. Release the rival's lock.
        $rival->rollBack();

        // 6. A fresh request now succeeds normally, proving the lock (not some other
        //    failure) was the actual cause of the earlier rejection.
        $client->request('POST', "/api/admin/users/{$target->getId()}/resend-invitation", server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        $this->em->clear();
        $afterRelease = $this->em->find(User::class, $target->getId());
        self::assertNotSame($originalToken, $afterRelease->getInvitationToken());
    }
}
