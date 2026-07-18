<?php

namespace App\Tests\Integration;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\FinancialCalculation;
use App\Entity\FirmInvoice;
use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\InstrumentistStatement;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\Payment;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\InstrumentistRateType;
use App\Enum\InvoiceStatus;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Enum\PricingRuleType;
use App\Exception\DocumentNotIssuedException;
use App\Exception\PaymentCurrencyMismatchException;
use App\Exception\PaymentExceedsRemainingException;
use App\Service\DocumentPaymentService;
use App\Service\FinancialCalculationService;
use App\Service\FirmInvoiceService;
use App\Service\InstrumentistStatementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * EPIC Exécution & Valorisation, Lot 5 (D-075) — §23 du lot : émission, numérotation,
 * audit, paiement unique/multiple/partiel/complet, refus de dépassement, devise
 * invalide, compatibilité legacy. Appels réels contre une base réelle.
 */
final class DocumentPaymentServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FinancialCalculationService $calcService;
    private FirmInvoiceService $invoiceService;
    private InstrumentistStatementService $statementService;
    private DocumentPaymentService $paymentService;
    private array $created = [
        'payments' => [], 'invoices' => [], 'statements' => [],
        'calculations' => [], 'lines' => [], 'interventions' => [], 'missions' => [],
        'rates' => [], 'rules' => [], 'items' => [], 'types' => [], 'firms' => [], 'sites' => [], 'users' => [],
    ];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->calcService = self::getContainer()->get(FinancialCalculationService::class);
        $this->invoiceService = self::getContainer()->get(FirmInvoiceService::class);
        $this->statementService = self::getContainer()->get(InstrumentistStatementService::class);
        $this->paymentService = self::getContainer()->get(DocumentPaymentService::class);
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen()) {
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

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('dps-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setPassword('x');
        $this->em->persist($u); $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    /** Facture GENERATED (nouveau flux Lot 4) avec un montant brut connu. */
    private function makeGeneratedInvoice(string $unitPrice, User $actor): FirmInvoice
    {
        $firm = new Firm();
        $firm->setName('DPS-' . bin2hex(random_bytes(3)));
        $this->em->persist($firm); $this->em->flush();
        $this->created['firms'][] = $firm->getId();

        $type = new InterventionType();
        $type->setCode('DPS-' . bin2hex(random_bytes(3)));
        $type->setLabel('DPS');
        $this->em->persist($type); $this->em->flush();
        $this->created['types'][] = $type->getId();

        $rule = new PricingRule();
        $rule->setFirm($firm);
        $rule->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $rule->setInterventionType($type);
        $rule->setUnitPrice($unitPrice);
        $this->em->persist($rule); $this->em->flush();
        $this->created['rules'][] = $rule->getId();

        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $rate = new InstrumentistRate();
        $rate->setInstrumentist($instrumentist);
        $rate->setRateType(InstrumentistRateType::HOURLY_RATE);
        $rate->setAmount('40.00');
        $rate->setCurrency('EUR');
        $rate->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($rate); $this->em->flush();
        $this->created['rates'][] = $rate->getId();

        $site = new Hospital();
        $site->setName('DPS-Site-' . bin2hex(random_bytes(3)));
        $this->em->persist($site); $this->em->flush();
        $this->created['sites'][] = $site->getId();
        $surgeon = $this->makeUser('ROLE_SURGEON');

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
        $intervention->setLabel('DPS');
        $intervention->setInterventionType($type);
        $intervention->setPrimaryFirm($firm);
        $this->em->persist($intervention); $this->em->flush();
        $this->created['interventions'][] = $intervention->getId();
        $mission->getInterventions()->add($intervention);

        $calc = $this->calcService->calculate($mission, $actor);
        $calc = $this->calcService->approve($calc, $actor);
        $this->created['calculations'][] = $calc->getId();

        $firmLine = null;
        foreach ($calc->getLines() as $l) {
            if ($l->getLineType()->value === 'FIRM_INTERVENTION_FEE') { $firmLine = $l; }
        }

        $invoice = $this->invoiceService->createFromEligibleLines(
            $firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'), [$firmLine->getId()], $actor,
        );
        $this->created['invoices'][] = $invoice->getId();

        return $invoice;
    }

    private function issueInvoice(FirmInvoice $invoice, User $actor): FirmInvoice
    {
        return $this->invoiceService->issue($invoice, $actor);
    }

    // ── Émission ──────────────────────────────────────────────────────────

    public function test_issue_transitions_generated_to_sent_dates_and_audits(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        $invoice = $this->makeGeneratedInvoice('180.00', $actor);
        self::assertSame(InvoiceStatus::GENERATED, $invoice->getStatus());
        $numberBefore = $invoice->getNumber();
        self::assertNotNull($numberBefore, 'le numéro est déjà attribué à la création dans ce système');

        $issued = $this->issueInvoice($invoice, $actor);

        self::assertSame(InvoiceStatus::SENT, $issued->getStatus());
        self::assertNotNull($issued->getSentAt());
        self::assertSame($numberBefore, $issued->getNumber(), 'issue() n\'écrase jamais un numéro déjà attribué');

        $events = $this->em->getRepository(AuditEvent::class)->findBy(['actor' => $actor], ['id' => 'ASC']);
        $types = array_map(static fn (AuditEvent $e) => $e->getEventType()->value, $events);
        self::assertContains(AuditEventType::FIRM_INVOICE_ISSUED->value, $types);
    }

    public function test_issue_rejects_non_generated_invoice(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        $invoice = $this->makeGeneratedInvoice('100.00', $actor);
        $this->issueInvoice($invoice, $actor);

        $this->expectException(\DomainException::class);
        $this->invoiceService->issue($invoice, $actor);
    }

    // ── Paiement — refus avant émission ──────────────────────────────────

    public function test_payment_rejected_before_document_is_sent(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        $invoice = $this->makeGeneratedInvoice('100.00', $actor);

        $this->expectException(DocumentNotIssuedException::class);
        $this->paymentService->recordPayment($invoice, '100.00', 'EUR', new \DateTimeImmutable('2026-06-20'), PaymentMethod::BANK_TRANSFER, null, null, $actor);
    }

    // ── Paiement unique / complet ────────────────────────────────────────

    public function test_single_full_payment_marks_document_paid(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        $invoice = $this->issueInvoice($this->makeGeneratedInvoice('180.00', $actor), $actor);

        $payment = $this->paymentService->recordPayment(
            $invoice, '180.00', 'EUR', new \DateTimeImmutable('2026-06-20'),
            PaymentMethod::BANK_TRANSFER, 'REF-001', 'paiement complet', $actor,
        );
        $this->created['payments'][] = $payment->getId();

        self::assertSame('180.00', $payment->getAmount());
        self::assertSame('REF-001', $payment->getReference());
        self::assertSame(PaymentMethod::BANK_TRANSFER, $payment->getMethod());

        $balance = $this->paymentService->computeBalance($invoice);
        self::assertSame('180.00', $balance->paidAmount);
        self::assertSame('0.00', $balance->remainingAmount);
        self::assertSame(PaymentStatus::PAID, $balance->status);

        // Le document (dimension DOCUMENTAIRE) reste SENT — PAID est une dimension
        // FINANCIÈRE distincte, jamais mélangée dans InvoiceStatus (§2 du lot).
        self::assertSame(InvoiceStatus::SENT, $invoice->getStatus());

        $events = $this->em->getRepository(AuditEvent::class)->findBy(['actor' => $actor], ['id' => 'ASC']);
        $types = array_map(static fn (AuditEvent $e) => $e->getEventType()->value, $events);
        self::assertContains(AuditEventType::DOCUMENT_PAYMENT_RECORDED->value, $types);
        self::assertContains(AuditEventType::DOCUMENT_FULLY_PAID->value, $types);
        self::assertNotContains(AuditEventType::DOCUMENT_PARTIALLY_PAID->value, $types);
    }

    // ── Paiements multiples / partiels ───────────────────────────────────

    public function test_multiple_partial_payments_accumulate_to_paid(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        $invoice = $this->issueInvoice($this->makeGeneratedInvoice('1000.00', $actor), $actor);

        $p1 = $this->paymentService->recordPayment($invoice, '400.00', 'EUR', new \DateTimeImmutable('2026-06-20'), PaymentMethod::BANK_TRANSFER, null, null, $actor);
        $this->created['payments'][] = $p1->getId();

        $balanceAfterFirst = $this->paymentService->computeBalance($invoice);
        self::assertSame('400.00', $balanceAfterFirst->paidAmount);
        self::assertSame('600.00', $balanceAfterFirst->remainingAmount);
        self::assertSame(PaymentStatus::PARTIALLY_PAID, $balanceAfterFirst->status);

        $p2 = $this->paymentService->recordPayment($invoice, '300.00', 'EUR', new \DateTimeImmutable('2026-06-25'), PaymentMethod::CASH, null, null, $actor);
        $this->created['payments'][] = $p2->getId();

        $balanceAfterSecond = $this->paymentService->computeBalance($invoice);
        self::assertSame('700.00', $balanceAfterSecond->paidAmount);
        self::assertSame('300.00', $balanceAfterSecond->remainingAmount);
        self::assertSame(PaymentStatus::PARTIALLY_PAID, $balanceAfterSecond->status);

        $p3 = $this->paymentService->recordPayment($invoice, '300.00', 'EUR', new \DateTimeImmutable('2026-06-30'), PaymentMethod::OTHER, 'solde', null, $actor);
        $this->created['payments'][] = $p3->getId();

        $final = $this->paymentService->computeBalance($invoice);
        self::assertSame('1000.00', $final->paidAmount);
        self::assertSame('0.00', $final->remainingAmount);
        self::assertSame(PaymentStatus::PAID, $final->status);

        self::assertCount(3, $this->paymentService->getPaymentsFor($invoice));
    }

    // ── Refus de dépassement ──────────────────────────────────────────────

    public function test_payment_exceeding_remaining_is_rejected(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        $invoice = $this->issueInvoice($this->makeGeneratedInvoice('1000.00', $actor), $actor);

        $p1 = $this->paymentService->recordPayment($invoice, '800.00', 'EUR', new \DateTimeImmutable('2026-06-20'), PaymentMethod::BANK_TRANSFER, null, null, $actor);
        $this->created['payments'][] = $p1->getId();

        $this->expectException(PaymentExceedsRemainingException::class);
        $this->paymentService->recordPayment($invoice, '300.00', 'EUR', new \DateTimeImmutable('2026-06-25'), PaymentMethod::BANK_TRANSFER, null, null, $actor);
    }

    public function test_payment_exceeding_remaining_leaves_balance_unchanged(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        $invoice = $this->issueInvoice($this->makeGeneratedInvoice('500.00', $actor), $actor);

        try {
            $this->paymentService->recordPayment($invoice, '600.00', 'EUR', new \DateTimeImmutable('2026-06-20'), PaymentMethod::BANK_TRANSFER, null, null, $actor);
            self::fail('Devait lever PaymentExceedsRemainingException.');
        } catch (PaymentExceedsRemainingException) {
        }

        $balance = $this->paymentService->computeBalance($invoice);
        self::assertSame('0.00', $balance->paidAmount);
        self::assertSame('500.00', $balance->remainingAmount);
        self::assertSame(PaymentStatus::UNPAID, $balance->status);
        self::assertCount(0, $this->paymentService->getPaymentsFor($invoice));
    }

    // ── Devise invalide ───────────────────────────────────────────────────

    public function test_payment_currency_mismatch_is_rejected(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        $invoice = $this->issueInvoice($this->makeGeneratedInvoice('200.00', $actor), $actor);

        $this->expectException(PaymentCurrencyMismatchException::class);
        $this->paymentService->recordPayment($invoice, '200.00', 'USD', new \DateTimeImmutable('2026-06-20'), PaymentMethod::BANK_TRANSFER, null, null, $actor);
    }

    // ── Legacy — document PAID sans aucun Payment ────────────────────────

    public function test_legacy_paid_document_without_payment_rows_is_treated_as_fully_settled(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        $invoice = $this->issueInvoice($this->makeGeneratedInvoice('250.00', $actor), $actor);
        $invoice = $this->invoiceService->markPaid($invoice); // chemin legacy Lot 1, aucun Payment créé

        self::assertSame(InvoiceStatus::PAID, $invoice->getStatus());
        self::assertCount(0, $this->paymentService->getPaymentsFor($invoice));

        $balance = $this->paymentService->computeBalance($invoice);
        self::assertSame('250.00', $balance->paidAmount);
        self::assertSame('0.00', $balance->remainingAmount);
        self::assertSame(PaymentStatus::PAID, $balance->status);
    }

    // ── Décompte instrumentiste — même service, mêmes règles ─────────────

    public function test_instrumentist_statement_payment_shares_the_same_service(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $rate = new InstrumentistRate();
        $rate->setInstrumentist($instrumentist);
        $rate->setRateType(InstrumentistRateType::HOURLY_RATE);
        $rate->setAmount('50.00');
        $rate->setCurrency('EUR');
        $rate->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($rate); $this->em->flush();
        $this->created['rates'][] = $rate->getId();

        $site = new Hospital();
        $site->setName('DPS-Stmt-' . bin2hex(random_bytes(3)));
        $this->em->persist($site); $this->em->flush();
        $this->created['sites'][] = $site->getId();
        $surgeon = $this->makeUser('ROLE_SURGEON');

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

        $calc = $this->calcService->calculate($mission, $actor);
        $calc = $this->calcService->approve($calc, $actor);
        $this->created['calculations'][] = $calc->getId();
        $instrLine = $calc->getLines()->first();

        $statement = $this->statementService->createFromEligibleLines(
            $instrumentist, 'EUR', (int) $today->format('Y'), (int) $today->format('m'), [$instrLine->getId()], $actor,
        );
        $this->created['statements'][] = $statement->getId();

        $statement = $this->statementService->issue($statement, $actor);
        self::assertSame(InvoiceStatus::SENT, $statement->getStatus());

        $payment = $this->paymentService->recordPayment(
            $statement, $statement->getTotalAmount(), 'EUR', new \DateTimeImmutable('2026-06-20'),
            PaymentMethod::BANK_TRANSFER, null, null, $actor,
        );
        $this->created['payments'][] = $payment->getId();

        $balance = $this->paymentService->computeBalance($statement);
        self::assertSame(PaymentStatus::PAID, $balance->status);

        $events = $this->em->getRepository(AuditEvent::class)->findBy(['actor' => $actor], ['id' => 'ASC']);
        $types = array_map(static fn (AuditEvent $e) => $e->getEventType()->value, $events);
        self::assertContains(AuditEventType::INSTRUMENTIST_STATEMENT_ISSUED->value, $types);
        self::assertContains(AuditEventType::DOCUMENT_FULLY_PAID->value, $types);
    }
}
