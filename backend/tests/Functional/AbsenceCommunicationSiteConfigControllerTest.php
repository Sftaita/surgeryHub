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
 * Communication des absences chirurgiens — Lot A/B (D-114). Contrat CRUD + RBAC de
 * GET/PATCH /api/planning/absence-communication-settings, y compris la validation des 4
 * champs "gestion du bloc" introduits en Lot B.
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

    /**
     * Lot B — le contrat est devenu une mise à jour PARTIELLE (permet de ne toucher que
     * "Libération de salle" ou que "Gestion du bloc" indépendamment) : un body vide n'a
     * plus rien d'invalide, contrairement au contrat strict du Lot A qui exigeait toujours
     * `notifyColleaguesEnabled`.
     */
    #[WithoutErrorHandler]
    public function test_patch_empty_body_is_a_valid_noop(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $site = $this->makeSite();

        $client->request('PATCH', "/api/planning/absence-communication-settings/{$site->getId()}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    // ── Lot B — validation "gestion du bloc" ────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_patch_enabling_block_management_without_email_to_returns_400(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $site = $this->makeSite();

        $client->request('PATCH', "/api/planning/absence-communication-settings/{$site->getId()}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'notifyBlockManagementEnabled' => true,
        ]));
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_patch_enabling_block_management_with_invalid_email_returns_400(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $site = $this->makeSite();

        $client->request('PATCH', "/api/planning/absence-communication-settings/{$site->getId()}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'notifyBlockManagementEnabled' => true,
            'blockManagementEmailTo' => 'not-an-email',
            'blockManagementDelayDays' => 14,
        ]));
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_patch_enabling_block_management_without_delay_returns_400(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $site = $this->makeSite();

        $client->request('PATCH', "/api/planning/absence-communication-settings/{$site->getId()}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'notifyBlockManagementEnabled' => true,
            'blockManagementEmailTo' => 'bloc@example.com',
        ]));
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_patch_negative_delay_returns_400(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $site = $this->makeSite();

        $client->request('PATCH', "/api/planning/absence-communication-settings/{$site->getId()}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'notifyBlockManagementEnabled' => true,
            'blockManagementEmailTo' => 'bloc@example.com',
            'blockManagementDelayDays' => -1,
        ]));
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_patch_delay_zero_is_valid(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $site = $this->makeSite();

        $client->request('PATCH', "/api/planning/absence-communication-settings/{$site->getId()}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'notifyBlockManagementEnabled' => true,
            'blockManagementEmailTo' => 'bloc@example.com',
            'blockManagementDelayDays' => 0,
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_patch_valid_block_management_config_with_cc_is_persisted(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $site = $this->makeSite();

        $client->request('PATCH', "/api/planning/absence-communication-settings/{$site->getId()}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'notifyBlockManagementEnabled' => true,
            'blockManagementEmailTo' => 'bloc@example.com',
            'blockManagementEmailCc' => ['cc1@example.com', 'cc2@example.com'],
            'blockManagementDelayDays' => 14,
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = $this->json($client->getResponse());
        self::assertTrue($data['notifyBlockManagementEnabled']);
        self::assertSame('bloc@example.com', $data['blockManagementEmailTo']);
        self::assertSame(['cc1@example.com', 'cc2@example.com'], $data['blockManagementEmailCc']);
        self::assertSame(14, $data['blockManagementDelayDays']);
    }

    public function test_patch_touching_only_delay_leaves_to_cc_and_enabled_untouched(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $site = $this->makeSite();

        $client->request('PATCH', "/api/planning/absence-communication-settings/{$site->getId()}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'notifyBlockManagementEnabled' => true,
            'blockManagementEmailTo' => 'bloc@example.com',
            'blockManagementEmailCc' => ['cc1@example.com', 'cc2@example.com'],
            'blockManagementDelayDays' => 14,
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        // Revue finale §13 — PATCH ne touchant qu'un seul champ ne doit jamais écraser les
        // autres avec null/défaut, même si la ligne reste `notifyBlockManagementEnabled: true`.
        $client->request('PATCH', "/api/planning/absence-communication-settings/{$site->getId()}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'blockManagementDelayDays' => 21,
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = $this->json($client->getResponse());
        self::assertTrue($data['notifyBlockManagementEnabled'], 'jamais réinitialisé par un patch qui ne le mentionne pas');
        self::assertSame('bloc@example.com', $data['blockManagementEmailTo'], 'jamais écrasé par un patch qui ne le mentionne pas');
        self::assertSame(['cc1@example.com', 'cc2@example.com'], $data['blockManagementEmailCc'], 'jamais écrasé par un patch qui ne le mentionne pas');
        self::assertSame(21, $data['blockManagementDelayDays']);
    }

    #[WithoutErrorHandler]
    public function test_patch_invalid_cc_email_returns_400(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $site = $this->makeSite();

        $client->request('PATCH', "/api/planning/absence-communication-settings/{$site->getId()}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'notifyBlockManagementEnabled' => true,
            'blockManagementEmailTo' => 'bloc@example.com',
            'blockManagementEmailCc' => ['not-an-email'],
            'blockManagementDelayDays' => 14,
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

        $client->request('PATCH', "/api/planning/absence-communication-settings/{$site->getId()}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'notifyBlockManagementEnabled' => true,
            'blockManagementEmailTo' => 'bloc@example.com',
            'blockManagementEmailCc' => ['cc1@example.com', 'CC1@example.com', 'Bloc@Example.com', ' cc2@example.com '],
            'blockManagementDelayDays' => 14,
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = $this->json($client->getResponse());
        self::assertSame(['cc1@example.com', 'cc2@example.com'], $data['blockManagementEmailCc'], 'dedup insensible à la casse, adresse principale retirée, jamais un rejet pour une variante triviale');
    }

    #[WithoutErrorHandler]
    public function test_disabling_block_management_preserves_previously_configured_values(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $site = $this->makeSite();

        $client->request('PATCH', "/api/planning/absence-communication-settings/{$site->getId()}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'notifyBlockManagementEnabled' => true,
            'blockManagementEmailTo' => 'bloc@example.com',
            'blockManagementEmailCc' => ['cc1@example.com'],
            'blockManagementDelayDays' => 14,
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('PATCH', "/api/planning/absence-communication-settings/{$site->getId()}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'notifyBlockManagementEnabled' => false,
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = $this->json($client->getResponse());
        self::assertFalse($data['notifyBlockManagementEnabled']);
        self::assertSame('bloc@example.com', $data['blockManagementEmailTo'], 'décision actée : OFF conserve les valeurs, permet un ré-enable sans ressaisie');
        self::assertSame(['cc1@example.com'], $data['blockManagementEmailCc']);
        self::assertSame(14, $data['blockManagementDelayDays']);
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
