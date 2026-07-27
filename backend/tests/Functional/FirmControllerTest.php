<?php

namespace App\Tests\Functional;

use App\Entity\Firm;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * /api/firms — CRUD du référentiel firmes. Régression corrigée : le contrôleur
 * vérifiait littéralement `denyAccessUnlessGranted('ROLE_MANAGER')`, seul contrôleur
 * du catalogue financier à ne pas passer par `BillingVoter::MANAGE` (ROLE_MANAGER OU
 * ROLE_ADMIN) — un compte ADMIN seul recevait un 403 sur create/update/delete alors
 * que le reste de l'architecture (BillingVoter, MissionVoter::isManager, ...) traite
 * systématiquement ADMIN comme équivalent MANAGER. Ces tests figent le comportement
 * attendu pour empêcher toute régression future.
 */
final class FirmControllerTest extends WebTestCase
{
    private const PASSWORD = 'FirmCtrl15!';

    private EntityManagerInterface $em;
    private array $createdFirmIds = [];
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdFirmIds as $id) {
                $e = $this->em->find(Firm::class, $id);
                if ($e !== null) { $this->em->remove($e); }
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
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('firmctrl-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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

    private function request(KernelBrowser $client, string $method, string $uri, string $token, array $body = []): Response
    {
        $client->request($method, $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: $method === 'GET' ? null : json_encode($body),
        );
        return $client->getResponse();
    }

    public function test_instrumentist_cannot_create_firm(): void
    {
        $client = $this->boot();
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $instr);

        $response = $this->request($client, 'POST', '/api/firms', $token, [
            'name' => 'Firm-' . bin2hex(random_bytes(4)),
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_manager_can_create_update_and_delete_firm(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);

        $created = $this->request($client, 'POST', '/api/firms', $token, [
            'name' => 'Firm-' . bin2hex(random_bytes(4)),
        ]);
        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode(), $created->getContent());
        $firm = json_decode($created->getContent(), true);
        $this->createdFirmIds[] = $firm['id'];

        $updated = $this->request($client, 'PATCH', "/api/firms/{$firm['id']}", $token, ['active' => false]);
        self::assertSame(Response::HTTP_OK, $updated->getStatusCode(), $updated->getContent());
        self::assertFalse(json_decode($updated->getContent(), true)['active']);

        $deleted = $this->request($client, 'DELETE', "/api/firms/{$firm['id']}", $token);
        self::assertSame(Response::HTTP_NO_CONTENT, $deleted->getStatusCode());
        $this->createdFirmIds = array_diff($this->createdFirmIds, [$firm['id']]);
    }

    /**
     * Régression D-072-EPIC-UX : compte ADMIN seul (pas de ROLE_MANAGER), doit pouvoir
     * gérer le catalogue firmes exactement comme un manager.
     */
    public function test_admin_can_create_update_and_delete_firm(): void
    {
        $client = $this->boot();
        $admin = $this->createUser('ROLE_ADMIN');
        $token = $this->login($client, $admin);

        $created = $this->request($client, 'POST', '/api/firms', $token, [
            'name' => 'Firm-' . bin2hex(random_bytes(4)),
        ]);
        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode(), $created->getContent());
        $firm = json_decode($created->getContent(), true);
        $this->createdFirmIds[] = $firm['id'];

        $updated = $this->request($client, 'PATCH', "/api/firms/{$firm['id']}", $token, ['representative' => 'Jean Dupont']);
        self::assertSame(Response::HTTP_OK, $updated->getStatusCode(), $updated->getContent());
        self::assertSame('Jean Dupont', json_decode($updated->getContent(), true)['representative']);

        $deleted = $this->request($client, 'DELETE', "/api/firms/{$firm['id']}", $token);
        self::assertSame(Response::HTTP_NO_CONTENT, $deleted->getStatusCode());
        $this->createdFirmIds = array_diff($this->createdFirmIds, [$firm['id']]);
    }

    public function test_duplicate_firm_name_is_rejected(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $name = 'Firm-' . bin2hex(random_bytes(4));

        $first = $this->request($client, 'POST', '/api/firms', $token, ['name' => $name]);
        self::assertSame(Response::HTTP_CREATED, $first->getStatusCode());
        $this->createdFirmIds[] = json_decode($first->getContent(), true)['id'];

        $second = $this->request($client, 'POST', '/api/firms', $token, ['name' => $name]);
        self::assertSame(Response::HTTP_CONFLICT, $second->getStatusCode());
    }
}
