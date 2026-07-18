<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\FinancialCalculation;
use App\Entity\FirmInvoice;
use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\InterventionType;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\Payment;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\InstrumentistRateType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PricingRuleType;
use App\Service\FinancialCalculationService;
use App\Service\FirmInvoiceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * EPIC Exécution & Valorisation, Lot 5 (D-075) — POST .../issue, POST/GET .../payments,
 * permissions BillingVoter::MANAGE (§15/§17 du lot).
 */
final class DocumentPaymentControllerTest extends WebTestCase
{
    private const PASSWORD = 'DocPayment75!';

    private EntityManagerInterface $em;
    private array $created = [
        'payments' => [], 'invoices' => [], 'missions' => [], 'interventions' => [],
        'calculations' => [], 'rules' => [], 'rates' => [], 'types' => [], 'firms' => [], 'sites' => [], 'users' => [],
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
            foreach ($this->created['payments'] as $id) { $e = $this->em->find(Payment::class, $id); if ($e) $this->em->remove($e); }
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
        $u->setEmail('dpctrl-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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

    private function makeGeneratedInvoice(User $actor): FirmInvoice
    {
        $firm = new Firm();
        $firm->setName('DPCTRL-' . bin2hex(random_bytes(3)));
        $this->em->persist($firm); $this->em->flush();
        $this->created['firms'][] = $firm->getId();

        $type = new InterventionType();
        $type->setCode('DPCTRL-' . bin2hex(random_bytes(3)));
        $type->setLabel('DPCTRL');
        $this->em->persist($type); $this->em->flush();
        $this->created['types'][] = $type->getId();

        $rule = new PricingRule();
        $rule->setFirm($firm);
        $rule->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $rule->setInterventionType($type);
        $rule->setUnitPrice('300.00');
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
        $site->setName('DPCTRL-Site-' . bin2hex(random_bytes(3)));
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
        $intervention->setLabel('DPCTRL');
        $intervention->setInterventionType($type);
        $intervention->setPrimaryFirm($firm);
        $this->em->persist($intervention); $this->em->flush();
        $this->created['interventions'][] = $intervention->getId();
        $mission->getInterventions()->add($intervention);

        $calcService = static::getContainer()->get(FinancialCalculationService::class);
        $calc = $calcService->calculate($mission, $actor);
        $calc = $calcService->approve($calc, $actor);
        $this->created['calculations'][] = $calc->getId();
        $firmLine = $calc->getLines()->filter(static fn ($l) => $l->getLineType()->value === 'FIRM_INTERVENTION_FEE')->first();

        $invoiceService = static::getContainer()->get(FirmInvoiceService::class);
        $invoice = $invoiceService->createFromEligibleLines($firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'), [$firmLine->getId()], $actor);
        $this->created['invoices'][] = $invoice->getId();

        return $invoice;
    }

    public function test_issue_then_record_payment_happy_path(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $invoice = $this->makeGeneratedInvoice($manager);
        $token = $this->login($client, $manager);

        $issueResponse = $this->postJson($client, $token, "/api/firm-invoices/{$invoice->getId()}/issue");
        self::assertSame(Response::HTTP_OK, $issueResponse->getStatusCode(), (string) $issueResponse->getContent());
        $issued = json_decode((string) $issueResponse->getContent(), true);
        self::assertSame('SENT', $issued['status']);
        self::assertSame('UNPAID', $issued['paymentStatus']);

        $paymentResponse = $this->postJson($client, $token, "/api/firm-invoices/{$invoice->getId()}/payments", [
            'amount' => '300.00', 'currency' => 'EUR', 'paidAt' => '2026-06-20',
            'method' => 'BANK_TRANSFER', 'reference' => 'REF-XYZ',
        ]);
        self::assertSame(Response::HTTP_CREATED, $paymentResponse->getStatusCode(), (string) $paymentResponse->getContent());
        $payment = json_decode((string) $paymentResponse->getContent(), true);
        $this->created['payments'][] = $payment['id'];
        self::assertSame('300.00', $payment['amount']);
        self::assertSame('REF-XYZ', $payment['reference']);

        $detailResponse = $this->getJson($client, $token, "/api/firm-invoices/{$invoice->getId()}");
        $detail = json_decode((string) $detailResponse->getContent(), true);
        self::assertSame('PAID', $detail['paymentStatus']);
        self::assertSame('0.00', $detail['remainingAmount']);
        self::assertCount(1, $detail['payments']);

        $listResponse = $this->getJson($client, $token, "/api/firm-invoices/{$invoice->getId()}/payments");
        self::assertSame(Response::HTTP_OK, $listResponse->getStatusCode());
        self::assertCount(1, json_decode((string) $listResponse->getContent(), true));
    }

    public function test_payment_exceeding_remaining_returns_422(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $invoice = $this->makeGeneratedInvoice($manager);
        $token = $this->login($client, $manager);

        $this->postJson($client, $token, "/api/firm-invoices/{$invoice->getId()}/issue");

        $response = $this->postJson($client, $token, "/api/firm-invoices/{$invoice->getId()}/payments", [
            'amount' => '999.00', 'currency' => 'EUR', 'paidAt' => '2026-06-20', 'method' => 'BANK_TRANSFER',
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('PAYMENT_EXCEEDS_REMAINING', $body['error']['code']);
    }

    public function test_payment_before_issue_returns_409(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $invoice = $this->makeGeneratedInvoice($manager);
        $token = $this->login($client, $manager);

        $response = $this->postJson($client, $token, "/api/firm-invoices/{$invoice->getId()}/payments", [
            'amount' => '300.00', 'currency' => 'EUR', 'paidAt' => '2026-06-20', 'method' => 'BANK_TRANSFER',
        ]);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('DOCUMENT_NOT_ISSUED', $body['error']['code']);
    }

    public function test_instrumentist_cannot_issue_or_pay(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $invoice = $this->makeGeneratedInvoice($manager);
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $instrumentist);

        $issueResponse = $this->postJson($client, $token, "/api/firm-invoices/{$invoice->getId()}/issue");
        self::assertSame(Response::HTTP_FORBIDDEN, $issueResponse->getStatusCode());

        $paymentResponse = $this->postJson($client, $token, "/api/firm-invoices/{$invoice->getId()}/payments", [
            'amount' => '100.00', 'currency' => 'EUR', 'paidAt' => '2026-06-20', 'method' => 'BANK_TRANSFER',
        ]);
        self::assertSame(Response::HTTP_FORBIDDEN, $paymentResponse->getStatusCode());

        $listResponse = $this->getJson($client, $token, "/api/firm-invoices/{$invoice->getId()}/payments");
        self::assertSame(Response::HTTP_FORBIDDEN, $listResponse->getStatusCode());
    }
}
