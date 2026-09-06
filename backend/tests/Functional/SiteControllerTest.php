<?php

namespace App\Tests\Functional;

use App\Entity\Hospital;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Communication des absences chirurgiens — revue post-déploiement (D-114). Coordonnées
 * "gestion du bloc" de l'établissement (`blockManagementContactEmail`/`blockManagementContactCc`),
 * déplacées depuis `AbsenceCommunicationSiteConfig` : ce sont des données organisationnelles
 * propres à l'établissement, gérées ici (`PATCH /api/sites/{id}`), jamais depuis
 * `AbsenceCommunicationSiteConfigController` (voir AbsenceCommunicationSiteConfigControllerTest
 * pour le comportement/délai de la fonctionnalité "gestion du bloc").
 */
final class SiteControllerTest extends WebTestCase
{
    private const PASSWORD = 'SiteControllerTest123!';

    private EntityManagerInterface $em;
    private array $createdIds = ['users' => [], 'sites' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['sites'] as $id) {
                $e = $this->em->find(Hospital::class, $id);
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

    private function authenticate($client, string $role): array
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('sitecontroller-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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

    private function auth(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('SiteController Test ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    // ── Contacts "gestion du bloc" ───────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_patch_valid_contact_email_and_cc_are_persisted(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $site = $this->makeSite();

        $client->request('PATCH', "/api/sites/{$site->getId()}", server: $this->auth($token) + ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'blockManagementContactEmail' => 'bloc@example.com',
            'blockManagementContactCc' => ['cc1@example.com', 'cc2@example.com'],
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = $this->json($client->getResponse());
        self::assertSame('bloc@example.com', $data['blockManagementContactEmail']);
        self::assertSame(['cc1@example.com', 'cc2@example.com'], $data['blockManagementContactCc']);
    }

    #[WithoutErrorHandler]
    public function test_patch_invalid_contact_email_returns_400(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $site = $this->makeSite();

        $client->request('PATCH', "/api/sites/{$site->getId()}", server: $this->auth($token) + ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'blockManagementContactEmail' => 'not-an-email',
        ]));
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_patch_invalid_cc_email_returns_400(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $site = $this->makeSite();

        $client->request('PATCH', "/api/sites/{$site->getId()}", server: $this->auth($token) + ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'blockManagementContactEmail' => 'bloc@example.com',
            'blockManagementContactCc' => ['not-an-email'],
        ]));
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_patch_cc_duplicates_and_primary_are_normalized_silently(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $site = $this->makeSite();

        $client->request('PATCH', "/api/sites/{$site->getId()}", server: $this->auth($token) + ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'blockManagementContactEmail' => 'bloc@example.com',
            'blockManagementContactCc' => ['cc1@example.com', 'CC1@example.com', 'Bloc@Example.com', ' cc2@example.com '],
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = $this->json($client->getResponse());
        self::assertSame(['cc1@example.com', 'cc2@example.com'], $data['blockManagementContactCc'], 'dedup insensible à la casse, adresse principale retirée, jamais un rejet pour une variante triviale');
    }

    #[WithoutErrorHandler]
    public function test_patch_touching_only_cc_leaves_email_untouched(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $site = $this->makeSite();

        $client->request('PATCH', "/api/sites/{$site->getId()}", server: $this->auth($token) + ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'blockManagementContactEmail' => 'bloc@example.com',
            'blockManagementContactCc' => ['cc1@example.com'],
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('PATCH', "/api/sites/{$site->getId()}", server: $this->auth($token) + ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'blockManagementContactCc' => ['cc2@example.com'],
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = $this->json($client->getResponse());
        self::assertSame('bloc@example.com', $data['blockManagementContactEmail'], 'jamais écrasé par un patch qui ne le mentionne pas');
        self::assertSame(['cc2@example.com'], $data['blockManagementContactCc']);
    }

    #[WithoutErrorHandler]
    public function test_patch_clearing_contact_email_sets_it_null(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $site = $this->makeSite();

        $client->request('PATCH', "/api/sites/{$site->getId()}", server: $this->auth($token) + ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'blockManagementContactEmail' => 'bloc@example.com',
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('PATCH', "/api/sites/{$site->getId()}", server: $this->auth($token) + ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'blockManagementContactEmail' => '',
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNull($this->json($client->getResponse())['blockManagementContactEmail']);
    }

    #[WithoutErrorHandler]
    public function test_new_site_has_no_contact_by_default(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $site = $this->makeSite();

        $client->request('GET', '/api/sites', server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = $this->json($client->getResponse());
        $item = null;
        foreach ($data as $i) {
            if ($i['id'] === $site->getId()) { $item = $i; break; }
        }
        self::assertNotNull($item);
        self::assertNull($item['blockManagementContactEmail']);
        self::assertSame([], $item['blockManagementContactCc']);
    }

    #[WithoutErrorHandler]
    public function test_instrumentist_is_forbidden_from_patching_contact(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $site = $this->makeSite();

        $client->request('PATCH', "/api/sites/{$site->getId()}", server: $this->auth($token) + ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'blockManagementContactEmail' => 'bloc@example.com',
        ]));
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }
}
