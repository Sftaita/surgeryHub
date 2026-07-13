<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Entity\UserAuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Real-HTTP coverage for PATCH /api/users/{id}/email — the manager/admin-facing
 * secure email change used from the instrumentist/surgeon drawers.
 */
final class UserEmailControllerTest extends WebTestCase
{
    private const PASSWORD = 'EmailChangeTest123!';

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
                $user = $this->em->find(User::class, $id);
                if ($user === null) {
                    continue;
                }
                $events = $this->em->createQueryBuilder()
                    ->select('e')->from(UserAuditEvent::class, 'e')
                    ->where('e.actor = :u OR e.targetUser = :u')
                    ->setParameter('u', $user)
                    ->getQuery()->getResult();
                foreach ($events as $event) {
                    $this->em->remove($event);
                }
            }
            $this->em->flush();

            foreach ($this->createdUserIds as $id) {
                $user = $this->em->find(User::class, $id);
                if ($user !== null) {
                    $this->em->remove($user);
                }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    public function test_manager_can_change_instrumentist_email(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST', 'old-instr@example.com');

        $client->request('PATCH', "/api/users/{$instrumentist->getId()}/email", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'email' => 'new-instr@example.com',
        ]));

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $data = $this->json($client->getResponse());
        self::assertSame('new-instr@example.com', $data['user']['email']);
        self::assertSame([], $data['warnings']);
    }

    public function test_manager_can_change_surgeon_email(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');
        $surgeon = $this->makeUser('ROLE_SURGEON', 'old-surgeon@example.com');

        $client->request('PATCH', "/api/users/{$surgeon->getId()}/email", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'email' => 'new-surgeon@example.com',
        ]));

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $data = $this->json($client->getResponse());
        self::assertSame('new-surgeon@example.com', $data['user']['email']);
    }

    public function test_admin_can_change_email(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_ADMIN');
        $target = $this->makeUser('ROLE_SURGEON', 'admin-target@example.com');

        $client->request('PATCH', "/api/users/{$target->getId()}/email", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'email' => 'admin-changed@example.com',
        ]));

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
    }

    public function test_instrumentist_cannot_change_another_users_email(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');
        $target = $this->makeUser('ROLE_SURGEON', 'protected@example.com');

        $client->request('PATCH', "/api/users/{$target->getId()}/email", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'email' => 'hijacked@example.com',
        ]));

        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
    }

    public function test_surgeon_cannot_change_another_users_email(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_SURGEON');
        $target = $this->makeUser('ROLE_INSTRUMENTIST', 'protected2@example.com');

        $client->request('PATCH', "/api/users/{$target->getId()}/email", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'email' => 'hijacked2@example.com',
        ]));

        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
    }

    public function test_unknown_user_returns_404(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $client->request('PATCH', '/api/users/999999999/email', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'email' => 'whoever@example.com',
        ]));

        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
        $data = $this->json($client->getResponse());
        self::assertSame('NOT_FOUND', $data['error']['code']);
    }

    public function test_duplicate_email_returns_409(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');
        $existing = $this->makeUser('ROLE_SURGEON', 'already-taken@example.com');
        $target = $this->makeUser('ROLE_SURGEON', 'to-change@example.com');

        $client->request('PATCH', "/api/users/{$target->getId()}/email", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'email' => $existing->getEmail(),
        ]));

        self::assertSame(Response::HTTP_CONFLICT, $client->getResponse()->getStatusCode());
        $data = $this->json($client->getResponse());
        self::assertSame('CONFLICT', $data['error']['code']);
    }

    public function test_invalid_email_returns_422(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');
        $target = $this->makeUser('ROLE_SURGEON', 'valid-target@example.com');

        $client->request('PATCH', "/api/users/{$target->getId()}/email", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'email' => 'not-an-email',
        ]));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        $data = $this->json($client->getResponse());
        self::assertSame('VALIDATION_FAILED', $data['error']['code']);
    }

    public function test_same_email_returns_400(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');
        $target = $this->makeUser('ROLE_SURGEON', 'unchanged@example.com');

        $client->request('PATCH', "/api/users/{$target->getId()}/email", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'email' => 'unchanged@example.com',
        ]));

        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
    }

    /** @return array{user: User, token: string} */
    private function authenticate(KernelBrowser $client, string $role): array
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('emailchange-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $user->setRoles([$role]);
        $user->setActive(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
        $this->createdUserIds[] = $user->getId();

        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, (string) $client->getResponse()->getContent());

        return ['user' => $user, 'token' => $data['token']];
    }

    private function auth(string $token, array $extra = []): array
    {
        return array_merge(['HTTP_AUTHORIZATION' => 'Bearer ' . $token], $extra);
    }

    private function makeUser(string $role, string $email): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setRoles([$role]);
        $u->setActive(true);
        $this->em->persist($u);
        $this->em->flush();
        $this->createdUserIds[] = $u->getId();
        return $u;
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }
}
