<?php

namespace App\Tests\Integration;

use App\Dto\CorrectionLineInput;
use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\FinancialCalculation;
use App\Entity\FirmInvoice;
use App\Entity\FirmInvoiceLine;
use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\InstrumentistStatement;
use App\Entity\InstrumentistStatementLine;
use App\Entity\InterventionType;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\Payment;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\CorrectionReasonCode;
use App\Enum\FinancialDocumentType;
use App\Enum\InstrumentistRateType;
use App\Enum\InvoiceStatus;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Enum\PricingRuleType;
use App\Exception\CorrectionNotEligibleException;
use App\Exception\CorrectionValidationException;
use App\Exception\RefundExceedsOverpaidException;
use App\Service\DocumentPaymentService;
use App\Service\FinancialCalculationService;
use App\Service\FinancialCorrectionService;
use App\Service\FirmInvoiceService;
use App\Service\InstrumentistStatementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * EPIC Exécution & Valorisation, Lot 6 (D-076) — §32 du lot : avant émission,
 * notes de crédit, notes de débit, paiements/remboursements, legacy. Appels réels
 * contre une base réelle — pas de mock des services final.
 */
final class FinancialCorrectionServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FinancialCalculationService $calcService;
    private FirmInvoiceService $invoiceService;
    private InstrumentistStatementService $statementService;
    private DocumentPaymentService $paymentService;
    private FinancialCorrectionService $correctionService;
    private array $created = [
        'payments' => [], 'invoices' => [], 'statements' => [],
        'calculations' => [], 'interventions' => [], 'missions' => [],
        'rates' => [], 'rules' => [], 'types' => [], 'firms' => [], 'sites' => [], 'users' => [],
    ];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->calcService = self::getContainer()->get(FinancialCalculationService::class);
        $this->invoiceService = self::getContainer()->get(FirmInvoiceService::class);
        $this->statementService = self::getContainer()->get(InstrumentistStatementService::class);
        $this->paymentService = self::getContainer()->get(DocumentPaymentService::class);
        $this->correctionService = self::getContainer()->get(FinancialCorrectionService::class);
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
            // Les corrections (documentType != STANDARD) référencent leur racine via
            // correctsDocument — les libérer d'abord évite une contrainte FK au retrait
            // du document racine.
            foreach ($this->created['invoices'] as $id) {
                $e = $this->em->find(FirmInvoice::class, $id);
                if ($e && $e->getDocumentType() !== FinancialDocumentType::STANDARD) { $this->em->remove($e); }
            }
            foreach ($this->created['statements'] as $id) {
                $e = $this->em->find(InstrumentistStatement::class, $id);
                if ($e && $e->getDocumentType() !== FinancialDocumentType::STANDARD) { $this->em->remove($e); }
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

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('fcs-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setPassword('x');
        $this->em->persist($u); $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    /**
     * Construit une facture GENERATED avec une seule ligne FIRM_INTERVENTION_FEE de
     * montant connu (`$unitPrice`), sans jamais appeler issue(). Retourne aussi la
     * Mission sous-jacente (utile pour §10 : ligne oubliée référencée par missionId).
     *
     * @return array{0: FirmInvoice, 1: FirmInvoiceLine, 2: Mission, 3: Firm}
     */
    private function makeGeneratedInvoiceWithLine(string $unitPrice, User $actor): array
    {
        $firm = new Firm();
        $firm->setName('FCS-' . bin2hex(random_bytes(3)));
        $this->em->persist($firm); $this->em->flush();
        $this->created['firms'][] = $firm->getId();

        $type = new InterventionType();
        $type->setCode('FCS-' . bin2hex(random_bytes(3)));
        $type->setLabel('FCS');
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
        $site->setName('FCS-Site-' . bin2hex(random_bytes(3)));
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
        $intervention->setLabel('FCS');
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

        return [$invoice, $invoice->getLines()->first(), $mission, $firm];
    }

    /** @return array{0: FirmInvoice, 1: FirmInvoiceLine, 2: Mission, 3: Firm} facture SENT + sa ligne unique. */
    private function makeIssuedInvoiceWithLine(string $unitPrice, User $actor): array
    {
        [$invoice, $line, $mission, $firm] = $this->makeGeneratedInvoiceWithLine($unitPrice, $actor);
        $invoice = $this->invoiceService->issue($invoice, $actor);
        return [$invoice, $invoice->getLines()->first(), $mission, $firm];
    }

    /** Variante "legacy" (chemin Lot 1 inchangé, jamais de FinancialCalculationLine). */
    private function makeIssuedLegacyInvoiceWithLine(string $unitPrice, User $actor): array
    {
        $firm = new Firm();
        $firm->setName('FCS-Legacy-' . bin2hex(random_bytes(3)));
        $this->em->persist($firm); $this->em->flush();
        $this->created['firms'][] = $firm->getId();

        $type = new InterventionType();
        $type->setCode('FCS-Legacy-' . bin2hex(random_bytes(3)));
        $type->setLabel('FCS-Legacy');
        $rule = new PricingRule();
        $rule->setFirm($firm);
        $rule->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $rule->setInterventionType($type);
        $rule->setUnitPrice($unitPrice);
        $this->em->persist($type); $this->em->persist($rule); $this->em->flush();
        $this->created['types'][] = $type->getId();
        $this->created['rules'][] = $rule->getId();

        $site = new Hospital();
        $site->setName('FCS-LegacySite-' . bin2hex(random_bytes(3)));
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
        $this->em->persist($mission); $this->em->flush();
        $this->created['missions'][] = $mission->getId();

        $intervention = new MissionIntervention();
        $intervention->setMission($mission);
        $intervention->setCode($type->getCode());
        $intervention->setLabel('FCS-Legacy');
        $this->em->persist($intervention); $this->em->flush();
        $this->created['interventions'][] = $intervention->getId();

        $this->em->clear();
        $firm = $this->em->find(Firm::class, $firm->getId());
        $mission = $this->em->find(Mission::class, $mission->getId());
        $actor = $this->em->find(User::class, $actor->getId());

        $invoice = $this->invoiceService->generate(
            $firm, $today->modify('-1 day'), $today->modify('+1 day'), [$intervention->getId()], [],
        );
        $this->created['invoices'][] = $invoice->getId();
        $invoice->setBillingEmailTo('legacy@example.test');
        $invoice = $this->invoiceService->issue($invoice, $actor);

        // §em->clear() ci-dessus détache toute entité chargée avant cet appel — retourner
        // l'acteur rechargé évite qu'un appelant réutilise par erreur une référence
        // désormais détachée (ORMInvalidArgumentException au prochain flush).
        return [$invoice, $invoice->getLines()->first(), $mission, $firm, $actor];
    }

    /** @return array{0: InstrumentistStatement, 1: InstrumentistStatementLine, 2: Mission} décompte SENT + sa ligne unique. */
    private function makeIssuedStatementWithLine(string $hourlyRate, User $actor): array
    {
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $rate = new InstrumentistRate();
        $rate->setInstrumentist($instrumentist);
        $rate->setRateType(InstrumentistRateType::HOURLY_RATE);
        $rate->setAmount($hourlyRate);
        $rate->setCurrency('EUR');
        $rate->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($rate); $this->em->flush();
        $this->created['rates'][] = $rate->getId();

        $site = new Hospital();
        $site->setName('FCS-Stmt-' . bin2hex(random_bytes(3)));
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
        $line = $statement->getLines()->first();

        return [$statement, $line, $mission];
    }

    /** @param CorrectionLineInput[] $lines */
    private function issueCorrection(FirmInvoice|InstrumentistStatement $correction, User $actor): FirmInvoice|InstrumentistStatement
    {
        return $this->correctionService->issueCorrection($correction, $actor);
    }

    // ── Éligibilité (§21) ─────────────────────────────────────────────────

    public function test_correction_refused_on_generated_document(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        [$invoice, $line] = $this->makeGeneratedInvoiceWithLine('100.00', $actor);

        $input = new CorrectionLineInput(
            originalDocumentLineId: $line->getId(),
            reasonCode: CorrectionReasonCode::WRONG_QUANTITY,
            description: 'Correction',
            quantity: '1',
            unitAmount: '10.00',
        );

        $this->expectException(CorrectionNotEligibleException::class);
        $this->correctionService->createCreditNote($invoice, [$input], null, $actor);
    }

    public function test_cancel_remains_the_correct_path_for_a_generated_document(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        [$invoice] = $this->makeGeneratedInvoiceWithLine('100.00', $actor);

        $invoice = $this->invoiceService->cancel($invoice, $actor, 'erreur de saisie');
        self::assertSame(InvoiceStatus::CANCELLED, $invoice->getStatus());
    }

    public function test_correction_on_a_correction_document_is_refused(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        [$invoice, $line] = $this->makeIssuedInvoiceWithLine('300.00', $actor);

        $creditInput = new CorrectionLineInput(
            originalDocumentLineId: $line->getId(),
            reasonCode: CorrectionReasonCode::WRONG_QUANTITY,
            description: 'Correction',
            quantity: '1',
            unitAmount: '100.00',
        );
        $creditNote = $this->correctionService->createCreditNote($invoice, [$creditInput], null, $actor);
        $this->created['invoices'][] = $creditNote->getId();
        $creditNote = $this->issueCorrection($creditNote, $actor);

        $subInput = new CorrectionLineInput(
            originalDocumentLineId: null,
            reasonCode: CorrectionReasonCode::OTHER,
            description: 'Correction de correction',
            quantity: '1',
            unitAmount: '10.00',
            comment: 'jamais autorisé',
            missionId: $line->getMission()->getId(),
        );

        $this->expectException(CorrectionNotEligibleException::class);
        $this->correctionService->createCreditNote($creditNote, [$subInput], null, $actor);
    }

    // ── Notes de crédit (§9/§22) ──────────────────────────────────────────

    public function test_credit_note_correcting_an_existing_line_preserves_the_original_line(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        [$invoice, $line] = $this->makeIssuedInvoiceWithLine('300.00', $actor);
        $this->em->refresh($line);
        $originalQuantity = $line->getQuantity();
        $originalTotal = $line->getTotalAmount();
        $originalSnapshot = $line->getDescriptionSnapshot();

        $input = new CorrectionLineInput(
            originalDocumentLineId: $line->getId(),
            reasonCode: CorrectionReasonCode::WRONG_QUANTITY,
            description: 'Quantité corrigée de 3 à 2',
            quantity: '1',
            unitAmount: '100.00',
        );
        $creditNote = $this->correctionService->createCreditNote($invoice, [$input], null, $actor);
        $this->created['invoices'][] = $creditNote->getId();

        self::assertSame(FinancialDocumentType::CREDIT_NOTE, $creditNote->getDocumentType());
        self::assertSame(InvoiceStatus::GENERATED, $creditNote->getStatus());
        self::assertSame('100.00', $creditNote->getTotalAmount());
        self::assertNull($creditNote->getNumber(), 'aucun numéro attribué à un brouillon (§19)');
        self::assertSame($invoice->getId(), $creditNote->getCorrectsDocument()->getId());

        $this->em->refresh($line);
        self::assertSame($originalQuantity, $line->getQuantity(), 'la ligne d\'origine ne doit jamais être modifiée');
        self::assertSame($originalTotal, $line->getTotalAmount());
        self::assertSame($originalSnapshot, $line->getDescriptionSnapshot());

        $creditLine = $creditNote->getLines()->first();
        self::assertSame($line->getId(), $creditLine->getOriginalDocumentLine()->getId());
        self::assertSame(CorrectionReasonCode::WRONG_QUANTITY, $creditLine->getReasonCode());
    }

    public function test_credit_note_only_affects_net_amount_once_issued(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        [$invoice, $line] = $this->makeIssuedInvoiceWithLine('300.00', $actor);

        $before = $this->paymentService->computeBalance($invoice);
        self::assertSame('300.00', $before->netDocumentAmount);

        $input = new CorrectionLineInput(
            originalDocumentLineId: $line->getId(),
            reasonCode: CorrectionReasonCode::WRONG_QUANTITY,
            description: 'Quantité corrigée',
            quantity: '1',
            unitAmount: '100.00',
        );
        $creditNote = $this->correctionService->createCreditNote($invoice, [$input], null, $actor);
        $this->created['invoices'][] = $creditNote->getId();

        $stillGenerated = $this->paymentService->computeBalance($invoice);
        self::assertSame('300.00', $stillGenerated->netDocumentAmount, 'une correction GENERATED ne doit jamais influencer le solde (§17)');

        $this->issueCorrection($creditNote, $actor);

        $afterIssue = $this->paymentService->computeBalance($invoice);
        self::assertSame('200.00', $afterIssue->netDocumentAmount);
        self::assertSame('100.00', $afterIssue->creditNotesAmount);
        self::assertNotNull($this->em->find(FirmInvoice::class, $creditNote->getId())->getNumber(), 'un numéro définitif est attribué à l\'émission (§19)');
    }

    public function test_cumulative_credit_notes_exceeding_original_line_amount_are_rejected(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        [$invoice, $line] = $this->makeIssuedInvoiceWithLine('300.00', $actor);

        $first = new CorrectionLineInput($line->getId(), CorrectionReasonCode::WRONG_QUANTITY, 'Première correction', '1', '200.00');
        $creditNote1 = $this->correctionService->createCreditNote($invoice, [$first], null, $actor);
        $this->created['invoices'][] = $creditNote1->getId();

        $second = new CorrectionLineInput($line->getId(), CorrectionReasonCode::WRONG_QUANTITY, 'Deuxième correction', '1', '150.00');

        try {
            $this->correctionService->createCreditNote($invoice, [$second], null, $actor);
            self::fail('Devait lever CorrectionValidationException (dépassement cumulé de la ligne).');
        } catch (CorrectionValidationException $e) {
            $codes = array_map(static fn ($a) => $a->code, $e->getAnomalies());
            self::assertContains('CREDIT_EXCEEDS_ORIGINAL_LINE', $codes);
        }

        self::assertCount(1, $this->em->getRepository(FirmInvoice::class)->findBy(['correctsDocument' => $invoice]), 'aucune deuxième note de crédit partiellement créée');
    }

    public function test_credit_note_that_would_make_net_amount_negative_is_rejected(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        [$invoice, $line] = $this->makeIssuedInvoiceWithLine('100.00', $actor);

        $input = new CorrectionLineInput(null, CorrectionReasonCode::COMMERCIAL_ADJUSTMENT, 'Geste commercial excessif', '1', '150.00', missionId: $line->getMission()->getId());

        try {
            $this->correctionService->createCreditNote($invoice, [$input], null, $actor);
            self::fail('Devait lever CorrectionValidationException (net négatif).');
        } catch (CorrectionValidationException $e) {
            $codes = array_map(static fn ($a) => $a->code, $e->getAnomalies());
            self::assertContains('NET_AMOUNT_NEGATIVE', $codes);
        }
    }

    public function test_credit_note_reason_other_requires_a_comment(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        [$invoice, $line] = $this->makeIssuedInvoiceWithLine('300.00', $actor);

        $input = new CorrectionLineInput($line->getId(), CorrectionReasonCode::OTHER, 'Motif libre', '1', '50.00');

        try {
            $this->correctionService->createCreditNote($invoice, [$input], null, $actor);
            self::fail('Devait lever CorrectionValidationException (OTHER sans commentaire).');
        } catch (CorrectionValidationException $e) {
            $codes = array_map(static fn ($a) => $a->code, $e->getAnomalies());
            self::assertContains('MISSING_OTHER_COMMENT', $codes);
        }
    }

    // ── Notes de débit (§10/§23) ──────────────────────────────────────────

    public function test_debit_note_for_an_omitted_line_creates_a_new_due_balance(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        [$invoice, $line, $mission] = $this->makeIssuedInvoiceWithLine('500.00', $actor);

        $payment = $this->paymentService->recordPayment($invoice, '500.00', 'EUR', new \DateTimeImmutable('2026-06-20'), PaymentMethod::BANK_TRANSFER, null, null, $actor);
        $this->created['payments'][] = $payment->getId();
        self::assertSame(PaymentStatus::PAID, $this->paymentService->computeBalance($invoice)->status);

        $input = new CorrectionLineInput(null, CorrectionReasonCode::OMITTED_LINE, 'Prestation oubliée', '1', '75.00', missionId: $mission->getId());
        $debitNote = $this->correctionService->createDebitNote($invoice, [$input], null, $actor);
        $this->created['invoices'][] = $debitNote->getId();
        $this->issueCorrection($debitNote, $actor);

        $balance = $this->paymentService->computeBalance($invoice);
        self::assertSame('575.00', $balance->netDocumentAmount);
        self::assertSame('75.00', $balance->debitNotesAmount);
        self::assertSame('75.00', $balance->remainingAmount);
        self::assertSame(PaymentStatus::PARTIALLY_PAID, $balance->status);

        // Le paiement original n'est jamais modifié (§13/§34).
        $this->em->refresh($payment);
        self::assertSame('500.00', $payment->getAmount());
    }

    public function test_debit_note_allows_a_complementary_payment(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        [$invoice, $line, $mission] = $this->makeIssuedInvoiceWithLine('500.00', $actor);
        $p1 = $this->paymentService->recordPayment($invoice, '500.00', 'EUR', new \DateTimeImmutable('2026-06-20'), PaymentMethod::BANK_TRANSFER, null, null, $actor);
        $this->created['payments'][] = $p1->getId();

        $input = new CorrectionLineInput(null, CorrectionReasonCode::OMITTED_LINE, 'Prestation oubliée', '1', '75.00', missionId: $mission->getId());
        $debitNote = $this->correctionService->createDebitNote($invoice, [$input], null, $actor);
        $this->created['invoices'][] = $debitNote->getId();
        $this->issueCorrection($debitNote, $actor);

        $p2 = $this->paymentService->recordPayment($invoice, '75.00', 'EUR', new \DateTimeImmutable('2026-06-25'), PaymentMethod::CASH, null, 'complément débit', $actor);
        $this->created['payments'][] = $p2->getId();

        $balance = $this->paymentService->computeBalance($invoice);
        self::assertSame('0.00', $balance->remainingAmount);
        self::assertSame(PaymentStatus::PAID, $balance->status);
    }

    public function test_debit_note_referencing_an_existing_line_is_traceable(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        [$invoice, $line] = $this->makeIssuedInvoiceWithLine('300.00', $actor);

        $input = new CorrectionLineInput($line->getId(), CorrectionReasonCode::WRONG_RATE, 'Tarif corrigé à la hausse', '1', '20.00');
        $debitNote = $this->correctionService->createDebitNote($invoice, [$input], null, $actor);
        $this->created['invoices'][] = $debitNote->getId();

        $debitLine = $debitNote->getLines()->first();
        self::assertSame($line->getId(), $debitLine->getOriginalDocumentLine()->getId());
        self::assertSame(CorrectionReasonCode::WRONG_RATE, $debitLine->getReasonCode());
        self::assertSame('20.00', $debitNote->getTotalAmount());
    }

    // ── Décompte instrumentiste — même service ────────────────────────────

    public function test_credit_note_on_instrumentist_statement_shares_the_same_service(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        [$statement, $line] = $this->makeIssuedStatementWithLine('60.00', $actor);

        $input = new CorrectionLineInput($line->getId(), CorrectionReasonCode::WRONG_DURATION, 'Durée corrigée', '1', '30.00');
        $creditNote = $this->correctionService->createCreditNote($statement, [$input], null, $actor);
        $this->created['statements'][] = $creditNote->getId();
        $this->issueCorrection($creditNote, $actor);

        $balance = $this->paymentService->computeBalance($statement);
        self::assertSame('30.00', $balance->creditNotesAmount);
        self::assertNotNull($creditNote->getNumber());
        self::assertStringContainsString('STMT-CN', $creditNote->getNumber());
    }

    // ── Remboursements (§14/§15/§16) ──────────────────────────────────────

    public function test_refund_after_credit_note_never_exceeds_the_real_overpayment(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        [$invoice, $line] = $this->makeIssuedInvoiceWithLine('800.00', $actor);

        $payment = $this->paymentService->recordPayment($invoice, '800.00', 'EUR', new \DateTimeImmutable('2026-06-20'), PaymentMethod::BANK_TRANSFER, null, null, $actor);
        $this->created['payments'][] = $payment->getId();

        $input = new CorrectionLineInput($line->getId(), CorrectionReasonCode::WRONG_QUANTITY, 'Correction', '1', '200.00');
        $creditNote = $this->correctionService->createCreditNote($invoice, [$input], null, $actor);
        $this->created['invoices'][] = $creditNote->getId();
        $this->issueCorrection($creditNote, $actor);

        $balance = $this->paymentService->computeBalance($invoice);
        self::assertSame('600.00', $balance->netDocumentAmount);
        self::assertSame('200.00', $balance->overpaidAmount);

        $this->expectException(RefundExceedsOverpaidException::class);
        $this->correctionService->recordRefund($invoice, '200.01', 'EUR', new \DateTimeImmutable('2026-06-25'), PaymentMethod::BANK_TRANSFER, null, null, $actor);
    }

    public function test_partial_then_full_refund_up_to_the_overpayment_succeeds(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        [$invoice, $line] = $this->makeIssuedInvoiceWithLine('800.00', $actor);
        $payment = $this->paymentService->recordPayment($invoice, '800.00', 'EUR', new \DateTimeImmutable('2026-06-20'), PaymentMethod::BANK_TRANSFER, null, null, $actor);
        $this->created['payments'][] = $payment->getId();

        $input = new CorrectionLineInput($line->getId(), CorrectionReasonCode::WRONG_QUANTITY, 'Correction', '1', '200.00');
        $creditNote = $this->correctionService->createCreditNote($invoice, [$input], null, $actor);
        $this->created['invoices'][] = $creditNote->getId();
        $this->issueCorrection($creditNote, $actor);

        $refund1 = $this->correctionService->recordRefund($invoice, '120.00', 'EUR', new \DateTimeImmutable('2026-06-25'), PaymentMethod::BANK_TRANSFER, null, null, $actor);
        $this->created['payments'][] = $refund1->getId();
        self::assertSame('80.00', $this->paymentService->computeBalance($invoice)->overpaidAmount);

        $refund2 = $this->correctionService->recordRefund($invoice, '80.00', 'EUR', new \DateTimeImmutable('2026-06-26'), PaymentMethod::BANK_TRANSFER, null, null, $actor);
        $this->created['payments'][] = $refund2->getId();
        self::assertSame('0.00', $this->paymentService->computeBalance($invoice)->overpaidAmount);

        self::assertCount(3, $this->paymentService->getPaymentsFor($invoice), '1 paiement + 2 remboursements, tous append-only');
        // Le paiement original n'est jamais modifié.
        $this->em->refresh($payment);
        self::assertSame('800.00', $payment->getAmount());
    }

    // ── Legacy (§24) ──────────────────────────────────────────────────────

    public function test_correction_on_a_legacy_document_is_allowed_without_reconstructing_financial_lines(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        [$invoice, $line, , , $actor] = $this->makeIssuedLegacyInvoiceWithLine('75.00', $actor);
        self::assertTrue($invoice->isLegacySource());

        $input = new CorrectionLineInput($line->getId(), CorrectionReasonCode::WRONG_RATE, 'Correction sur document legacy', '1', '15.00');
        $creditNote = $this->correctionService->createCreditNote($invoice, [$input], null, $actor);
        $this->created['invoices'][] = $creditNote->getId();

        self::assertSame('15.00', $creditNote->getTotalAmount());
        $creditLine = $creditNote->getLines()->first();
        self::assertNull($creditLine->getFinancialCalculationLine(), 'aucune reconstruction de FinancialCalculationLine sur un document legacy (§24)');
        self::assertSame($line->getId(), $creditLine->getOriginalDocumentLine()->getId());

        // La facture legacy d'origine reste inchangée.
        $this->em->refresh($invoice);
        self::assertTrue($invoice->isLegacySource());
        self::assertSame('75.00', $invoice->getTotalAmount());
    }

    // ── Audit (§28) ────────────────────────────────────────────────────────

    public function test_credit_note_debit_note_issue_and_refund_are_all_audited(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        [$invoice, $line] = $this->makeIssuedInvoiceWithLine('800.00', $actor);
        $payment = $this->paymentService->recordPayment($invoice, '800.00', 'EUR', new \DateTimeImmutable('2026-06-20'), PaymentMethod::BANK_TRANSFER, null, null, $actor);
        $this->created['payments'][] = $payment->getId();

        $creditInput = new CorrectionLineInput($line->getId(), CorrectionReasonCode::WRONG_QUANTITY, 'Correction', '1', '100.00');
        $creditNote = $this->correctionService->createCreditNote($invoice, [$creditInput], 'ajustement', $actor);
        $this->created['invoices'][] = $creditNote->getId();
        $this->issueCorrection($creditNote, $actor);

        $debitInput = new CorrectionLineInput(null, CorrectionReasonCode::OMITTED_LINE, 'Oubli', '1', '50.00', missionId: $line->getMission()->getId());
        $debitNote = $this->correctionService->createDebitNote($invoice, [$debitInput], null, $actor);
        $this->created['invoices'][] = $debitNote->getId();
        $this->issueCorrection($debitNote, $actor);

        $refund = $this->correctionService->recordRefund($invoice, '50.00', 'EUR', new \DateTimeImmutable('2026-06-25'), PaymentMethod::BANK_TRANSFER, null, null, $actor);
        $this->created['payments'][] = $refund->getId();

        $events = $this->em->getRepository(AuditEvent::class)->findBy(['actor' => $actor], ['id' => 'ASC']);
        $types = array_map(static fn (AuditEvent $e) => $e->getEventType()->value, $events);

        self::assertContains(AuditEventType::CREDIT_NOTE_CREATED->value, $types);
        self::assertContains(AuditEventType::DEBIT_NOTE_CREATED->value, $types);
        self::assertContains(AuditEventType::FINANCIAL_CORRECTION_ISSUED->value, $types);
        self::assertContains(AuditEventType::DOCUMENT_NET_BALANCE_CHANGED->value, $types);
        self::assertContains(AuditEventType::REFUND_RECORDED->value, $types);
    }

    // ── Atomicité (§30) ───────────────────────────────────────────────────

    public function test_invalid_correction_line_creates_no_partial_document(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        [$invoice, $line] = $this->makeIssuedInvoiceWithLine('300.00', $actor);

        $valid = new CorrectionLineInput($line->getId(), CorrectionReasonCode::WRONG_QUANTITY, 'Valide', '1', '50.00');
        $invalid = new CorrectionLineInput(null, CorrectionReasonCode::OTHER, 'Sans commentaire', '1', '10.00'); // OTHER sans comment => anomalie

        try {
            $this->correctionService->createCreditNote($invoice, [$valid, $invalid], null, $actor);
            self::fail('Devait lever CorrectionValidationException.');
        } catch (CorrectionValidationException) {
        }

        self::assertCount(0, $this->em->getRepository(FirmInvoice::class)->findBy(['correctsDocument' => $invoice]), 'aucun document correctif partiel');
        self::assertSame('300.00', $this->paymentService->computeBalance($invoice)->netDocumentAmount, 'le solde ne doit pas bouger');
    }
}
