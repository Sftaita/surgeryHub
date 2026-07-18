<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\InterventionType;
use App\Entity\PricingRule;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * EPIC Exécution & Valorisation, Lot 2 (D-072) — couvre les changements de contrat
 * HTTP de FirmBillingController : PATCH/DELETE restreints aux règles futures, nouveau
 * POST .../replace. Complète FirmBillingControllerPricingRuleTest (Lot 1, toujours
 * valide et inchangé pour create/list).
 */
final class PricingRuleVersioningControllerTest extends WebTestCase
{
    private const PASSWORD = 'Versioning72!';

    private EntityManagerInterface $em;
    private array $createdRuleIds = [];
    private array $createdTypeIds = [];
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
            foreach ($this->createdUserIds as $id) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['actor' => $id]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            $this->em->flush();
            foreach ($this->createdRuleIds as $id) {
                $e = $this->em->find(PricingRule::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdTypeIds as $id) {
                $e = $this->em->find(InterventionType::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdFirmIds as $id) {
                $e = $this->em->find(Firm::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
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
        $u->setEmail('vctrl-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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

    private function makeFirm(): Firm
    {
        $f = new Firm();
        $f->setName('VCtrl-' . bin2hex(random_bytes(4)));
        $this->em->persist($f);
        $this->em->flush();
        $this->createdFirmIds[] = $f->getId();
        return $f;
    }

    private function makeType(): InterventionType
    {
        $t = new InterventionType();
        $t->setCode('VCTRL-' . bin2hex(random_bytes(4)));
        $t->setLabel('Type de test');
        $this->em->persist($t);
        $this->em->flush();
        $this->createdTypeIds[] = $t->getId();
        return $t;
    }

    private function makeRule(Firm $firm, InterventionType $type, string $validFrom, ?string $validTo = null): PricingRule
    {
        $r = new PricingRule();
        $r->setFirm($firm);
        $r->setRuleType(\App\Enum\PricingRuleType::INTERVENTION_FEE);
        $r->setInterventionType($type);
        $r->setUnitPrice('250.00');
        $r->setValidFrom(new \DateTimeImmutable($validFrom));
        if ($validTo !== null) { $r->setValidTo(new \DateTimeImmutable($validTo)); }
        $this->em->persist($r);
        $this->em->flush();
        $this->createdRuleIds[] = $r->getId();
        return $r;
    }

    // ── PATCH restreint aux règles futures ──────────────────────────────────

    public function test_patch_rejects_an_already_active_rule(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $active = $this->makeRule($firm, $type, '2026-01-01');

        $response = $this->request($client, 'PATCH', "/api/firms/{$firm->getId()}/pricing-rules/{$active->getId()}", $token, ['unitPrice' => 999]);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    public function test_patch_succeeds_on_a_future_rule(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $future = $this->makeRule($firm, $type, (new \DateTimeImmutable('+1 month'))->format('Y-m-d'));

        $response = $this->request($client, 'PATCH', "/api/firms/{$firm->getId()}/pricing-rules/{$future->getId()}", $token, ['unitPrice' => 300]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertSame(300.0, (float) $body['unitPrice']);
    }

    // ── DELETE restreint aux règles futures ─────────────────────────────────

    public function test_delete_rejects_an_already_active_rule(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $active = $this->makeRule($firm, $type, '2026-01-01');

        $response = $this->request($client, 'DELETE', "/api/firms/{$firm->getId()}/pricing-rules/{$active->getId()}", $token);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertNotNull($this->em->find(PricingRule::class, $active->getId()), 'la règle active ne doit jamais être supprimée physiquement');
    }

    public function test_delete_succeeds_on_a_future_rule(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $future = $this->makeRule($firm, $type, (new \DateTimeImmutable('+1 month'))->format('Y-m-d'));
        $futureId = $future->getId();

        $response = $this->request($client, 'DELETE', "/api/firms/{$firm->getId()}/pricing-rules/{$futureId}", $token);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertNull($this->em->find(PricingRule::class, $futureId));
        // Retire de la liste de nettoyage — déjà supprimée.
        $this->createdRuleIds = array_diff($this->createdRuleIds, [$futureId]);
    }

    // ── POST .../replace — le cas principal du lot ──────────────────────────

    public function test_replace_closes_old_rule_and_creates_new_one(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $current = $this->makeRule($firm, $type, '2026-01-01');
        $effectiveFrom = (new \DateTimeImmutable('+1 month'))->format('Y-m-d');

        $response = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/pricing-rules/{$current->getId()}/replace", $token, [
            'unitPrice' => 300, 'effectiveFrom' => $effectiveFrom,
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $newRule = json_decode($response->getContent(), true);
        $this->createdRuleIds[] = $newRule['id'];
        self::assertSame($effectiveFrom, $newRule['validFrom']);
        self::assertNull($newRule['validTo']);

        $reloadedCurrent = $this->em->find(PricingRule::class, $current->getId());
        self::assertSame($effectiveFrom, $reloadedCurrent->getValidTo()->format('Y-m-d'));
        self::assertSame('250.00', $reloadedCurrent->getUnitPrice(), 'jamais réécrit rétroactivement');
    }

    public function test_replace_rejects_past_effective_date(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $current = $this->makeRule($firm, $type, '2025-01-01');

        $response = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/pricing-rules/{$current->getId()}/replace", $token, [
            'unitPrice' => 300, 'effectiveFrom' => '2025-06-01',
        ]);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    // ── Permissions ──────────────────────────────────────────────────────────

    public function test_instrumentist_cannot_replace_a_rule(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $instr);
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $current = $this->makeRule($firm, $type, '2026-01-01');

        $response = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/pricing-rules/{$current->getId()}/replace", $token, [
            'unitPrice' => 300, 'effectiveFrom' => (new \DateTimeImmutable('+1 month'))->format('Y-m-d'),
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }
}
