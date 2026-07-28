<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Onboarding instrumentiste — POST /api/me/onboarding/complete + exposition sur GET /api/me. */
final class MeControllerTest extends WebTestCase
{
    private const PASSWORD = 'MeOnboard28!';

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
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('meonb-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
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
        self::assertArrayHasKey('token', $data, 'Login failed: ' . $client->getResponse()->getContent());
        return $data['token'];
    }

    private function request(KernelBrowser $client, string $method, string $uri, string $token): Response
    {
        $client->request($method, $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
        return $client->getResponse();
    }

    public function test_me_exposes_instrumentist_onboarding_completed_false_by_default(): void
    {
        $client = $this->boot();
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $instr);

        $res = $this->request($client, 'GET', '/api/me', $token);
        self::assertSame(Response::HTTP_OK, $res->getStatusCode());
        $data = json_decode($res->getContent(), true);
        self::assertArrayHasKey('instrumentistOnboardingCompleted', $data);
        self::assertFalse($data['instrumentistOnboardingCompleted']);
    }

    public function test_instrumentist_can_complete_own_onboarding(): void
    {
        $client = $this->boot();
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $instr);

        $res = $this->request($client, 'POST', '/api/me/onboarding/complete', $token);
        self::assertSame(Response::HTTP_OK, $res->getStatusCode());
        $data = json_decode($res->getContent(), true);
        self::assertTrue($data['instrumentistOnboardingCompleted']);

        $follow = $this->request($client, 'GET', '/api/me', $token);
        self::assertTrue(json_decode($follow->getContent(), true)['instrumentistOnboardingCompleted']);
    }

    public function test_completing_onboarding_is_idempotent(): void
    {
        $client = $this->boot();
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $instr);

        $this->request($client, 'POST', '/api/me/onboarding/complete', $token);
        $userId = $instr->getId();
        $this->em->clear();
        $firstCompletedAt = $this->em->find(User::class, $userId)->getInstrumentistOnboardingCompletedAt();

        // Second call must not move the timestamp forward.
        $this->request($client, 'POST', '/api/me/onboarding/complete', $token);
        $this->em->clear();
        $secondCompletedAt = $this->em->find(User::class, $userId)->getInstrumentistOnboardingCompletedAt();

        self::assertEquals($firstCompletedAt, $secondCompletedAt);
    }

    public function test_manager_cannot_complete_instrumentist_onboarding(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);

        $res = $this->request($client, 'POST', '/api/me/onboarding/complete', $token);
        self::assertSame(Response::HTTP_FORBIDDEN, $res->getStatusCode());
    }

    public function test_admin_cannot_complete_instrumentist_onboarding(): void
    {
        $client = $this->boot();
        $admin = $this->createUser('ROLE_ADMIN');
        $token = $this->login($client, $admin);

        $res = $this->request($client, 'POST', '/api/me/onboarding/complete', $token);
        self::assertSame(Response::HTTP_FORBIDDEN, $res->getStatusCode());
    }

    public function test_completing_onboarding_only_affects_the_authenticated_user(): void
    {
        $client = $this->boot();
        $instrA = $this->createUser('ROLE_INSTRUMENTIST');
        $instrB = $this->createUser('ROLE_INSTRUMENTIST');
        $tokenA = $this->login($client, $instrA);

        $this->request($client, 'POST', '/api/me/onboarding/complete', $tokenA);

        $this->em->clear();
        self::assertNotNull($this->em->find(User::class, $instrA->getId())->getInstrumentistOnboardingCompletedAt());
        self::assertNull($this->em->find(User::class, $instrB->getId())->getInstrumentistOnboardingCompletedAt());
    }

    public function test_onboarding_completion_requires_authentication(): void
    {
        $client = $this->boot();
        $client->request('POST', '/api/me/onboarding/complete', server: ['CONTENT_TYPE' => 'application/json']);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }
}
