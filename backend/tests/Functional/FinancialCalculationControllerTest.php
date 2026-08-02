<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\FinancialCalculation;
use App\Entity\FinancialCalculationLine;
use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\InterventionType;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\InstrumentistRateType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PricingRuleType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * EPIC Exécution & Valorisation, Lot 3 (D-073) — §23/§24 du lot : endpoints
 * FinancialCalculation, permissions manager/admin uniquement.
 */
final class FinancialCalculationControllerTest extends WebTestCase
{
    private const PASSWORD = 'FinCalc73!';

    private EntityManagerInterface $em;
    private array $created = [
        'calculations' => [], 'missions' => [], 'interventions' => [], 'rules' => [],
        'rates' => [], 'types' => [], 'firms' => [], 'sites' => [], 'users' => [],
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
            $this->em->flush();
            foreach ($this->created['calculations'] as $id) {
                $calc = $this->em->find(FinancialCalculation::class, $id);
                if ($calc) {
                    foreach ($calc->getLines() as $l) { $this->em->remove($l); }
                    $this->em->remove($calc);
                }
            }
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
        $u->setEmail('fcctrl-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
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

    private function makeFirm(): Firm
    {
        $f = new Firm();
        $f->setName('FC-Firm-' . bin2hex(random_bytes(3)));
        $this->em->persist($f); $this->em->flush();
        $this->created['firms'][] = $f->getId();
        return $f;
    }

    private function makeType(): InterventionType
    {
        $t = new InterventionType();
        $t->setCode('FCT-' . bin2hex(random_bytes(3)));
        $t->setLabel('FC Type');
        $this->em->persist($t); $this->em->flush();
        $this->created['types'][] = $t->getId();
        return $t;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('FC-Site-' . bin2hex(random_bytes(3)));
        $this->em->persist($h); $this->em->flush();
        $this->created['sites'][] = $h->getId();
        return $h;
    }

    private function makeEligibleMission(User $instrumentist): Mission
    {
        $site = $this->makeSite();
        $surgeon = $this->createUser('ROLE_SURGEON');

        $firm = $this->makeFirm();
        $type = $this->makeType();
        $rule = new PricingRule();
        $rule->setFirm($firm);
        $rule->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $rule->setInterventionType($type);
        $rule->setUnitPrice('150.00');
        $this->em->persist($rule); $this->em->flush();
        $this->created['rules'][] = $rule->getId();

        $rate = new InstrumentistRate();
        $rate->setInstrumentist($instrumentist);
        $rate->setRateType(InstrumentistRateType::HOURLY_RATE);
        $rate->setAmount('40.00');
        $rate->setCurrency('EUR');
        $rate->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($rate); $this->em->flush();
        $this->created['rates'][] = $rate->getId();

        $m = new Mission();
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($surgeon);
        $m->setStartAt(new \DateTimeImmutable('2026-06-01 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-06-01 09:00:00'));
        $m->setStatus(MissionStatus::VALIDATED);
        $m->setInstrumentist($instrumentist);
        $this->em->persist($m); $this->em->flush();
        $this->created['missions'][] = $m->getId();

        $intervention = new MissionIntervention();
        $intervention->setMission($m);
        $intervention->setCode($type->getCode());
        $intervention->setLabel('FC intervention');
        $intervention->setInterventionType($type);
        $intervention->setPrimaryFirm($firm);
        $this->em->persist($intervention); $this->em->flush();
        $this->created['interventions'][] = $intervention->getId();
        $m->getInterventions()->add($intervention);

        return $m;
    }

    private function makeIneligibleMission(): Mission
    {
        $site = $this->makeSite();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $m = new Mission();
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($surgeon);
        $m->setStartAt(new \DateTimeImmutable('2026-06-01 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-06-01 09:00:00'));
        $m->setStatus(MissionStatus::ASSIGNED); // pas VALIDATED
        $this->em->persist($m); $this->em->flush();
        $this->created['missions'][] = $m->getId();
        return $m;
    }

    private function postJson(KernelBrowser $client, string $token, string $uri): Response
    {
        $client->request('POST', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        return $client->getResponse();
    }

    private function getJson(KernelBrowser $client, string $token, string $uri): Response
    {
        $client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        return $client->getResponse();
    }

    // ── Permissions ───────────────────────────────────────────────────────

    public function test_instrumentist_cannot_create_a_calculation(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');
        $mission = $this->makeEligibleMission($instrumentist);
        $token = $this->login($client, $instrumentist);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/financial-calculations");

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        unset($manager);
    }

    public function test_surgeon_cannot_view_calculations(): void
    {
        $client = $this->boot();
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');
        $mission = $this->makeEligibleMission($instrumentist);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $token = $this->login($client, $surgeon);

        $response = $this->getJson($client, $token, "/api/missions/{$mission->getId()}/financial-calculations");

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    // ── Happy path : create → list → show → recalculate → approve → lock ──

    public function test_full_manager_workflow(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');
        $mission = $this->makeEligibleMission($instrumentist);
        $token = $this->login($client, $manager);

        // create()
        $createResponse = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/financial-calculations");
        self::assertSame(Response::HTTP_CREATED, $createResponse->getStatusCode(), (string) $createResponse->getContent());
        $created = json_decode((string) $createResponse->getContent(), true);
        $this->created['calculations'][] = $created['id'];

        self::assertSame(1, $created['version']);
        self::assertSame('CALCULATED', $created['status']);
        self::assertCount(2, $created['lines']); // FIRM_INTERVENTION_FEE + INSTRUMENTIST_HOURLY
        self::assertSame('150.00', $created['totalsByCurrency']['EUR']['FIRM'] ?? null, (string) $createResponse->getContent());
        self::assertSame('40.00', $created['totalsByCurrency']['EUR']['INSTRUMENTIST'] ?? null, (string) $createResponse->getContent());

        // list()
        $listResponse = $this->getJson($client, $token, "/api/missions/{$mission->getId()}/financial-calculations");
        self::assertSame(Response::HTTP_OK, $listResponse->getStatusCode());
        $list = json_decode((string) $listResponse->getContent(), true);
        self::assertCount(1, $list);

        // show()
        $showResponse = $this->getJson($client, $token, "/api/financial-calculations/{$created['id']}");
        self::assertSame(Response::HTTP_OK, $showResponse->getStatusCode());

        // recalculate()
        $recalcResponse = $this->postJson($client, $token, "/api/financial-calculations/{$created['id']}/recalculate");
        self::assertSame(Response::HTTP_CREATED, $recalcResponse->getStatusCode(), (string) $recalcResponse->getContent());
        $recalculated = json_decode((string) $recalcResponse->getContent(), true);
        $this->created['calculations'][] = $recalculated['id'];
        self::assertSame(2, $recalculated['version']);

        // approve() then lock() the new active version.
        $approveResponse = $this->postJson($client, $token, "/api/financial-calculations/{$recalculated['id']}/approve");
        self::assertSame(Response::HTTP_OK, $approveResponse->getStatusCode(), (string) $approveResponse->getContent());
        self::assertSame('APPROVED', json_decode((string) $approveResponse->getContent(), true)['status']);

        $lockResponse = $this->postJson($client, $token, "/api/financial-calculations/{$recalculated['id']}/lock");
        self::assertSame(Response::HTTP_OK, $lockResponse->getStatusCode(), (string) $lockResponse->getContent());
        self::assertSame('LOCKED', json_decode((string) $lockResponse->getContent(), true)['status']);
    }

    /**
     * Refonte Catalogue/Prestations (D-092) — le détail de calcul (accessible depuis
     * preview/détail de facture) doit exposer grossAmount/adjustmentAmount/warnings sans
     * nouvel endpoint (§26 : DTO existant étendu). Fixture sans FirmServiceOffering :
     * comportement par défaut — grossAmount = totalAmount, adjustmentAmount = "0.00",
     * warnings = [].
     */
    public function test_show_exposes_gross_amount_adjustment_amount_and_warnings(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');
        $mission = $this->makeEligibleMission($instrumentist);
        $token = $this->login($client, $manager);

        $createResponse = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/financial-calculations");
        self::assertSame(Response::HTTP_CREATED, $createResponse->getStatusCode(), (string) $createResponse->getContent());
        $created = json_decode((string) $createResponse->getContent(), true);
        $this->created['calculations'][] = $created['id'];

        $showResponse = $this->getJson($client, $token, "/api/financial-calculations/{$created['id']}");
        self::assertSame(Response::HTTP_OK, $showResponse->getStatusCode());
        $body = json_decode((string) $showResponse->getContent(), true);

        $feeLine = null;
        foreach ($body['lines'] as $l) {
            if ($l['lineType'] === 'FIRM_INTERVENTION_FEE') { $feeLine = $l; }
        }
        self::assertNotNull($feeLine, (string) $showResponse->getContent());
        self::assertSame('150.00', $feeLine['grossAmount']);
        self::assertSame('0.00', $feeLine['adjustmentAmount']);
        self::assertSame('150.00', $feeLine['totalAmount']);
        self::assertSame([], $feeLine['warnings']);
        self::assertArrayHasKey('representativePresentSnapshot', $feeLine['snapshot']);
        self::assertArrayHasKey('representativePolicySnapshot', $feeLine['snapshot']);
    }

    // ── Erreurs métier mappées ──────────────────────────────────────────────

    public function test_create_on_ineligible_mission_returns_409(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $mission = $this->makeIneligibleMission();
        $token = $this->login($client, $manager);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/financial-calculations");

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('FINANCIAL_CALCULATION_INELIGIBLE', $body['error']['code']);
    }

    public function test_create_with_missing_rate_returns_422_with_structured_anomalies(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST'); // aucun tarif créé

        $site = $this->makeSite();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $m = new Mission();
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($surgeon);
        $m->setStartAt(new \DateTimeImmutable('2026-06-01 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-06-01 09:00:00'));
        $m->setStatus(MissionStatus::VALIDATED);
        $m->setInstrumentist($instrumentist);
        $this->em->persist($m); $this->em->flush();
        $this->created['missions'][] = $m->getId();

        $token = $this->login($client, $manager);
        $response = $this->postJson($client, $token, "/api/missions/{$m->getId()}/financial-calculations");

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('FINANCIAL_CALCULATION_ANOMALIES', $body['error']['code']);
        self::assertNotEmpty($body['error']['violations']);
        self::assertSame('MISSING_INSTRUMENTIST_RATE', $body['error']['violations'][0]['code'] ?? null, json_encode($body));
    }
}
