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
use App\Entity\Payment;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\InstrumentistRateType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PricingRuleType;
use App\Service\FinancialCalculationService;
use App\Service\FirmInvoiceService;
use App\Service\InstrumentistStatementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * EPIC Exécution & Valorisation, Lot 6 (D-076) — §26/§27 du lot : endpoints
 * credit-notes/debit-notes/corrections/refunds + /api/firm-invoice-corrections/{id}/issue,
 * permissions BillingVoter::MANAGE, erreurs structurées.
 */
final class FinancialCorrectionControllerTest extends WebTestCase
{
    private const PASSWORD = 'FinCorr76!';

    private EntityManagerInterface $em;
    private array $created = [
        'payments' => [], 'invoices' => [], 'statements' => [], 'missions' => [], 'interventions' => [],
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
            foreach ($this->created['invoices'] as $id) {
                $e = $this->em->find(FirmInvoice::class, $id);
                if ($e && $e->getCorrectsDocument() !== null) { $this->em->remove($e); }
            }
            foreach ($this->created['statements'] as $id) {
                $e = $this->em->find(InstrumentistStatement::class, $id);
                if ($e && $e->getCorrectsDocument() !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->created['invoices'] as $id) { $e = $this->em->find(FirmInvoice::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['statements'] as $id) { $e = $this->em->find(InstrumentistStatement::class, $id); if ($e) $this->em->remove($e); }
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
        $u->setEmail('fcctrl-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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

    /** @return array{0: FirmInvoice, 1: array} facture SENT (via API) + sa ligne sérialisée unique. */
    private function makeSentInvoiceWithLine(KernelBrowser $client, string $token, User $manager, string $unitPrice = '300.00'): array
    {
        $firm = new Firm();
        $firm->setName('FCCTRL-' . bin2hex(random_bytes(3)));
        $this->em->persist($firm); $this->em->flush();
        $this->created['firms'][] = $firm->getId();

        $type = new InterventionType();
        $type->setCode('FCCTRL-' . bin2hex(random_bytes(3)));
        $type->setLabel('FCCTRL');
        $this->em->persist($type); $this->em->flush();
        $this->created['types'][] = $type->getId();

        $rule = new PricingRule();
        $rule->setFirm($firm);
        $rule->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $rule->setInterventionType($type);
        $rule->setUnitPrice($unitPrice);
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
        $site->setName('FCCTRL-Site-' . bin2hex(random_bytes(3)));
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
        $intervention->setLabel('FCCTRL');
        $intervention->setInterventionType($type);
        $intervention->setPrimaryFirm($firm);
        $this->em->persist($intervention); $this->em->flush();
        $this->created['interventions'][] = $intervention->getId();
        $mission->getInterventions()->add($intervention);

        $calcService = static::getContainer()->get(FinancialCalculationService::class);
        $calc = $calcService->calculate($mission, $manager);
        $calc = $calcService->approve($calc, $manager);
        $this->created['calculations'][] = $calc->getId();
        $firmLine = $calc->getLines()->filter(static fn ($l) => $l->getLineType()->value === 'FIRM_INTERVENTION_FEE')->first();

        $invoiceService = static::getContainer()->get(FirmInvoiceService::class);
        $invoice = $invoiceService->createFromEligibleLines($firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'), [$firmLine->getId()], $manager);
        $this->created['invoices'][] = $invoice->getId();

        $issueResponse = $this->postJson($client, $token, "/api/firm-invoices/{$invoice->getId()}/issue");
        self::assertSame(Response::HTTP_OK, $issueResponse->getStatusCode(), (string) $issueResponse->getContent());

        $detail = json_decode((string) $issueResponse->getContent(), true);
        return [$this->em->find(FirmInvoice::class, $invoice->getId()), $detail['lines'][0]];
    }

    // ── Note de crédit — cycle complet ─────────────────────────────────────

    public function test_manager_can_create_and_issue_a_credit_note_then_see_it_in_corrections(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        [$invoice, $line] = $this->makeSentInvoiceWithLine($client, $token, $manager);

        $createResponse = $this->postJson($client, $token, "/api/firm-invoices/{$invoice->getId()}/credit-notes", [
            'lines' => [[
                'originalDocumentLineId' => $line['id'],
                'reasonCode' => 'WRONG_QUANTITY',
                'description' => 'Quantité corrigée',
                'quantity' => '1',
                'unitAmount' => '100.00',
            ]],
        ]);
        self::assertSame(Response::HTTP_CREATED, $createResponse->getStatusCode(), (string) $createResponse->getContent());
        $creditNote = json_decode((string) $createResponse->getContent(), true);
        $this->created['invoices'][] = $creditNote['id'];
        self::assertSame('CREDIT_NOTE', $creditNote['documentType']);
        self::assertSame('GENERATED', $creditNote['status']);
        self::assertNull($creditNote['number']);

        $issueResponse = $this->postJson($client, $token, "/api/firm-invoice-corrections/{$creditNote['id']}/issue");
        self::assertSame(Response::HTTP_OK, $issueResponse->getStatusCode(), (string) $issueResponse->getContent());
        $issued = json_decode((string) $issueResponse->getContent(), true);
        self::assertSame('SENT', $issued['status']);
        self::assertNotNull($issued['number']);
        self::assertStringContainsString('FIRM-CN', $issued['number']);

        $listResponse = $this->getJson($client, $token, "/api/firm-invoices/{$invoice->getId()}/corrections");
        self::assertSame(Response::HTTP_OK, $listResponse->getStatusCode());
        $corrections = json_decode((string) $listResponse->getContent(), true);
        self::assertCount(1, $corrections);
        self::assertSame($creditNote['id'], $corrections[0]['id']);

        $rootDetail = json_decode((string) $this->getJson($client, $token, "/api/firm-invoices/{$invoice->getId()}")->getContent(), true);
        self::assertSame('200.00', $rootDetail['netDocumentAmount']);
        self::assertSame('100.00', $rootDetail['creditNotesAmount']);
        self::assertCount(1, $rootDetail['corrections']);
    }

    // ── Note de débit + remboursement ───────────────────────────────────────

    public function test_debit_note_creates_due_balance_then_refund_after_overpayment(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        [$invoice, $line] = $this->makeSentInvoiceWithLine($client, $token, $manager, '800.00');

        $paymentResponse = $this->postJson($client, $token, "/api/firm-invoices/{$invoice->getId()}/payments", [
            'amount' => '800.00', 'currency' => 'EUR', 'paidAt' => '2026-06-20', 'method' => 'BANK_TRANSFER',
        ]);
        self::assertSame(Response::HTTP_CREATED, $paymentResponse->getStatusCode());
        $this->created['payments'][] = json_decode((string) $paymentResponse->getContent(), true)['id'];

        $missionId = $this->em->getRepository(\App\Entity\FirmInvoiceLine::class)->find($line['id'])->getMission()->getId();
        $debitResponse = $this->postJson($client, $token, "/api/firm-invoices/{$invoice->getId()}/debit-notes", [
            'lines' => [[
                'reasonCode' => 'OMITTED_LINE',
                'description' => 'Prestation oubliée',
                'quantity' => '1',
                'unitAmount' => '100.00',
                'missionId' => $missionId,
            ]],
        ]);
        self::assertSame(Response::HTTP_CREATED, $debitResponse->getStatusCode(), (string) $debitResponse->getContent());
        $debitNote = json_decode((string) $debitResponse->getContent(), true);
        $this->created['invoices'][] = $debitNote['id'];

        $this->postJson($client, $token, "/api/firm-invoice-corrections/{$debitNote['id']}/issue");

        $rootAfterDebit = json_decode((string) $this->getJson($client, $token, "/api/firm-invoices/{$invoice->getId()}")->getContent(), true);
        self::assertSame('900.00', $rootAfterDebit['netDocumentAmount']);
        self::assertSame('100.00', $rootAfterDebit['remainingAmount']);

        // Rembourse un trop-perçu inexistant à ce stade — doit être refusé.
        $refundTooEarly = $this->postJson($client, $token, "/api/firm-invoices/{$invoice->getId()}/refunds", [
            'amount' => '50.00', 'currency' => 'EUR', 'paidAt' => '2026-06-21', 'method' => 'BANK_TRANSFER',
        ]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $refundTooEarly->getStatusCode());
        self::assertSame('REFUND_EXCEEDS_OVERPAID', json_decode((string) $refundTooEarly->getContent(), true)['error']['code']);

        // Complète le paiement du solde dû.
        $completion = $this->postJson($client, $token, "/api/firm-invoices/{$invoice->getId()}/payments", [
            'amount' => '100.00', 'currency' => 'EUR', 'paidAt' => '2026-06-22', 'method' => 'CASH',
        ]);
        self::assertSame(Response::HTTP_CREATED, $completion->getStatusCode());
        $this->created['payments'][] = json_decode((string) $completion->getContent(), true)['id'];

        $rootFinal = json_decode((string) $this->getJson($client, $token, "/api/firm-invoices/{$invoice->getId()}")->getContent(), true);
        self::assertSame('PAID', $rootFinal['paymentStatus']);
        self::assertSame('0.00', $rootFinal['remainingAmount']);
    }

    // ── Éligibilité / erreurs structurées ───────────────────────────────────

    public function test_credit_note_on_generated_invoice_returns_409(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);

        $firm = new Firm();
        $firm->setName('FCCTRL-Gen-' . bin2hex(random_bytes(3)));
        $this->em->persist($firm); $this->em->flush();
        $this->created['firms'][] = $firm->getId();
        $type = new InterventionType();
        $type->setCode('FCCTRL-Gen-' . bin2hex(random_bytes(3)));
        $type->setLabel('FCCTRL-Gen');
        $rule = new PricingRule();
        $rule->setFirm($firm);
        $rule->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $rule->setInterventionType($type);
        $rule->setUnitPrice('150.00');
        $this->em->persist($type); $this->em->persist($rule); $this->em->flush();
        $this->created['types'][] = $type->getId();
        $this->created['rules'][] = $rule->getId();
        $site = new Hospital();
        $site->setName('FCCTRL-GenSite-' . bin2hex(random_bytes(3)));
        $this->em->persist($site); $this->em->flush();
        $this->created['sites'][] = $site->getId();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');
        $rate = new InstrumentistRate();
        $rate->setInstrumentist($instrumentist);
        $rate->setRateType(InstrumentistRateType::HOURLY_RATE);
        $rate->setAmount('40.00');
        $rate->setCurrency('EUR');
        $rate->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($rate); $this->em->flush();
        $this->created['rates'][] = $rate->getId();

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
        $intervention->setLabel('FCCTRL-Gen');
        $intervention->setInterventionType($type);
        $intervention->setPrimaryFirm($firm);
        $this->em->persist($intervention); $this->em->flush();
        $this->created['interventions'][] = $intervention->getId();
        $mission->getInterventions()->add($intervention);

        $calcService = static::getContainer()->get(FinancialCalculationService::class);
        $calc = $calcService->calculate($mission, $manager);
        $calc = $calcService->approve($calc, $manager);
        $this->created['calculations'][] = $calc->getId();
        $firmLine = $calc->getLines()->filter(static fn ($l) => $l->getLineType()->value === 'FIRM_INTERVENTION_FEE')->first();

        $invoiceService = static::getContainer()->get(FirmInvoiceService::class);
        $invoice = $invoiceService->createFromEligibleLines($firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'), [$firmLine->getId()], $manager);
        $this->created['invoices'][] = $invoice->getId();
        $lineId = $invoice->getLines()->first()->getId();

        $response = $this->postJson($client, $token, "/api/firm-invoices/{$invoice->getId()}/credit-notes", [
            'lines' => [[
                'originalDocumentLineId' => $lineId,
                'reasonCode' => 'WRONG_QUANTITY',
                'description' => 'Correction',
                'quantity' => '1',
                'unitAmount' => '10.00',
            ]],
        ]);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('CORRECTION_NOT_ELIGIBLE', json_decode((string) $response->getContent(), true)['error']['code']);
    }

    public function test_credit_note_without_lines_returns_422(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        [$invoice] = $this->makeSentInvoiceWithLine($client, $token, $manager);

        $response = $this->postJson($client, $token, "/api/firm-invoices/{$invoice->getId()}/credit-notes", ['lines' => []]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', json_decode((string) $response->getContent(), true)['error']['code']);
    }

    // ── Permissions (§27) ────────────────────────────────────────────────

    public function test_instrumentist_cannot_create_issue_or_refund_corrections(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);
        [$invoice, $line] = $this->makeSentInvoiceWithLine($client, $managerToken, $manager);

        $otherInstrumentist = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $otherInstrumentist);

        $createResponse = $this->postJson($client, $token, "/api/firm-invoices/{$invoice->getId()}/credit-notes", [
            'lines' => [['originalDocumentLineId' => $line['id'], 'reasonCode' => 'WRONG_QUANTITY', 'description' => 'x', 'quantity' => '1', 'unitAmount' => '10.00']],
        ]);
        self::assertSame(Response::HTTP_FORBIDDEN, $createResponse->getStatusCode());

        $listResponse = $this->getJson($client, $token, "/api/firm-invoices/{$invoice->getId()}/corrections");
        self::assertSame(Response::HTTP_FORBIDDEN, $listResponse->getStatusCode());

        $refundResponse = $this->postJson($client, $token, "/api/firm-invoices/{$invoice->getId()}/refunds", [
            'amount' => '10.00', 'currency' => 'EUR', 'paidAt' => '2026-06-20', 'method' => 'BANK_TRANSFER',
        ]);
        self::assertSame(Response::HTTP_FORBIDDEN, $refundResponse->getStatusCode());

        // Crée une vraie note de crédit avec le manager pour vérifier le refus d'émission.
        $creditNote = json_decode((string) $this->postJson($client, $managerToken, "/api/firm-invoices/{$invoice->getId()}/credit-notes", [
            'lines' => [['originalDocumentLineId' => $line['id'], 'reasonCode' => 'WRONG_QUANTITY', 'description' => 'x', 'quantity' => '1', 'unitAmount' => '10.00']],
        ])->getContent(), true);
        $this->created['invoices'][] = $creditNote['id'];

        $issueResponse = $this->postJson($client, $token, "/api/firm-invoice-corrections/{$creditNote['id']}/issue");
        self::assertSame(Response::HTTP_FORBIDDEN, $issueResponse->getStatusCode());
    }

    // ── PDF (§25) ────────────────────────────────────────────────────────

    public function test_credit_note_pdf_renders_successfully(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        [$invoice, $line] = $this->makeSentInvoiceWithLine($client, $token, $manager);

        $creditNote = json_decode((string) $this->postJson($client, $token, "/api/firm-invoices/{$invoice->getId()}/credit-notes", [
            'lines' => [['originalDocumentLineId' => $line['id'], 'reasonCode' => 'WRONG_QUANTITY', 'description' => 'Correction PDF', 'quantity' => '1', 'unitAmount' => '100.00']],
        ])->getContent(), true);
        $this->created['invoices'][] = $creditNote['id'];
        $this->postJson($client, $token, "/api/firm-invoice-corrections/{$creditNote['id']}/issue");

        $pdfResponse = $this->getJson($client, $token, "/api/firm-invoices/{$creditNote['id']}/pdf");
        self::assertSame(Response::HTTP_OK, $pdfResponse->getStatusCode());
        self::assertSame('application/pdf', $pdfResponse->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', $pdfResponse->getContent());

        $rootPdfResponse = $this->getJson($client, $token, "/api/firm-invoices/{$invoice->getId()}/pdf");
        self::assertSame(Response::HTTP_OK, $rootPdfResponse->getStatusCode());
        self::assertStringStartsWith('%PDF', $rootPdfResponse->getContent());
    }

    public function test_instrumentist_statement_credit_note_pdf_renders_successfully(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);

        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');
        $rate = new InstrumentistRate();
        $rate->setInstrumentist($instrumentist);
        $rate->setRateType(InstrumentistRateType::HOURLY_RATE);
        $rate->setAmount('60.00');
        $rate->setCurrency('EUR');
        $rate->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($rate); $this->em->flush();
        $this->created['rates'][] = $rate->getId();

        $site = new Hospital();
        $site->setName('FCCTRL-StmtSite-' . bin2hex(random_bytes(3)));
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
        $mission->setEndAt($today->modify('+2 hours'));
        $mission->setStatus(MissionStatus::VALIDATED);
        $mission->setInstrumentist($instrumentist);
        $this->em->persist($mission); $this->em->flush();
        $this->created['missions'][] = $mission->getId();

        $calcService = static::getContainer()->get(FinancialCalculationService::class);
        $calc = $calcService->calculate($mission, $manager);
        $calc = $calcService->approve($calc, $manager);
        $this->created['calculations'][] = $calc->getId();
        $instrLine = $calc->getLines()->first();

        $statementService = static::getContainer()->get(InstrumentistStatementService::class);
        $statement = $statementService->createFromEligibleLines($instrumentist, 'EUR', (int) $today->format('Y'), (int) $today->format('m'), [$instrLine->getId()], $manager);
        $this->created['statements'][] = $statement->getId();
        $lineId = $statement->getLines()->first()->getId();

        $issueResponse = $this->postJson($client, $token, "/api/instrumentist-statements/{$statement->getId()}/issue");
        self::assertSame(Response::HTTP_OK, $issueResponse->getStatusCode(), (string) $issueResponse->getContent());

        $creditNote = json_decode((string) $this->postJson($client, $token, "/api/instrumentist-statements/{$statement->getId()}/credit-notes", [
            'lines' => [['originalDocumentLineId' => $lineId, 'reasonCode' => 'WRONG_DURATION', 'description' => 'Durée corrigée', 'quantity' => '1', 'unitAmount' => '30.00']],
        ])->getContent(), true);
        $this->created['statements'][] = $creditNote['id'];
        $this->postJson($client, $token, "/api/instrumentist-statement-corrections/{$creditNote['id']}/issue");

        $pdfResponse = $this->getJson($client, $token, "/api/instrumentist-statements/{$creditNote['id']}/pdf");
        self::assertSame(Response::HTTP_OK, $pdfResponse->getStatusCode(), (string) $pdfResponse->getContent());
        self::assertStringStartsWith('%PDF', $pdfResponse->getContent());

        $rootPdfResponse = $this->getJson($client, $token, "/api/instrumentist-statements/{$statement->getId()}/pdf");
        self::assertSame(Response::HTTP_OK, $rootPdfResponse->getStatusCode());
        self::assertStringStartsWith('%PDF', $rootPdfResponse->getContent());
    }
}
