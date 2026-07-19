<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\FinancialCalculation;
use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\InstrumentistRateType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PricingRuleType;
use App\Service\FinancialCalculationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — §27/§30/§31 du lot : permissions
 * BillingVoter::MANAGE, erreurs structurées sur filtres invalides, pagination du
 * drill-down.
 */
final class FinancialStatisticsControllerTest extends WebTestCase
{
    private const PASSWORD = 'FinStats77!';

    private EntityManagerInterface $em;
    private array $created = [
        'missions' => [], 'interventions' => [], 'materialLines' => [], 'calculations' => [],
        'rates' => [], 'rules' => [], 'items' => [], 'types' => [], 'firms' => [], 'sites' => [], 'users' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->created['missions'] as $missionId) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $missionId]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            foreach ($this->created['users'] as $userId) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['actor' => $userId]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            $this->em->flush();
            foreach ($this->created['calculations'] as $id) {
                $calc = $this->em->find(FinancialCalculation::class, $id);
                if ($calc) { foreach ($calc->getLines() as $l) { $this->em->remove($l); } }
            }
            $this->em->flush();
            foreach ($this->created['calculations'] as $id) { $e = $this->em->find(FinancialCalculation::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['materialLines'] as $id) { $e = $this->em->find(MaterialLine::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['interventions'] as $id) { $e = $this->em->find(MissionIntervention::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['missions'] as $id) { $e = $this->em->find(Mission::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['rates'] as $id) { $e = $this->em->find(InstrumentistRate::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['rules'] as $id) { $e = $this->em->find(PricingRule::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['items'] as $id) { $e = $this->em->find(MaterialItem::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['types'] as $id) { $e = $this->em->find(InterventionType::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['firms'] as $id) { $e = $this->em->find(Firm::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['sites'] as $id) { $e = $this->em->find(Hospital::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['users'] as $id) { $e = $this->em->find(User::class, $id); if ($e) $this->em->remove($e); }
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
        $u->setEmail('fsctrl-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u); $this->em->flush();
        $this->created['users'][] = $u->getId();
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

    private function getJson(KernelBrowser $client, string $token, string $uri): Response
    {
        $client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        return $client->getResponse();
    }

    /** @return array{0: Mission, 1: User} */
    private function makeApprovedMission(User $manager): array
    {
        $firm = new Firm();
        $firm->setName('FSCTRL-' . bin2hex(random_bytes(4)));
        $this->em->persist($firm); $this->em->flush();
        $this->created['firms'][] = $firm->getId();

        $type = new InterventionType();
        $type->setCode('FSCTRL-' . bin2hex(random_bytes(3)));
        $type->setLabel('FSCTRL');
        $this->em->persist($type); $this->em->flush();
        $this->created['types'][] = $type->getId();

        $rule = new PricingRule();
        $rule->setFirm($firm);
        $rule->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $rule->setInterventionType($type);
        $rule->setUnitPrice('120.00');
        $this->em->persist($rule); $this->em->flush();
        $this->created['rules'][] = $rule->getId();

        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');
        $rate = new InstrumentistRate();
        $rate->setInstrumentist($instrumentist);
        $rate->setRateType(InstrumentistRateType::HOURLY_RATE);
        $rate->setAmount('35.00');
        $rate->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($rate); $this->em->flush();
        $this->created['rates'][] = $rate->getId();

        $site = new Hospital();
        $site->setName('FSCTRL-Site-' . bin2hex(random_bytes(3)));
        $this->em->persist($site); $this->em->flush();
        $this->created['sites'][] = $site->getId();
        $surgeon = $this->createUser('ROLE_SURGEON');

        $today = new \DateTimeImmutable('2026-05-15 09:00:00');
        $mission = new Mission();
        $mission->setType(MissionType::BLOCK);
        $mission->setSite($site);
        $mission->setSurgeon($surgeon);
        $mission->setCreatedBy($surgeon);
        $mission->setStartAt($today);
        $mission->setEndAt($today->modify('+1 hour'));
        $mission->setStatus(MissionStatus::VALIDATED);
        $mission->setInstrumentist($instrumentist);
        $this->em->persist($mission); $this->em->flush();
        $this->created['missions'][] = $mission->getId();

        $intervention = new MissionIntervention();
        $intervention->setMission($mission);
        $intervention->setCode($type->getCode());
        $intervention->setLabel('FSCTRL');
        $intervention->setInterventionType($type);
        $intervention->setPrimaryFirm($firm);
        $this->em->persist($intervention); $this->em->flush();
        $this->created['interventions'][] = $intervention->getId();
        $mission->getInterventions()->add($intervention);

        $calcService = static::getContainer()->get(FinancialCalculationService::class);
        $calc = $calcService->calculate($mission, $manager);
        $this->created['calculations'][] = $calc->getId();
        $calcService->approve($calc, $manager);

        return [$mission, $surgeon];
    }

    // ── Permissions (§27) ────────────────────────────────────────────────

    public function test_manager_can_access_overview(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);

        $response = $this->getJson($client, $token, '/api/financial-statistics/overview?from=2026-05-01&to=2026-06-01');
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $body = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('activity', $body);
        self::assertArrayHasKey('currencies', $body);
    }

    public function test_admin_can_access_pipeline(): void
    {
        $client = $this->boot();
        $admin = $this->createUser('ROLE_ADMIN');
        $token = $this->login($client, $admin);

        $response = $this->getJson($client, $token, '/api/financial-statistics/pipeline?from=2026-05-01&to=2026-06-01');
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $body = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('validatedMissionsWithoutCalculation', $body);
    }

    public function test_instrumentist_is_forbidden_from_all_endpoints(): void
    {
        $client = $this->boot();
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $instrumentist);

        foreach ([
            '/api/financial-statistics/overview',
            '/api/financial-statistics/timeseries',
            '/api/financial-statistics/pipeline',
            '/api/financial-statistics/by-firm',
            '/api/financial-statistics/by-instrumentist',
            '/api/financial-statistics/by-surgeon',
            '/api/financial-statistics/by-intervention',
            '/api/financial-statistics/top-materials',
            '/api/financial-statistics/missions',
            '/api/financial-statistics/calculations',
            '/api/financial-statistics/documents',
        ] as $endpoint) {
            $response = $this->getJson($client, $token, $endpoint);
            self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode(), "endpoint attendu 403: {$endpoint}");
        }
    }

    // ── Filtres invalides (§6) ────────────────────────────────────────────

    public function test_from_after_to_returns_422(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);

        $response = $this->getJson($client, $token, '/api/financial-statistics/overview?from=2026-06-01&to=2026-05-01');
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function test_invalid_sort_by_returns_422(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);

        $response = $this->getJson($client, $token, '/api/financial-statistics/by-firm?sortBy=notAllowedField');
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function test_invalid_granularity_returns_422(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);

        $response = $this->getJson($client, $token, '/api/financial-statistics/timeseries?granularity=YEAR');
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function test_invalid_currency_returns_422(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);

        $response = $this->getJson($client, $token, '/api/financial-statistics/overview?currency=TOOLONG');
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    // ── Drill-down + pagination (§18/§30) ─────────────────────────────────

    public function test_missions_drilldown_is_paginated_and_matches_overview_scope(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        [, $surgeon] = $this->makeApprovedMission($manager);

        $response = $this->getJson($client, $token, "/api/financial-statistics/missions?from=2026-05-01&to=2026-06-01&surgeonId={$surgeon->getId()}&limit=5&page=1");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(1, $body['total']);
        self::assertSame(1, $body['page']);
        self::assertSame(5, $body['limit']);
        self::assertCount(1, $body['items']);
        self::assertSame('MISSION', $body['items'][0]['sourceType']);
    }

    public function test_by_firm_supports_pagination_parameters(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $this->makeApprovedMission($manager);

        $response = $this->getJson($client, $token, '/api/financial-statistics/by-firm?from=2026-05-01&to=2026-06-01&page=1&limit=1');
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(1, $body['page']);
        self::assertSame(1, $body['limit']);
        self::assertArrayHasKey('total', $body);
    }
}
