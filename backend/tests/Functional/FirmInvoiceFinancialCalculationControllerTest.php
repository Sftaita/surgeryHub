<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\FinancialCalculation;
use App\Entity\FirmInvoice;
use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\InstrumentistStatement;
use App\Entity\InterventionType;
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
 * EPIC Exécution & Valorisation, Lot 4 (D-074) — endpoints
 * eligible-lines/from-financial-calculations/cancel, permissions BillingVoter::MANAGE.
 */
final class FirmInvoiceFinancialCalculationControllerTest extends WebTestCase
{
    private const PASSWORD = 'FinCalcDoc74!';

    private EntityManagerInterface $em;
    private array $created = [
        'invoices' => [], 'missions' => [], 'interventions' => [], 'calculations' => [],
        'rules' => [], 'rates' => [], 'types' => [], 'firms' => [], 'sites' => [], 'users' => [],
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
            foreach ($this->created['invoices'] as $id) { $e = $this->em->find(FirmInvoice::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['calculations'] as $id) {
                $calc = $this->em->find(FinancialCalculation::class, $id);
                if ($calc) { foreach ($calc->getLines() as $l) { $this->em->remove($l); } }
            }
            $this->em->flush();
            foreach ($this->created['calculations'] as $id) { $e = $this->em->find(FinancialCalculation::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['interventions'] as $id) { $e = $this->em->find(MissionIntervention::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['missions'] as $id) { $e = $this->em->find(Mission::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['rates'] as $id) { $e = $this->em->find(InstrumentistRate::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['rules'] as $id) { $e = $this->em->find(PricingRule::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['types'] as $id) { $e = $this->em->find(InterventionType::class, $id); if ($e) $this->em->remove($e); }
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
        $u->setEmail('ficfc-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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

    private function postJson(KernelBrowser $client, string $token, string $uri, array $body = []): Response
    {
        $client->request('POST', $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode($body),
        );
        return $client->getResponse();
    }

    /** Construit une mission VALIDATED + intervention + calcul APPROVED (1 ligne FIRM_INTERVENTION_FEE). */
    private function makeApprovedCalculationLine(User $actor): array
    {
        $firm = new Firm();
        $firm->setName('FICFC-' . bin2hex(random_bytes(3)));
        $this->em->persist($firm); $this->em->flush();
        $this->created['firms'][] = $firm->getId();

        $type = new InterventionType();
        $type->setCode('FICFC-' . bin2hex(random_bytes(3)));
        $type->setLabel('FICFC');
        $this->em->persist($type); $this->em->flush();
        $this->created['types'][] = $type->getId();

        $rule = new PricingRule();
        $rule->setFirm($firm);
        $rule->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $rule->setInterventionType($type);
        $rule->setUnitPrice('170.00');
        $this->em->persist($rule); $this->em->flush();
        $this->created['rules'][] = $rule->getId();

        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');
        $rate = new InstrumentistRate();
        $rate->setInstrumentist($instrumentist);
        $rate->setRateType(InstrumentistRateType::HOURLY_RATE);
        $rate->setAmount('40.00');
        $rate->setCurrency('EUR');
        $rate->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($rate); $this->em->flush();
        $this->created['rates'][] = $rate->getId();

        $site = new Hospital();
        $site->setName('FICFC-Site-' . bin2hex(random_bytes(3)));
        $this->em->persist($site); $this->em->flush();
        $this->created['sites'][] = $site->getId();
        $surgeon = $this->createUser('ROLE_SURGEON');

        $today = new \DateTimeImmutable('2026-06-15 08:00:00');
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
        $intervention->setLabel('FICFC');
        $intervention->setInterventionType($type);
        $intervention->setPrimaryFirm($firm);
        $this->em->persist($intervention); $this->em->flush();
        $this->created['interventions'][] = $intervention->getId();
        $mission->getInterventions()->add($intervention);

        $calcService = static::getContainer()->get(FinancialCalculationService::class);
        $calc = $calcService->calculate($mission, $actor);
        $calc = $calcService->approve($calc, $actor);
        $this->created['calculations'][] = $calc->getId();

        return [$firm, $today];
    }

    public function test_eligible_lines_and_create_from_financial_calculations_happy_path(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        [$firm, $today] = $this->makeApprovedCalculationLine($manager);
        $token = $this->login($client, $manager);

        $eligibleResponse = $this->getJson($client, $token, sprintf(
            '/api/firm-invoices/eligible-lines?firmId=%d&currency=EUR&periodStart=%s&periodEnd=%s',
            $firm->getId(), $today->modify('-1 day')->format('Y-m-d'), $today->modify('+1 day')->format('Y-m-d'),
        ));
        self::assertSame(Response::HTTP_OK, $eligibleResponse->getStatusCode(), (string) $eligibleResponse->getContent());
        $eligible = json_decode((string) $eligibleResponse->getContent(), true);
        self::assertCount(1, $eligible['lines']);
        $lineId = $eligible['lines'][0]['id'];

        $createResponse = $this->postJson($client, $token, '/api/firm-invoices/from-financial-calculations', [
            'firmId' => $firm->getId(),
            'currency' => 'EUR',
            'periodStart' => $today->modify('-1 day')->format('Y-m-d'),
            'periodEnd' => $today->modify('+1 day')->format('Y-m-d'),
            'selectedFinancialCalculationLineIds' => [$lineId],
        ]);
        self::assertSame(Response::HTTP_CREATED, $createResponse->getStatusCode(), (string) $createResponse->getContent());
        $invoice = json_decode((string) $createResponse->getContent(), true);
        $this->created['invoices'][] = $invoice['id'];

        self::assertSame('GENERATED', $invoice['status']);
        self::assertFalse($invoice['legacySource']);
        self::assertSame($lineId, $invoice['lines'][0]['financialCalculationLineId']);
        self::assertFalse($invoice['lines'][0]['legacy']);

        // cancel()
        $cancelResponse = $this->postJson($client, $token, "/api/firm-invoices/{$invoice['id']}/cancel", ['reason' => 'test']);
        self::assertSame(Response::HTTP_OK, $cancelResponse->getStatusCode(), (string) $cancelResponse->getContent());
        $cancelled = json_decode((string) $cancelResponse->getContent(), true);
        self::assertSame('CANCELLED', $cancelled['status']);
        self::assertCount(0, $cancelled['lines']);
    }

    public function test_double_submission_returns_structured_422(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        [$firm, $today] = $this->makeApprovedCalculationLine($manager);
        $token = $this->login($client, $manager);

        $eligibleResponse = $this->getJson($client, $token, sprintf(
            '/api/firm-invoices/eligible-lines?firmId=%d&currency=EUR&periodStart=%s&periodEnd=%s',
            $firm->getId(), $today->modify('-1 day')->format('Y-m-d'), $today->modify('+1 day')->format('Y-m-d'),
        ));
        $lineId = json_decode((string) $eligibleResponse->getContent(), true)['lines'][0]['id'];

        $body = [
            'firmId' => $firm->getId(),
            'currency' => 'EUR',
            'periodStart' => $today->modify('-1 day')->format('Y-m-d'),
            'periodEnd' => $today->modify('+1 day')->format('Y-m-d'),
            'selectedFinancialCalculationLineIds' => [$lineId],
        ];

        $first = $this->postJson($client, $token, '/api/firm-invoices/from-financial-calculations', $body);
        self::assertSame(Response::HTTP_CREATED, $first->getStatusCode());
        $this->created['invoices'][] = json_decode((string) $first->getContent(), true)['id'];

        $second = $this->postJson($client, $token, '/api/firm-invoices/from-financial-calculations', $body);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $second->getStatusCode());
        $errorBody = json_decode((string) $second->getContent(), true);
        self::assertSame('DOCUMENT_LINE_SELECTION_FAILED', $errorBody['error']['code']);
        self::assertSame('FINANCIAL_LINE_ALREADY_ASSIGNED', $errorBody['error']['violations'][0]['code'] ?? null, json_encode($errorBody));
    }

    public function test_instrumentist_cannot_manage_invoices(): void
    {
        $client = $this->boot();
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $instrumentist);

        $response = $this->getJson($client, $token, '/api/firm-invoices/eligible-lines?firmId=1&currency=EUR&periodStart=2026-01-01&periodEnd=2026-01-31');

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }
}
