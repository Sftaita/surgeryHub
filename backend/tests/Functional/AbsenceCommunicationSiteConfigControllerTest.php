<?php

namespace App\Tests\Functional;

use App\Entity\AbsenceCommunicationSiteConfig;
use App\Entity\Hospital;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Communication des absences chirurgiens — Lot A (D-114). Contrat CRUD + RBAC de
 * GET/PATCH /api/planning/absence-communication-settings.
 */
final class AbsenceCommunicationSiteConfigControllerTest extends WebTestCase
{
    private const PASSWORD = 'AbsCommSettingsTest123!';

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
            foreach ($this->createdIds['sites'] as $siteId) {
                $site = $this->em->find(Hospital::class, $siteId);
                if ($site === null) { continue; }
                foreach ($this->em->createQueryBuilder()->select('c')->from(AbsenceCommunicationSiteConfig::class, 'c')->where('c.site = :s')->setParameter('s', $site)->getQuery()->getResult() as $c) {
                    $this->em->remove($c);
                }
            }
            $this->em->flush();

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
        $user->setEmail('abscommsettings-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('AbsCommSettings Site ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    #[WithoutErrorHandler]
    public function test_list_returns_false_by_default_for_a_site_without_explicit_config(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $site = $this->makeSite();

        $client->request('GET', '/api/planning/absence-communication-settings', server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = $this->json($client->getResponse());

        $item = null;
        foreach ($data['items'] as $i) {
            if ($i['site']['id'] === $site->getId()) { $item = $i; break; }
        }
        self::assertNotNull($item);
        self::assertFalse($item['notifyColleaguesEnabled']);
    }

    #[WithoutErrorHandler]
    public function test_patch_enables_and_persists_the_toggle(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $site = $this->makeSite();

        $client->request('PATCH', "/api/planning/absence-communication-settings/{$site->getId()}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['notifyColleaguesEnabled' => true]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertTrue($this->json($client->getResponse())['notifyColleaguesEnabled']);

        $client->request('GET', '/api/planning/absence-communication-settings', server: $this->auth($token));
        $data = $this->json($client->getResponse());
        $item = null;
        foreach ($data['items'] as $i) {
            if ($i['site']['id'] === $site->getId()) { $item = $i; break; }
        }
        self::assertTrue($item['notifyColleaguesEnabled']);
    }

    #[WithoutErrorHandler]
    public function test_patch_missing_field_returns_400(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $site = $this->makeSite();

        $client->request('PATCH', "/api/planning/absence-communication-settings/{$site->getId()}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([]));
        self::assertSame(400, $client->getResponse()->getStatusCode(), 'same convention as ShiftPeriodController (BadRequestHttpException)');
    }

    #[WithoutErrorHandler]
    public function test_patch_unknown_site_returns_404(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $client->request('PATCH', '/api/planning/absence-communication-settings/999999999', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['notifyColleaguesEnabled' => true]));
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_instrumentist_is_forbidden(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $client->request('GET', '/api/planning/absence-communication-settings', server: $this->auth($token));
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }
}
