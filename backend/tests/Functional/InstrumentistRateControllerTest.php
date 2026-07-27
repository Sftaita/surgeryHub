<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\InstrumentistRate;
use App\Entity\User;
use App\Enum\InstrumentistRateType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** EPIC Exécution & Valorisation, Lot 2 (D-072) — /api/instrumentists/{id}/rates. */
final class InstrumentistRateControllerTest extends WebTestCase
{
    private const PASSWORD = 'InstrRate72!';

    private EntityManagerInterface $em;
    private array $createdRateIds = [];
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
            foreach ($this->createdRateIds as $id) {
                $e = $this->em->find(InstrumentistRate::class, $id);
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
        $u->setEmail('irctrl-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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

    // ── create + list ────────────────────────────────────────────────────────

    public function test_manager_can_create_and_list_rates(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $manager);

        $create = $this->request($client, 'POST', "/api/instrumentists/{$instr->getId()}/rates", $token, [
            'rateType' => 'HOURLY_RATE', 'amount' => 45, 'validFrom' => '2026-01-01',
        ]);
        self::assertSame(Response::HTTP_CREATED, $create->getStatusCode(), $create->getContent());
        $created = json_decode($create->getContent(), true);
        $this->createdRateIds[] = $created['id'];
        self::assertSame('EUR', $created['currency']);

        $list = $this->request($client, 'GET', "/api/instrumentists/{$instr->getId()}/rates", $token);
        self::assertSame(Response::HTTP_OK, $list->getStatusCode());
        self::assertCount(1, json_decode($list->getContent(), true));
    }

    public function test_overlapping_rate_is_rejected(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $manager);

        $first = $this->request($client, 'POST', "/api/instrumentists/{$instr->getId()}/rates", $token, [
            'rateType' => 'HOURLY_RATE', 'amount' => 45, 'validFrom' => '2026-01-01',
        ]);
        $this->createdRateIds[] = json_decode($first->getContent(), true)['id'];

        $second = $this->request($client, 'POST', "/api/instrumentists/{$instr->getId()}/rates", $token, [
            'rateType' => 'HOURLY_RATE', 'amount' => 50, 'validFrom' => '2026-06-01',
        ]);

        self::assertSame(Response::HTTP_CONFLICT, $second->getStatusCode());
    }

    // ── replace ──────────────────────────────────────────────────────────────

    public function test_replace_closes_old_and_creates_new(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $manager);

        $create = $this->request($client, 'POST', "/api/instrumentists/{$instr->getId()}/rates", $token, [
            'rateType' => 'HOURLY_RATE', 'amount' => 45, 'validFrom' => '2026-01-01',
        ]);
        $current = json_decode($create->getContent(), true);
        $this->createdRateIds[] = $current['id'];

        $effectiveFrom = (new \DateTimeImmutable('+1 month'))->format('Y-m-d');
        $replace = $this->request($client, 'POST', "/api/instrumentists/{$instr->getId()}/rates/{$current['id']}/replace", $token, [
            'amount' => 50, 'effectiveFrom' => $effectiveFrom,
        ]);

        self::assertSame(Response::HTTP_CREATED, $replace->getStatusCode(), $replace->getContent());
        $new = json_decode($replace->getContent(), true);
        $this->createdRateIds[] = $new['id'];
        self::assertSame($effectiveFrom, $new['validFrom']);

        $reloadedCurrent = $this->em->find(InstrumentistRate::class, $current['id']);
        self::assertSame('45.00', $reloadedCurrent->getAmount(), 'jamais réécrit rétroactivement');
    }

    // ── future-only PATCH/DELETE ─────────────────────────────────────────────

    public function test_patch_rejects_an_already_active_rate(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $manager);

        $create = $this->request($client, 'POST', "/api/instrumentists/{$instr->getId()}/rates", $token, [
            'rateType' => 'HOURLY_RATE', 'amount' => 45, 'validFrom' => '2026-01-01',
        ]);
        $rate = json_decode($create->getContent(), true);
        $this->createdRateIds[] = $rate['id'];

        $response = $this->request($client, 'PATCH', "/api/instrumentists/{$instr->getId()}/rates/{$rate['id']}", $token, ['amount' => 999]);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    public function test_delete_succeeds_on_a_future_rate(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $manager);

        $create = $this->request($client, 'POST', "/api/instrumentists/{$instr->getId()}/rates", $token, [
            'rateType' => 'HOURLY_RATE', 'amount' => 45, 'validFrom' => (new \DateTimeImmutable('+1 month'))->format('Y-m-d'),
        ]);
        $rate = json_decode($create->getContent(), true);

        $response = $this->request($client, 'DELETE', "/api/instrumentists/{$instr->getId()}/rates/{$rate['id']}", $token);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertNull($this->em->find(InstrumentistRate::class, $rate['id']));
    }

    // ── Permissions — §11 du lot : manager/admin uniquement ─────────────────

    public function test_instrumentist_cannot_create_their_own_rate(): void
    {
        $client = $this->boot();
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $instr);

        $response = $this->request($client, 'POST', "/api/instrumentists/{$instr->getId()}/rates", $token, [
            'rateType' => 'HOURLY_RATE', 'amount' => 999, 'validFrom' => '2026-01-01',
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_instrumentist_cannot_list_their_own_rates(): void
    {
        $client = $this->boot();
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $instr);

        $response = $this->request($client, 'GET', "/api/instrumentists/{$instr->getId()}/rates", $token);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    /** Régression D-072-EPIC-UX : ADMIN seul doit avoir les mêmes droits qu'un manager (BillingVoter::MANAGE). */
    public function test_admin_can_create_and_list_rates(): void
    {
        $client = $this->boot();
        $admin = $this->createUser('ROLE_ADMIN');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $admin);

        $create = $this->request($client, 'POST', "/api/instrumentists/{$instr->getId()}/rates", $token, [
            'rateType' => 'HOURLY_RATE', 'amount' => 45, 'validFrom' => '2026-01-01',
        ]);
        self::assertSame(Response::HTTP_CREATED, $create->getStatusCode(), $create->getContent());
        $this->createdRateIds[] = json_decode($create->getContent(), true)['id'];

        $list = $this->request($client, 'GET', "/api/instrumentists/{$instr->getId()}/rates", $token);
        self::assertSame(Response::HTTP_OK, $list->getStatusCode());
    }
}
