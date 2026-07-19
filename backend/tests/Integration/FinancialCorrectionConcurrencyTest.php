<?php

namespace App\Tests\Integration;

use App\Dto\CorrectionLineInput;
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
use App\Enum\CorrectionReasonCode;
use App\Enum\InstrumentistRateType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PaymentMethod;
use App\Enum\PricingRuleType;
use App\Exception\CorrectionValidationException;
use App\Exception\RefundExceedsOverpaidException;
use App\Service\AuditService;
use App\Service\DocumentPaymentService;
use App\Service\FinancialCalculationService;
use App\Service\FinancialCorrectionService;
use App\Service\FirmInvoiceService;
use App\Service\InstrumentistRateResolver;
use App\Service\InstrumentistStatementService;
use App\Service\MissionExecutionService;
use App\Service\PricingRuleResolver;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * EPIC Exécution & Valorisation, Lot 6 (D-076) — §29 du lot : deux managers créant
 * simultanément deux crédits sur la même ligne, ou deux remboursements, ne doivent
 * jamais dépasser la limite corrigible / le trop-perçu réel. Même méthode que
 * FirmInvoiceConcurrencyTest (Lot 4) : connexions DBAL réellement distinctes, un
 * worker tient le verrou pessimiste sur le document RACINE (jamais sur la correction
 * elle-même — §29 du lot) le temps du test.
 */
final class FinancialCorrectionConcurrencyTest extends KernelTestCase
{
    private const LOCK_TIMEOUT_SECONDS = 2;

