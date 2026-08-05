<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\InstrumentistRate;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Diagnostic tarifs instrumentistes (2026-08-05) — GET /api/instrumentists expose
 * désormais `hasCurrentHourlyRate`, calculé via InstrumentistRateResolver (même règle
 * que FinancialCalculationService), jamais recalculé côté frontend. Jusqu'ici cet
 * endpoint n'avait aucune couverture fonctionnelle dédiée.
 */
final class InstrumentistControllerTest extends WebTestCase
{
    private const PASSWORD = 'InstrList72!';

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

    private function createUser(string $role, string $prefix = 'instrlist'): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail($prefix . '-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('InstrList');
        $u->setLastname('Test');
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

    private function createRate(KernelBrowser $client, string $token, int $instrumentistId, string $validFrom, ?string $validTo, int|float $amount = 45): void
    {
        $response = $this->request($client, 'POST', "/api/instrumentists/{$instrumentistId}/rates", $token, [
            'rateType' => 'HOURLY_RATE',
            'amount' => $amount,
            'validFrom' => $validFrom,
            'validTo' => $validTo,
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $this->createdRateIds[] = json_decode($response->getContent(), true)['id'];
    }

    private function findInList(array $items, int $instrumentistId): ?array
    {
        foreach ($items as $item) {
            if ($item['id'] === $instrumentistId) {
                return $item;
            }
        }
        return null;
    }

    public function test_instrumentist_with_no_rate_has_flag_false(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $manager);

        $response = $this->request($client, 'GET', '/api/instrumentists?limit=200', $token);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $items = json_decode($response->getContent(), true)['items'];

        $found = $this->findInList($items, $instr->getId());
        self::assertNotNull($found, 'Le nouvel instrumentiste doit apparaître dans la liste');
        self::assertFalse($found['hasCurrentHourlyRate']);
    }

    public function test_instrumentist_with_active_bounded_rate_has_flag_true(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $manager);

        $yesterday = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');
        $nextMonth = (new \DateTimeImmutable('+1 month'))->format('Y-m-d');
        $this->createRate($client, $token, $instr->getId(), $yesterday, $nextMonth);

        $response = $this->request($client, 'GET', '/api/instrumentists?limit=200', $token);
        $items = json_decode($response->getContent(), true)['items'];

        self::assertTrue($this->findInList($items, $instr->getId())['hasCurrentHourlyRate']);
    }

    public function test_instrumentist_with_open_ended_rate_has_flag_true(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $manager);

        $this->createRate($client, $token, $instr->getId(), '2026-01-01', null);

        $response = $this->request($client, 'GET', '/api/instrumentists?limit=200', $token);
        $items = json_decode($response->getContent(), true)['items'];

        self::assertTrue($this->findInList($items, $instr->getId())['hasCurrentHourlyRate']);
    }

    public function test_instrumentist_with_future_rate_only_has_flag_false(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $manager);

        $nextYear = (new \DateTimeImmutable('+1 year'))->format('Y-m-d');
        $this->createRate($client, $token, $instr->getId(), $nextYear, null);

        $response = $this->request($client, 'GET', '/api/instrumentists?limit=200', $token);
        $items = json_decode($response->getContent(), true)['items'];

        self::assertFalse($this->findInList($items, $instr->getId())['hasCurrentHourlyRate']);
    }

    public function test_instrumentist_with_expired_rate_only_has_flag_false(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $manager);

        $this->createRate($client, $token, $instr->getId(), '2020-01-01', '2020-06-01');

        $response = $this->request($client, 'GET', '/api/instrumentists?limit=200', $token);
        $items = json_decode($response->getContent(), true)['items'];

        self::assertFalse($this->findInList($items, $instr->getId())['hasCurrentHourlyRate']);
    }

    /**
     * Vérification tarif 0€ (2026-08-05) — un InstrumentistRate à 0.00 EUR couvrant
     * aujourd'hui reste un tarif RÉEL : hasCurrentHourlyRate ne doit jamais retomber à
     * false à cause du montant, uniquement en l'absence de toute InstrumentistRate
     * applicable (voir test_instrumentist_with_no_rate_has_flag_false ci-dessus).
     */
    public function test_instrumentist_with_zero_amount_rate_has_flag_true(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $manager);

        $this->createRate($client, $token, $instr->getId(), '2026-01-01', null, amount: 0);

        $response = $this->request($client, 'GET', '/api/instrumentists?limit=200', $token);
        $items = json_decode($response->getContent(), true)['items'];

        self::assertTrue($this->findInList($items, $instr->getId())['hasCurrentHourlyRate'], 'un tarif à 0€ reste un tarif configuré, pas un tarif manquant');
    }
}
