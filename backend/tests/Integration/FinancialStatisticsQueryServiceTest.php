<?php

namespace App\Tests\Integration;

use App\Dto\CorrectionLineInput;
use App\Dto\FinancialStatisticsFilter;
use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\FinancialCalculation;
use App\Entity\FirmInvoice;
use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\InstrumentistStatement;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
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
use App\Enum\StatisticsGranularity;
use App\Service\FinancialCalculationService;
use App\Service\FinancialCorrectionService;
use App\Service\DocumentPaymentService;
use App\Service\FinancialStatisticsQueryService;
use App\Service\FirmInvoiceService;
use App\Service\InstrumentistStatementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — §31 du lot : overview (aucune donnée, une
 * devise, plusieurs devises, calculs superseded/cancelled exclus), périodes (bornes
 * inclusive/exclusive), corrections (générée exclue, émise déduite/ajoutée),
 * paiements (entrant/sortant/legacy), pipeline. Appels réels contre une base réelle.
 */
final class FinancialStatisticsQueryServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FinancialCalculationService $calcService;
    private FirmInvoiceService $invoiceService;
    private InstrumentistStatementService $statementService;
    private DocumentPaymentService $paymentService;
    private FinancialCorrectionService $correctionService;
    private FinancialStatisticsQueryService $stats;
    private array $created = [
        'payments' => [], 'invoices' => [], 'statements' => [], 'calculations' => [],
        'interventions' => [], 'materialLines' => [], 'missions' => [],
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
        $this->correctionService = self::getContainer()->get(FinancialCorrectionService::class);
        $this->stats = self::getContainer()->get(FinancialStatisticsQueryService::class);
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

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('fsqs-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setPassword('x');
        $this->em->persist($u); $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    private function makeFirm(): Firm
    {
        $f = new Firm();
        $f->setName('FSQS-' . bin2hex(random_bytes(4)));
        $this->em->persist($f); $this->em->flush();
        $this->created['firms'][] = $f->getId();
        return $f;
    }

    /**
     * Construit une mission VALIDATED avec exécution réelle + une intervention
     * (firm, quantité 1) + une ligne matériel + un instrumentiste horaire, puis calcule
     * et APPROVE le FinancialCalculation résultant.
     *
     * @return array{0: Mission, 1: FinancialCalculation, 2: User (instrumentist), 3: User (surgeon), 4: Firm, 5: User (actor)}
     */
    private function makeApprovedMission(
        Firm $firm,
        string $interventionPrice,
        string $materialPrice,
        string $hourlyRate,
        \DateTimeImmutable $missionDate,
        int $durationMinutes = 90,
        string $currency = 'EUR',
        ?Hospital $site = null,
        ?User $surgeon = null,
        ?User $instrumentist = null,
    ): array {
        $type = new InterventionType();
        $type->setCode('FSQS-' . bin2hex(random_bytes(3)));
        $type->setLabel('FSQS Type');
        $this->em->persist($type); $this->em->flush();
        $this->created['types'][] = $type->getId();

        $item = new MaterialItem();
        $item->setFirm($firm);
        $item->setLabel('FSQS Item');
        $item->setUnit('pièce');
        $item->setReferenceCode('REF-' . bin2hex(random_bytes(4)));
        $this->em->persist($item); $this->em->flush();
        $this->created['items'][] = $item->getId();

        $interventionRule = new PricingRule();
        $interventionRule->setFirm($firm);
        $interventionRule->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $interventionRule->setInterventionType($type);
        $interventionRule->setUnitPrice($interventionPrice);
        $interventionRule->setCurrency($currency);
        $this->em->persist($interventionRule); $this->em->flush();
        $this->created['rules'][] = $interventionRule->getId();

        $materialRule = new PricingRule();
        $materialRule->setFirm($firm);
        $materialRule->setRuleType(PricingRuleType::MATERIAL_FEE);
        $materialRule->setMaterialItem($item);
        $materialRule->setUnitPrice($materialPrice);
        $materialRule->setCurrency($currency);
        $this->em->persist($materialRule); $this->em->flush();
        $this->created['rules'][] = $materialRule->getId();

        $instrumentist ??= $this->makeUser('ROLE_INSTRUMENTIST');
        $rate = new InstrumentistRate();
        $rate->setInstrumentist($instrumentist);
        $rate->setRateType(InstrumentistRateType::HOURLY_RATE);
        $rate->setAmount($hourlyRate);
        $rate->setCurrency($currency);
        $rate->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($rate); $this->em->flush();
        $this->created['rates'][] = $rate->getId();

        $site ??= $this->makeSite();
        $surgeon ??= $this->makeUser('ROLE_SURGEON');

        $mission = new Mission();
        $mission->setType(MissionType::BLOCK);
        $mission->setSite($site);
        $mission->setSurgeon($surgeon);
        $mission->setCreatedBy($surgeon);
        $mission->setStartAt($missionDate);
        $mission->setEndAt($missionDate->modify("+{$durationMinutes} minutes"));
        $mission->setStatus(MissionStatus::VALIDATED);
        $mission->setInstrumentist($instrumentist);
        $this->em->persist($mission); $this->em->flush();
        $this->created['missions'][] = $mission->getId();

        $intervention = new MissionIntervention();
        $intervention->setMission($mission);
        $intervention->setCode($type->getCode());
        $intervention->setLabel('FSQS Intervention');
        $intervention->setInterventionType($type);
        $intervention->setPrimaryFirm($firm);
        $this->em->persist($intervention); $this->em->flush();
        $this->created['interventions'][] = $intervention->getId();
        $mission->getInterventions()->add($intervention);

        $materialLine = new MaterialLine();
        $materialLine->setMission($mission);
        $materialLine->setMissionIntervention($intervention);
        $materialLine->setItem($item);
        $materialLine->setQuantity('1.00');
        $materialLine->setCreatedBy($surgeon);
        $this->em->persist($materialLine); $this->em->flush();
        $this->created['materialLines'][] = $materialLine->getId();
        $mission->getMaterialLines()->add($materialLine);

        $actor = $this->makeUser('ROLE_MANAGER');
        $calc = $this->calcService->calculate($mission, $actor);
        $this->created['calculations'][] = $calc->getId();

        // Exécution réelle (§4 du lot — date de rattachement = actualStartAt).
        $execService = self::getContainer()->get(\App\Service\MissionExecutionService::class);
        $execService->updateActuals($mission, $actor, $missionDate, $missionDate->modify("+{$durationMinutes} minutes"), null, \App\Enum\HoursSource::MANAGER);

        $calc = $this->calcService->approve($calc, $actor);

        return [$mission, $calc, $instrumentist, $surgeon, $firm, $actor];
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('FSQS-Site-' . bin2hex(random_bytes(3)));
        $this->em->persist($h); $this->em->flush();
        $this->created['sites'][] = $h->getId();
        return $h;
    }

    /**
     * §6 du lot — `surgeonId`/`firmId` optionnels : la base de dev est une copie de
     * production (190+ missions réelles, voir mémoire projet) — toute assertion sur un
     * COMPTE EXACT doit se restreindre au chirurgien/à la firme unique généré(e) par la
     * fixture du test, jamais un décompte non scopé qui capterait aussi les données
     * réelles préexistantes.
     */
    private function filter(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $currency = null, ?int $surgeonId = null, ?int $firmId = null): FinancialStatisticsFilter
    {
        return new FinancialStatisticsFilter(from: $from, to: $to, surgeonId: $surgeonId, firmId: $firmId, currency: $currency);
    }

    /**
     * Pour les assertions dont la date de rattachement est horodatée serveur
     * ("maintenant" — FirmInvoice.sentAt/createdAt via issue()/createFromEligibleLines(),
     * jamais la date de la mission simulée) : fenêtre large couvrant "aujourd'hui" sans
     * dépendre de la date système exacte du run. `firmId` obligatoire en pratique — voir
     * docblock de `filter()`.
     */
    private function wideFilter(?int $firmId = null): FinancialStatisticsFilter
    {
        return new FinancialStatisticsFilter(from: new \DateTimeImmutable('2020-01-01'), to: new \DateTimeImmutable('2030-01-01'), firmId: $firmId);
    }

    // ── Overview — aucune donnée / une devise / plusieurs devises ────────

    public function test_overview_with_no_data_returns_zeroed_activity_and_no_currencies(): void
    {
        $overview = $this->stats->overview($this->filter(new \DateTimeImmutable('2019-01-01'), new \DateTimeImmutable('2019-02-01')));

        self::assertSame(0, $overview->activity->missionCount);
        self::assertSame(0, $overview->activity->executedMissionCount);
        self::assertSame(0, $overview->activity->validatedMissionCount);
        self::assertSame([], $overview->currencies);
    }

    public function test_overview_single_currency_computes_generated_and_average(): void
    {
        $firm = $this->makeFirm();
        $today = new \DateTimeImmutable('2026-05-15 09:00:00');
        [$mission] = $this->makeApprovedMission($firm, '200.00', '50.00', '40.00', $today, 60);

        $overview = $this->stats->overview($this->filter(new \DateTimeImmutable('2026-05-01'), new \DateTimeImmutable('2026-06-01'), firmId: $firm->getId()));

        self::assertSame(1, $overview->activity->missionCount);
        self::assertSame(1, $overview->activity->executedMissionCount);
        self::assertCount(1, $overview->currencies);
        $eur = $overview->currencies[0];
        self::assertSame('EUR', $eur->currency);
        // intervention 200 + material 50 = 250 ; instrumentist 1h * 40 = 40.
        self::assertSame('250.00', $eur->generatedFirmRevenue);
        self::assertSame('40.00', $eur->generatedInstrumentistCompensation);
        self::assertSame('290.00', $eur->generatedTotalValue);
        self::assertSame('210.00', $eur->generatedContributionMargin);
        self::assertSame('290.00', $eur->averageMissionValue);
    }

    public function test_overview_multiple_currencies_never_produces_an_artificial_total(): void
    {
        $firmEur = $this->makeFirm();
        $firmUsd = $this->makeFirm();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $today = new \DateTimeImmutable('2026-05-15 09:00:00');
        $this->makeApprovedMission($firmEur, '100.00', '0.00', '40.00', $today, 60, 'EUR', surgeon: $surgeon);
        $this->makeApprovedMission($firmUsd, '300.00', '0.00', '50.00', $today->modify('+1 hour'), 60, 'USD', surgeon: $surgeon);

        $overview = $this->stats->overview($this->filter(new \DateTimeImmutable('2026-05-01'), new \DateTimeImmutable('2026-06-01'), surgeonId: $surgeon->getId()));

        self::assertCount(2, $overview->currencies);
        $byCurrency = [];
        foreach ($overview->currencies as $c) { $byCurrency[$c->currency] = $c; }
        self::assertArrayHasKey('EUR', $byCurrency);
        self::assertArrayHasKey('USD', $byCurrency);
        self::assertSame('100.00', $byCurrency['EUR']->generatedFirmRevenue);
        self::assertSame('300.00', $byCurrency['USD']->generatedFirmRevenue);
        // missionCount reste global (non dupliqué par devise) — voir FinancialOverviewActivityDto.
        self::assertSame(2, $overview->activity->missionCount);
    }

    public function test_overview_averages_multiple_missions_correctly(): void
    {
        $firm = $this->makeFirm();
        $today = new \DateTimeImmutable('2026-05-10 09:00:00');
        $this->makeApprovedMission($firm, '100.00', '0.00', '0.00', $today, 60);
        $this->makeApprovedMission($firm, '300.00', '0.00', '0.00', $today->modify('+1 day'), 60);

        $overview = $this->stats->overview($this->filter(new \DateTimeImmutable('2026-05-01'), new \DateTimeImmutable('2026-06-01'), firmId: $firm->getId()));

        self::assertSame(2, $overview->activity->missionCount);
        $eur = $overview->currencies[0];
        self::assertSame('400.00', $eur->generatedFirmRevenue);
        self::assertSame('200.00', $eur->averageMissionValue);
    }

    // ── Calculs SUPERSEDED/CANCELLED exclus (§8) ─────────────────────────

    public function test_superseded_calculation_is_excluded_from_generated_value(): void
    {
        $firm = $this->makeFirm();
        $today = new \DateTimeImmutable('2026-05-12 09:00:00');
        [$mission, $calc, , , , $actor] = $this->makeApprovedMission($firm, '100.00', '0.00', '0.00', $today, 60);

        // approve() verrouille le statut à APPROVED — recalculate() nécessite CALCULATED
        // ou APPROVED (non LOCKED) : APPROVED convient, le nouveau calcul remplace l'ancien.
        $new = $this->calcService->recalculate($mission, $actor);
        $this->created['calculations'][] = $new->getId();

        $overview = $this->stats->overview($this->filter(new \DateTimeImmutable('2026-05-01'), new \DateTimeImmutable('2026-06-01'), firmId: $firm->getId()));

        // Le nouveau calcul (CALCULATED) reste actif — une seule version compte, jamais les deux sommées.
        self::assertCount(1, $overview->currencies);
        self::assertSame('100.00', $overview->currencies[0]->generatedFirmRevenue);
    }

    public function test_cancelled_calculation_is_excluded_from_generated_value(): void
    {
        $firm = $this->makeFirm();
        $today = new \DateTimeImmutable('2026-05-13 09:00:00');
        [, $calc, , , , $actor] = $this->makeApprovedMission($firm, '150.00', '0.00', '0.00', $today, 60);

        // approve() a déjà transitionné vers APPROVED — cancel() accepte CALCULATED/APPROVED (non LOCKED).
        $this->calcService->cancel($calc, $actor, 'test');

        $overview = $this->stats->overview($this->filter(new \DateTimeImmutable('2026-05-01'), new \DateTimeImmutable('2026-06-01'), firmId: $firm->getId()));

        self::assertSame([], $overview->currencies);
        self::assertSame(1, $overview->activity->missionCount, 'l\'activité (Mission) reste comptée même si le calcul est annulé — sources distinctes (§2 du lot).');
    }

    // ── Distinction firme/instrumentiste (jamais mélangés) ───────────────

    public function test_firm_revenue_and_instrumentist_compensation_are_never_mixed(): void
    {
        $firm = $this->makeFirm();
        $today = new \DateTimeImmutable('2026-05-14 09:00:00');
        $this->makeApprovedMission($firm, '500.00', '0.00', '20.00', $today, 120);

        $overview = $this->stats->overview($this->filter(new \DateTimeImmutable('2026-05-01'), new \DateTimeImmutable('2026-06-01'), firmId: $firm->getId()));

        $eur = $overview->currencies[0];
        self::assertSame('500.00', $eur->generatedFirmRevenue);
        self::assertSame('40.00', $eur->generatedInstrumentistCompensation); // 2h * 20.00
        self::assertNotSame($eur->generatedFirmRevenue, $eur->generatedInstrumentistCompensation);
    }

    // ── Périodes — bornes inclusive/exclusive (§3) ───────────────────────

    public function test_from_bound_is_inclusive_and_to_bound_is_exclusive(): void
    {
        $firm = $this->makeFirm();
        $boundaryDate = new \DateTimeImmutable('2026-07-01 00:00:00', new \DateTimeZone('Europe/Brussels'));
        $this->makeApprovedMission($firm, '100.00', '0.00', '0.00', $boundaryDate, 30);

        // from inclusif : la mission au 01/07 00:00:00 pile doit être comptée.
        $inclusive = $this->stats->overview($this->filter($boundaryDate, $boundaryDate->modify('+1 day'), firmId: $firm->getId()));
        self::assertSame(1, $inclusive->activity->missionCount);

        // to exclusif : une période finissant exactement à la date de la mission doit l'exclure.
        $exclusive = $this->stats->overview($this->filter($boundaryDate->modify('-1 day'), $boundaryDate, firmId: $firm->getId()));
        self::assertSame(0, $exclusive->activity->missionCount);
    }

    public function test_period_crossing_month_boundary_includes_missions_from_both_months(): void
    {
        $firm = $this->makeFirm();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $this->makeApprovedMission($firm, '100.00', '0.00', '0.00', new \DateTimeImmutable('2026-05-31 10:00:00'), 30, surgeon: $surgeon);
        $this->makeApprovedMission($firm, '200.00', '0.00', '0.00', new \DateTimeImmutable('2026-06-01 10:00:00'), 30, surgeon: $surgeon);

        $overview = $this->stats->overview($this->filter(new \DateTimeImmutable('2026-05-25'), new \DateTimeImmutable('2026-06-05'), surgeonId: $surgeon->getId()));
        self::assertSame(2, $overview->activity->missionCount);
    }

    // ── Corrections (§19) ─────────────────────────────────────────────────

    public function test_generated_credit_note_does_not_affect_invoiced_net_amount(): void
    {
        [$invoice, $line, $actor, $firm] = $this->makeIssuedInvoice('2026-05-01', '300.00');

        $input = new CorrectionLineInput($line->getId(), CorrectionReasonCode::WRONG_QUANTITY, 'Correction', '1', '100.00');
        $creditNote = $this->correctionService->createCreditNote($invoice, [$input], null, $actor);
        $this->created['invoices'][] = $creditNote->getId();
        // Jamais émise (reste GENERATED).

        $overview = $this->stats->overview($this->wideFilter($firm->getId()));
        self::assertSame('300.00', $overview->currencies[0]->invoicedNetAmount, 'une correction GENERATED ne doit jamais influencer le montant net documenté (§19).');
    }

    public function test_issued_credit_note_reduces_invoiced_net_amount(): void
    {
        [$invoice, $line, $actor, $firm] = $this->makeIssuedInvoice('2026-05-02', '300.00');

        $input = new CorrectionLineInput($line->getId(), CorrectionReasonCode::WRONG_QUANTITY, 'Correction', '1', '100.00');
        $creditNote = $this->correctionService->createCreditNote($invoice, [$input], null, $actor);
        $this->created['invoices'][] = $creditNote->getId();
        $this->correctionService->issueCorrection($creditNote, $actor);

        $overview = $this->stats->overview($this->wideFilter($firm->getId()));
        self::assertSame('200.00', $overview->currencies[0]->invoicedNetAmount);
        self::assertSame('100.00', $overview->currencies[0]->invoiceCreditNotesAmount);

        // Le document racine n'est jamais modifié.
        $this->em->refresh($invoice);
        self::assertSame('300.00', $invoice->getTotalAmount());
    }

    public function test_issued_debit_note_increases_invoiced_net_amount(): void
    {
        [$invoice, $line, $actor, $firm] = $this->makeIssuedInvoice('2026-05-03', '300.00');

        $input = new CorrectionLineInput(null, CorrectionReasonCode::OMITTED_LINE, 'Oubli', '1', '50.00', missionId: $line->getMission()->getId());
        $debitNote = $this->correctionService->createDebitNote($invoice, [$input], null, $actor);
        $this->created['invoices'][] = $debitNote->getId();
        $this->correctionService->issueCorrection($debitNote, $actor);

        $overview = $this->stats->overview($this->wideFilter($firm->getId()));
        self::assertSame('350.00', $overview->currencies[0]->invoicedNetAmount);
        self::assertSame('50.00', $overview->currencies[0]->invoiceDebitNotesAmount);
    }

    // ── Paiements (§20) ───────────────────────────────────────────────────

    public function test_firm_invoice_inbound_payment_counts_as_cash_in(): void
    {
        [$invoice, , $actor, $firm] = $this->makeIssuedInvoice('2026-05-04', '300.00');
        $payment = $this->paymentService->recordPayment($invoice, '300.00', 'EUR', new \DateTimeImmutable('2026-05-10'), PaymentMethod::BANK_TRANSFER, null, null, $actor);
        $this->created['payments'][] = $payment->getId();

        $overview = $this->stats->overview($this->wideFilter($firm->getId()));
        self::assertSame('300.00', $overview->currencies[0]->paymentsIn);
        self::assertSame('0.00', $overview->currencies[0]->paymentsOut);
        self::assertSame('300.00', $overview->currencies[0]->netCashFlow);
    }

    public function test_instrumentist_statement_inbound_payment_counts_as_cash_out(): void
    {
        [$statement, , $actor, $instrumentist] = $this->makeIssuedStatement('2026-05-05', '200.00');
        $payment = $this->paymentService->recordPayment($statement, '200.00', 'EUR', new \DateTimeImmutable('2026-05-11'), PaymentMethod::BANK_TRANSFER, null, null, $actor);
        $this->created['payments'][] = $payment->getId();

        $overview = $this->stats->overview(new FinancialStatisticsFilter(from: new \DateTimeImmutable('2020-01-01'), to: new \DateTimeImmutable('2030-01-01'), instrumentistId: $instrumentist->getId()));
        // §20 : direction=INBOUND sur un décompte instrumentiste est un décaissement RÉEL,
        // jamais un encaissement — c'est l'inversion documentée par D-077.
        self::assertSame('0.00', $overview->currencies[0]->paymentsIn);
        self::assertSame('200.00', $overview->currencies[0]->paymentsOut);
        self::assertSame('-200.00', $overview->currencies[0]->netCashFlow);
    }

    public function test_refund_on_firm_invoice_counts_as_cash_out(): void
    {
        [$invoice, $line, $actor, $firm] = $this->makeIssuedInvoice('2026-05-06', '300.00');
        $payment = $this->paymentService->recordPayment($invoice, '300.00', 'EUR', new \DateTimeImmutable('2026-05-10'), PaymentMethod::BANK_TRANSFER, null, null, $actor);
        $this->created['payments'][] = $payment->getId();

        $input = new CorrectionLineInput($line->getId(), CorrectionReasonCode::WRONG_QUANTITY, 'Correction', '1', '100.00');
        $creditNote = $this->correctionService->createCreditNote($invoice, [$input], null, $actor);
        $this->created['invoices'][] = $creditNote->getId();
        $this->correctionService->issueCorrection($creditNote, $actor);

        $refund = $this->correctionService->recordRefund($invoice, '100.00', 'EUR', new \DateTimeImmutable('2026-05-12'), PaymentMethod::BANK_TRANSFER, null, null, $actor);
        $this->created['payments'][] = $refund->getId();

        $overview = $this->stats->overview($this->wideFilter($firm->getId()));
        self::assertSame('300.00', $overview->currencies[0]->paymentsIn);
        self::assertSame('100.00', $overview->currencies[0]->paymentsOut);
        self::assertSame('200.00', $overview->currencies[0]->netCashFlow);
        self::assertSame('0.00', $overview->currencies[0]->openFirmBalance);
    }

    public function test_partial_payment_is_reflected_in_open_balance(): void
    {
        [$invoice, , $actor, $firm] = $this->makeIssuedInvoice('2026-05-07', '1000.00');
        $payment = $this->paymentService->recordPayment($invoice, '400.00', 'EUR', new \DateTimeImmutable('2026-05-10'), PaymentMethod::BANK_TRANSFER, null, null, $actor);
        $this->created['payments'][] = $payment->getId();

        $overview = $this->stats->overview($this->wideFilter($firm->getId()));
        self::assertSame('400.00', $overview->currencies[0]->paymentsIn);
        self::assertSame('600.00', $overview->currencies[0]->openFirmBalance);
    }

    public function test_legacy_paid_document_without_payment_rows_is_treated_as_settled(): void
    {
        [$invoice, , , $firm] = $this->makeIssuedInvoice('2026-05-08', '500.00');
        $this->invoiceService->markPaid($invoice); // chemin legacy Lot 1, aucun Payment créé

        $overview = $this->stats->overview($this->wideFilter($firm->getId()));
        self::assertCount(1, $overview->currencies, 'la facture legacy doit bien être détectée (sinon "0.00" serait un simple défaut, pas une preuve).');
        self::assertSame('500.00', $overview->currencies[0]->invoicedNetAmount);
        self::assertSame('0.00', $overview->currencies[0]->openFirmBalance, 'un document PAID legacy sans Payment doit être traité comme intégralement soldé (compatibilité D-075).');
    }

    // ── Pipeline (§17) ────────────────────────────────────────────────────

    public function test_pipeline_counts_validated_mission_without_calculation(): void
    {
        $today = new \DateTimeImmutable('2026-05-20 09:00:00');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
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

        $pipeline = $this->stats->pipeline($this->filter(new \DateTimeImmutable('2026-05-01'), new \DateTimeImmutable('2026-06-01'), surgeonId: $surgeon->getId()));
        self::assertSame(1, $pipeline->validatedMissionsWithoutCalculation);
    }

    public function test_pipeline_counts_approved_calculation_without_documents(): void
    {
        $firm = $this->makeFirm();
        $this->makeApprovedMission($firm, '100.00', '0.00', '0.00', new \DateTimeImmutable('2026-05-21 09:00:00'), 30);

        $pipeline = $this->stats->pipeline($this->filter(new \DateTimeImmutable('2026-05-01'), new \DateTimeImmutable('2026-06-01'), firmId: $firm->getId()));
        self::assertSame(1, $pipeline->approvedCalculationsWithoutDocuments);
        self::assertSame(0, $pipeline->partiallyDocumentedCalculations);
    }

    public function test_pipeline_counts_partially_documented_calculation(): void
    {
        $firm = $this->makeFirm();
        [$mission, $calc, , , , $actor] = $this->makeApprovedMission($firm, '100.00', '50.00', '40.00', new \DateTimeImmutable('2026-05-22 09:00:00'), 60);

        $firmLine = null;
        foreach ($calc->getLines() as $l) {
            if ($l->getLineType()->value === 'FIRM_INTERVENTION_FEE') { $firmLine = $l; }
        }
        $invoice = $this->invoiceService->createFromEligibleLines($firm, 'EUR', new \DateTimeImmutable('2026-05-01'), new \DateTimeImmutable('2026-06-01'), [$firmLine->getId()], $actor);
        $this->created['invoices'][] = $invoice->getId();

        $pipeline = $this->stats->pipeline($this->filter(new \DateTimeImmutable('2026-05-01'), new \DateTimeImmutable('2026-06-01'), firmId: $firm->getId()));
        self::assertSame(1, $pipeline->partiallyDocumentedCalculations);
        self::assertSame(0, $pipeline->approvedCalculationsWithoutDocuments);
    }

    public function test_pipeline_counts_generated_invoice_not_issued(): void
    {
        [$mission, $calc, , , $firm, $actor] = $this->makeApprovedMissionForPipeline();
        $firmLine = $this->firstFirmLine($calc);
        $invoice = $this->invoiceService->createFromEligibleLines($firm, 'EUR', new \DateTimeImmutable('2026-05-01'), new \DateTimeImmutable('2026-06-01'), [$firmLine->getId()], $actor);
        $this->created['invoices'][] = $invoice->getId();

        // §17 : une facture GENERATED n'a pas de sentAt — sa date de rattachement est
        // createdAt (horodatage serveur "maintenant"), jamais la date de la mission.
        // Fenêtre large pour couvrir "maintenant" sans dépendre de la date du jour ;
        // firmId scope la base de dev (copie de production, voir docblock de filter()).
        $pipeline = $this->stats->pipeline($this->wideFilter($firm->getId()));
        self::assertSame(1, $pipeline->generatedInvoicesNotIssued);
    }

    public function test_pipeline_counts_issued_invoice_with_open_balance(): void
    {
        [$invoice, , $actor, $firm] = $this->makeIssuedInvoice('2026-05-23', '500.00');
        $payment = $this->paymentService->recordPayment($invoice, '100.00', 'EUR', new \DateTimeImmutable('2026-05-24'), PaymentMethod::BANK_TRANSFER, null, null, $actor);
        $this->created['payments'][] = $payment->getId();

        // issue() horodate sentAt à "maintenant" (jamais la date de la mission) — voir
        // note ci-dessus.
        $pipeline = $this->stats->pipeline($this->wideFilter($firm->getId()));
        self::assertSame(1, $pipeline->issuedInvoicesWithOpenBalance);
    }

    public function test_pipeline_counts_overpaid_document_awaiting_refund(): void
    {
        [$invoice, $line, $actor, $firm] = $this->makeIssuedInvoice('2026-05-25', '300.00');
        $payment = $this->paymentService->recordPayment($invoice, '300.00', 'EUR', new \DateTimeImmutable('2026-05-26'), PaymentMethod::BANK_TRANSFER, null, null, $actor);
        $this->created['payments'][] = $payment->getId();

        $input = new CorrectionLineInput($line->getId(), CorrectionReasonCode::WRONG_QUANTITY, 'Correction', '1', '100.00');
        $creditNote = $this->correctionService->createCreditNote($invoice, [$input], null, $actor);
        $this->created['invoices'][] = $creditNote->getId();
        $this->correctionService->issueCorrection($creditNote, $actor);
        // Aucun remboursement enregistré — trop-perçu de 100.00 en attente.

        $pipeline = $this->stats->pipeline($this->wideFilter($firm->getId()));
        self::assertSame(1, $pipeline->overpaidDocumentsAwaitingRefund);
    }

    // ── Séries temporelles (§11) ──────────────────────────────────────────

    public function test_timeseries_includes_empty_buckets_with_zero(): void
    {
        $businessTz = new \DateTimeZone(\App\Doctrine\Type\BusinessDateTimeImmutableType::BUSINESS_TIMEZONE);
        $firm = $this->makeFirm();
        $this->makeApprovedMission($firm, '100.00', '0.00', '0.00', new \DateTimeImmutable('2026-05-01 09:00:00', $businessTz), 30);

        $points = $this->stats->timeseries(
            $this->filter(new \DateTimeImmutable('2026-05-01 00:00:00', $businessTz), new \DateTimeImmutable('2026-05-04 00:00:00', $businessTz), firmId: $firm->getId()),
            StatisticsGranularity::DAY,
        );

        self::assertCount(3, $points, 'un bucket par jour sur [1er, 4) mai, y compris ceux sans donnée.');
        self::assertSame(1, $points[0]->missionCount);
        self::assertSame(0, $points[1]->missionCount);
        self::assertSame([], $points[1]->currencies);
    }

    // ── Helpers de fixtures documentaires ─────────────────────────────────

    /** @return array{0: Mission, 1: FinancialCalculation, 2: User, 3: User, 4: Firm, 5: User} */
    private function makeApprovedMissionForPipeline(): array
    {
        $firm = $this->makeFirm();
        return $this->makeApprovedMission($firm, '100.00', '0.00', '40.00', new \DateTimeImmutable('2026-05-19 09:00:00'), 60);
    }

    private function firstFirmLine(FinancialCalculation $calc): \App\Entity\FinancialCalculationLine
    {
        foreach ($calc->getLines() as $l) {
            if ($l->getLineType()->value === 'FIRM_INTERVENTION_FEE') { return $l; }
        }
        throw new \RuntimeException('no firm line');
    }

    /** @return array{0: FirmInvoice, 1: \App\Entity\FirmInvoiceLine, 2: User} */
    /** @return array{0: FirmInvoice, 1: \App\Entity\FirmInvoiceLine, 2: User, 3: Firm} */
    private function makeIssuedInvoice(string $dateStr, string $unitPrice): array
    {
        $firm = $this->makeFirm();
        [, $calc, , , , $actor] = $this->makeApprovedMission($firm, $unitPrice, '0.00', '0.00', new \DateTimeImmutable($dateStr . ' 09:00:00'), 30);
        $firmLine = $this->firstFirmLine($calc);

        $invoice = $this->invoiceService->createFromEligibleLines($firm, 'EUR', new \DateTimeImmutable('2020-01-01'), new \DateTimeImmutable('2030-01-01'), [$firmLine->getId()], $actor);
        $this->created['invoices'][] = $invoice->getId();
        $invoice = $this->invoiceService->issue($invoice, $actor);

        return [$invoice, $invoice->getLines()->first(), $actor, $firm];
    }

    /** @return array{0: InstrumentistStatement, 1: \App\Entity\InstrumentistStatementLine, 2: User, 3: User} */
    private function makeIssuedStatement(string $dateStr, string $hourlyRate): array
    {
        $firm = $this->makeFirm();
        [$mission, $calc, $instrumentist, , , $actor] = $this->makeApprovedMission($firm, '0.00', '0.00', $hourlyRate, new \DateTimeImmutable($dateStr . ' 09:00:00'), 60);

        $instrLine = null;
        foreach ($calc->getLines() as $l) {
            if ($l->getLineType()->value === 'INSTRUMENTIST_HOURLY') { $instrLine = $l; }
        }

        $statement = $this->statementService->createFromEligibleLines($instrumentist, 'EUR', (int) $mission->getStartAt()->format('Y'), (int) $mission->getStartAt()->format('m'), [$instrLine->getId()], $actor);
        $this->created['statements'][] = $statement->getId();
        $statement = $this->statementService->issue($statement, $actor);

        return [$statement, $statement->getLines()->first(), $actor, $instrumentist];
    }
}