    private EntityManagerInterface $em;
    private array $created = [
        'payments' => [], 'invoices' => [], 'missions' => [], 'interventions' => [],
        'calculations' => [], 'rules' => [], 'rates' => [], 'types' => [], 'firms' => [], 'sites' => [], 'users' => [],
    ];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
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
            foreach ($this->created['invoices'] as $id) {
                $e = $this->em->find(FirmInvoice::class, $id);
                if ($e && $e->getCorrectsDocument() !== null) { $this->em->remove($e); }
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

    private function freshEntityManager(): EntityManagerInterface
    {
        return new \Doctrine\ORM\EntityManager(
            \Doctrine\DBAL\DriverManager::getConnection($this->em->getConnection()->getParams()),
            $this->em->getConfiguration(),
        );
    }

    private function financialCalculationServiceFor(EntityManagerInterface $em): FinancialCalculationService
    {
        $audit = new AuditService($em);
        return new FinancialCalculationService(
            $em,
            new PricingRuleResolver($em),
            new InstrumentistRateResolver($em),
            new MissionExecutionService($em, $audit),
            $audit,
        );
    }

    private function firmInvoiceServiceFor(EntityManagerInterface $em): FirmInvoiceService
    {
        return new FirmInvoiceService($em, $this->financialCalculationServiceFor($em), new AuditService($em));
    }

    private function instrumentistStatementServiceFor(EntityManagerInterface $em): InstrumentistStatementService
    {
        return new InstrumentistStatementService($em, $this->financialCalculationServiceFor($em), new AuditService($em));
    }

    private function documentPaymentServiceFor(EntityManagerInterface $em): DocumentPaymentService
    {
        return new DocumentPaymentService($em, new AuditService($em));
    }

    private function correctionServiceFor(EntityManagerInterface $em): FinancialCorrectionService
    {
        return new FinancialCorrectionService(
            $em,
            $this->firmInvoiceServiceFor($em),
            $this->instrumentistStatementServiceFor($em),
            $this->documentPaymentServiceFor($em),
            new AuditService($em),
        );
    }

    private function setLockTimeout(EntityManagerInterface $em, int $seconds): void
    {
        $em->getConnection()->executeStatement("SET SESSION innodb_lock_wait_timeout = {$seconds}");
    }

    private function isLockTimeoutError(\Throwable $e): bool
    {
        $message = $e->getMessage();
        return str_contains($message, 'Lock wait timeout') || str_contains($message, '1205');
    }

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('fcc-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setPassword('x');
        $this->em->persist($u); $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    /** @return array{0: FirmInvoice, 1: \App\Entity\FirmInvoiceLine, 2: User} facture SENT avec une ligne de 300.00. */
    private function makeIssuedInvoiceWithLine(string $unitPrice, User $actor): array
    {
        $firm = new Firm();
        $firm->setName('FCC-' . bin2hex(random_bytes(3)));
        $this->em->persist($firm); $this->em->flush();
        $this->created['firms'][] = $firm->getId();

        $type = new InterventionType();
        $type->setCode('FCC-' . bin2hex(random_bytes(3)));
        $type->setLabel('FCC');
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
        $site->setName('FCC-Site-' . bin2hex(random_bytes(3)));
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
        $intervention->setLabel('FCC');
        $intervention->setInterventionType($type);
        $intervention->setPrimaryFirm($firm);
        $this->em->persist($intervention); $this->em->flush();
        $this->created['interventions'][] = $intervention->getId();
        $mission->getInterventions()->add($intervention);

        $calcService = $this->financialCalculationServiceFor($this->em);
        $calc = $calcService->calculate($mission, $actor);
        $calc = $calcService->approve($calc, $actor);
        $this->created['calculations'][] = $calc->getId();

        $firmLine = null;
        foreach ($calc->getLines() as $l) {
            if ($l->getLineType()->value === 'FIRM_INTERVENTION_FEE') { $firmLine = $l; }
        }

        $invoiceService = $this->firmInvoiceServiceFor($this->em);
        $invoice = $invoiceService->createFromEligibleLines(
            $firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'), [$firmLine->getId()], $actor,
        );
        $this->created['invoices'][] = $invoice->getId();
        $invoice = $invoiceService->issue($invoice, $actor);

        return [$invoice, $invoice->getLines()->first(), $actor];
    }

    // ── Deux crédits concurrents sur la même ligne (§22/§29) ──────────────

    public function test_two_concurrent_credit_notes_on_the_same_line_never_exceed_its_original_amount(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        [$invoice, $line, $actor] = $this->makeIssuedInvoiceWithLine('300.00', $actor);
        $invoiceId = $invoice->getId();
        $lineId = $line->getId();
        $actorId = $actor->getId();

        // Worker B : tient le verrou pessimiste sur le document racine (FirmInvoice),
        // transaction non committée — reproduit la fenêtre de contention réelle de
        // createCreditNote() (verrouille le document racine avant de valider les lignes).
        $emB = $this->freshEntityManager();
        $emB->getConnection()->beginTransaction();
        $invoiceB = $emB->find(FirmInvoice::class, $invoiceId);
        $emB->lock($invoiceB, LockMode::PESSIMISTIC_WRITE);

        // Worker A : tentative réelle de createCreditNote() sur la MÊME ligne, avec un
        // timeout court — doit être réellement bloqué.
        $emA = $this->freshEntityManager();
        $this->setLockTimeout($emA, self::LOCK_TIMEOUT_SECONDS);
        $invoiceA = $emA->find(FirmInvoice::class, $invoiceId);
        $actorA = $emA->find(User::class, $actorId);
        $inputA = new CorrectionLineInput($lineId, CorrectionReasonCode::WRONG_QUANTITY, 'Crédit A', '1', '200.00');

        $blocked = false;
        try {
            $this->correctionServiceFor($emA)->createCreditNote($invoiceA, [$inputA], null, $actorA);
        } catch (\Throwable $e) {
            $blocked = $this->isLockTimeoutError($e);
        }
        self::assertTrue($blocked, 'createCreditNote() doit être réellement bloqué par le verrou pessimiste tenu sur le même document racine.');

        // B crée son propre crédit de 200.00 puis commit — consomme les 2/3 de la ligne (300.00).
        $inputB = new CorrectionLineInput($lineId, CorrectionReasonCode::WRONG_QUANTITY, 'Crédit B', '1', '200.00');
        $actorBRef = $emB->find(User::class, $actorId);
        $creditB = $this->correctionServiceFor($emB)->createCreditNote($invoiceB, [$inputB], null, $actorBRef);
        $this->created['invoices'][] = $creditB->getId();
        $emB->getConnection()->commit();

        // A retente avec un EntityManager frais : la ligne n'a plus que 100.00 de marge
        // (300.00 - 200.00 déjà crédités par B) — sa demande de 200.00 doit être refusée.
        $emA2 = $this->freshEntityManager();
        $invoiceA2 = $emA2->find(FirmInvoice::class, $invoiceId);
        $actorA2 = $emA2->find(User::class, $actorId);
        $inputA2 = new CorrectionLineInput($lineId, CorrectionReasonCode::WRONG_QUANTITY, 'Crédit A retry', '1', '200.00');

        try {
            $this->correctionServiceFor($emA2)->createCreditNote($invoiceA2, [$inputA2], null, $actorA2);
            self::fail('Devait lever CorrectionValidationException (dépassement cumulé de la ligne, 200.00 + 200.00 > 300.00).');
        } catch (CorrectionValidationException $e) {
            $codes = array_map(static fn ($a) => $a->code, $e->getAnomalies());
            self::assertContains('CREDIT_EXCEEDS_ORIGINAL_LINE', $codes);
        }

        // Seul le crédit de B existe — jamais de dépassement cumulé, jamais de doublon.
        $all = $this->em->getRepository(FirmInvoice::class)->findBy(['correctsDocument' => $invoiceId]);
        self::assertCount(1, $all);
        self::assertSame('200.00', $all[0]->getTotalAmount());
    }

    // ── Deux remboursements concurrents (§15/§29) ──────────────────────────

    public function test_two_concurrent_refunds_never_exceed_the_real_overpayment(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        [$invoice, $line] = $this->makeIssuedInvoiceWithLine('800.00', $actor);
        $invoiceId = $invoice->getId();
        $actorId = $actor->getId();

        // Paye 800.00 puis crédite 300.00 — trop-perçu réel = 300.00.
        $paymentService = $this->documentPaymentServiceFor($this->em);
        $payment = $paymentService->recordPayment($invoice, '800.00', 'EUR', new \DateTimeImmutable('2026-06-20'), PaymentMethod::BANK_TRANSFER, null, null, $actor);
        $this->created['payments'][] = $payment->getId();

        $creditInput = new CorrectionLineInput($line->getId(), CorrectionReasonCode::WRONG_QUANTITY, 'Correction', '1', '300.00');
        $correctionService = $this->correctionServiceFor($this->em);
        $creditNote = $correctionService->createCreditNote($invoice, [$creditInput], null, $actor);
        $this->created['invoices'][] = $creditNote->getId();
        $correctionService->issueCorrection($creditNote, $actor);

        self::assertSame('300.00', $paymentService->computeBalance($invoice)->overpaidAmount);

        // Worker B : tient le verrou pessimiste sur le document racine.
        $emB = $this->freshEntityManager();
        $emB->getConnection()->beginTransaction();
        $invoiceB = $emB->find(FirmInvoice::class, $invoiceId);
        $emB->lock($invoiceB, LockMode::PESSIMISTIC_WRITE);

        // Worker A : tentative réelle de remboursement sur le MÊME document, timeout court.
        $emA = $this->freshEntityManager();
        $this->setLockTimeout($emA, self::LOCK_TIMEOUT_SECONDS);
        $invoiceA = $emA->find(FirmInvoice::class, $invoiceId);
        $actorA = $emA->find(User::class, $actorId);

        $blocked = false;
        try {
            $this->documentPaymentServiceFor($emA)->recordRefund($invoiceA, '200.00', 'EUR', new \DateTimeImmutable('2026-06-25'), PaymentMethod::BANK_TRANSFER, null, null, $actorA);
        } catch (\Throwable $e) {
            $blocked = $this->isLockTimeoutError($e);
        }
        self::assertTrue($blocked, 'recordRefund() doit être réellement bloqué par le verrou pessimiste tenu sur le même document racine.');

        // B rembourse 200.00 et commit — il reste 100.00 de trop-perçu réel.
        $actorBRef = $emB->find(User::class, $actorId);
        $refundB = $this->documentPaymentServiceFor($emB)->recordRefund($invoiceB, '200.00', 'EUR', new \DateTimeImmutable('2026-06-25'), PaymentMethod::BANK_TRANSFER, null, null, $actorBRef);
        $this->created['payments'][] = $refundB->getId();
        $emB->getConnection()->commit();

        // A retente avec un EntityManager frais : ne reste que 100.00 de trop-perçu —
        // sa demande de 200.00 doit être refusée.
        $emA2 = $this->freshEntityManager();
        $invoiceA2 = $emA2->find(FirmInvoice::class, $invoiceId);
        $actorA2 = $emA2->find(User::class, $actorId);

        try {
            $this->documentPaymentServiceFor($emA2)->recordRefund($invoiceA2, '200.00', 'EUR', new \DateTimeImmutable('2026-06-26'), PaymentMethod::BANK_TRANSFER, null, null, $actorA2);
            self::fail('Devait lever RefundExceedsOverpaidException (200.00 > 100.00 restant).');
        } catch (RefundExceedsOverpaidException) {
        }

        self::assertSame('100.00', $paymentService->computeBalance($invoice)->overpaidAmount, 'jamais de dépassement cumulé du trop-perçu réel.');
        self::assertCount(2, $paymentService->getPaymentsFor($invoice), '1 paiement + 1 seul remboursement réussi (B).');
    }
}
