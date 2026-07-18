<?php

namespace App\Tests\Integration;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\FinancialCalculation;
use App\Entity\FinancialCalculationLine;
use App\Entity\FirmInvoice;
use App\Entity\FirmInvoiceLine;
use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\InstrumentistStatement;
use App\Entity\InstrumentistStatementLine;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\FinancialCalculationStatus;
use App\Enum\InstrumentistRateType;
use App\Enum\InvoiceStatus;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PricingRuleType;
use App\Exception\DocumentAlreadyIssuedException;
use App\Exception\DocumentLineSelectionException;
use App\Service\FinancialCalculationService;
use App\Service\FirmInvoiceService;
use App\Service\InstrumentistStatementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * EPIC Exécution & Valorisation, Lot 4 (D-074) — §34 du lot : sélection des lignes
 * FIRM_ et INSTRUMENTIST_, regroupement, rattachement, verrouillage du calcul, calcul
 * partiellement consommé (§11/§30), annulation, legacy inchangé. Appels réels contre une
 * base réelle — pas de mock des services final.
 */
final class FirmInvoiceFinancialCalculationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $connectionParams;
    private FinancialCalculationService $calcService;
    private FirmInvoiceService $invoiceService;
    private InstrumentistStatementService $statementService;
    private array $created = [
        'invoices' => [], 'invoiceLines' => [], 'statements' => [], 'statementLines' => [],
        'calculations' => [], 'lines' => [], 'executions' => [], 'materialLines' => [],
        'interventions' => [], 'missions' => [], 'rates' => [], 'rules' => [],
        'items' => [], 'types' => [], 'firms' => [], 'sites' => [], 'users' => [],
    ];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->connectionParams = $this->em->getConnection()->getParams();
        $this->calcService = self::getContainer()->get(FinancialCalculationService::class);
        $this->invoiceService = self::getContainer()->get(FirmInvoiceService::class);
        $this->statementService = self::getContainer()->get(InstrumentistStatementService::class);
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen()) {
            foreach ($this->created['missions'] as $missionId) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $missionId]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            $this->em->flush();
            // Purge audit globaux (recordGlobal) créés par cet acteur — évite un FK
            // violation lors de la suppression des User de test.
            foreach ($this->created['users'] as $userId) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['actor' => $userId]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            $this->em->flush();

            // FirmInvoice/InstrumentistStatement déclarent déjà cascade:['persist','remove']
            // + orphanRemoval sur leurs lignes — supprimer le document suffit, supprimer
            // aussi les lignes individuellement créerait une collection en mémoire périmée
            // (ORMInvalidArgumentException au flush suivant).
            foreach ($this->created['invoices'] as $id) { $e = $this->em->find(FirmInvoice::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['statements'] as $id) { $e = $this->em->find(InstrumentistStatement::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();

            foreach ($this->created['lines'] as $id) { $e = $this->em->find(FinancialCalculationLine::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['calculations'] as $id) {
                $e = $this->em->find(FinancialCalculation::class, $id);
                if ($e) { $e->setSupersededByCalculation(null); }
            }
            $this->em->flush();
            foreach ($this->created['calculations'] as $id) { $e = $this->em->find(FinancialCalculation::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['executions'] as $id) { $e = $this->em->find(\App\Entity\MissionExecution::class, $id); if ($e) $this->em->remove($e); }
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

    /**
     * `wrapInTransaction()` ferme l'EntityManager dans son bloc finally dès qu'une
     * exception le traverse (voir EntityManager::wrapInTransaction()) — un test qui
     * provoque volontairement cet échec doit obtenir un EntityManager frais pour ses
     * assertions post-exception ET pour que tearDown() puisse nettoyer normalement.
     */
    private function freshEntityManager(): EntityManagerInterface
    {
        return new \Doctrine\ORM\EntityManager(
            \Doctrine\DBAL\DriverManager::getConnection($this->connectionParams),
            $this->em->getConfiguration(),
        );
    }

    private function makeFirm(string $name): Firm
    {
        $f = new Firm();
        $f->setName($name . '-' . bin2hex(random_bytes(3)));
        $this->em->persist($f); $this->em->flush();
        $this->created['firms'][] = $f->getId();
        return $f;
    }

    private function makeType(string $code): InterventionType
    {
        $t = new InterventionType();
        $t->setCode($code . '-' . bin2hex(random_bytes(3)));
        $t->setLabel($code);
        $this->em->persist($t); $this->em->flush();
        $this->created['types'][] = $t->getId();
        return $t;
    }

    private function makeItem(Firm $firm): MaterialItem
    {
        $mi = new MaterialItem();
        $mi->setFirm($firm);
        $mi->setLabel('Item-' . bin2hex(random_bytes(3)));
        $mi->setUnit('pièce');
        $mi->setReferenceCode(bin2hex(random_bytes(4)));
        $this->em->persist($mi); $this->em->flush();
        $this->created['items'][] = $mi->getId();
        return $mi;
    }

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('fifc-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setPassword('x');
        $this->em->persist($u); $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('FIFC-' . bin2hex(random_bytes(3)));
        $this->em->persist($h); $this->em->flush();
        $this->created['sites'][] = $h->getId();
        return $h;
    }

    private function interventionRule(Firm $firm, InterventionType $type, string $price): PricingRule
    {
        $r = new PricingRule();
        $r->setFirm($firm);
        $r->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $r->setInterventionType($type);
        $r->setUnitPrice($price);
        $this->em->persist($r); $this->em->flush();
        $this->created['rules'][] = $r->getId();
        return $r;
    }

    private function materialRule(Firm $firm, MaterialItem $item, string $price): PricingRule
    {
        $r = new PricingRule();
        $r->setFirm($firm);
        $r->setRuleType(PricingRuleType::MATERIAL_FEE);
        $r->setMaterialItem($item);
        $r->setUnitPrice($price);
        $this->em->persist($r); $this->em->flush();
        $this->created['rules'][] = $r->getId();
        return $r;
    }

    private function hourlyRate(User $instrumentist, string $amount): InstrumentistRate
    {
        $r = new InstrumentistRate();
        $r->setInstrumentist($instrumentist);
        $r->setRateType(InstrumentistRateType::HOURLY_RATE);
        $r->setAmount($amount);
        $r->setCurrency('EUR');
        $r->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($r); $this->em->flush();
        $this->created['rates'][] = $r->getId();
        return $r;
    }

    /**
     * Construit une mission VALIDATED avec une intervention (firmA) + une ligne matériel
     * (firmB) + un instrumentiste — puis calcule et APPROVE le FinancialCalculation
     * résultant. Retourne le calcul APPROVED, prêt à être consommé par les deux services
     * documentaires (§30 : plusieurs bénéficiaires sur un même calcul).
     */
    private function makeApprovedCalculation(Firm $firmIntervention, InterventionType $type, Firm $firmMaterial, MaterialItem $item, User $instrumentist, User $actor, \DateTimeImmutable $missionDate): FinancialCalculation
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');

        $mission = new Mission();
        $mission->setType(MissionType::BLOCK);
        $mission->setSite($site);
        $mission->setSurgeon($surgeon);
        $mission->setCreatedBy($surgeon);
        $mission->setStartAt($missionDate);
        $mission->setEndAt($missionDate->modify('+2 hours'));
        $mission->setStatus(MissionStatus::VALIDATED);
        $mission->setInstrumentist($instrumentist);
        $this->em->persist($mission); $this->em->flush();
        $this->created['missions'][] = $mission->getId();

        $intervention = new MissionIntervention();
        $intervention->setMission($mission);
        $intervention->setCode($type->getCode());
        $intervention->setLabel('Test intervention');
        $intervention->setInterventionType($type);
        $intervention->setPrimaryFirm($firmIntervention);
        $this->em->persist($intervention); $this->em->flush();
        $this->created['interventions'][] = $intervention->getId();
        $mission->getInterventions()->add($intervention);

        $materialLine = new MaterialLine();
        $materialLine->setMission($mission);
        $materialLine->setMissionIntervention($intervention);
        $materialLine->setItem($item);
        $materialLine->setQuantity('2.00');
        $materialLine->setCreatedBy($surgeon);
        $this->em->persist($materialLine); $this->em->flush();
        $this->created['materialLines'][] = $materialLine->getId();
        $mission->getMaterialLines()->add($materialLine);

        $calculation = $this->calcService->calculate($mission, $actor);
        $this->created['calculations'][] = $calculation->getId();
        foreach ($calculation->getLines() as $l) { $this->created['lines'][] = $l->getId(); }

        return $this->calcService->approve($calculation, $actor);
    }

    private function trackInvoice(FirmInvoice $i): FirmInvoice
    {
        $this->created['invoices'][] = $i->getId();
        foreach ($i->getLines() as $l) { $this->created['invoiceLines'][] = $l->getId(); }
        return $i;
    }

    private function trackStatement(InstrumentistStatement $s): InstrumentistStatement
    {
        $this->created['statements'][] = $s->getId();
        foreach ($s->getLines() as $l) { $this->created['statementLines'][] = $l->getId(); }
        return $s;
    }

    // ── Sélection / éligibilité ──────────────────────────────────────────

    public function test_preview_eligible_lines_returns_only_firm_lines_for_the_target_firm(): void
    {
        $firmA = $this->makeFirm('InvA');
        $firmB = $this->makeFirm('InvB');
        $type = $this->makeType('LCA');
        $item = $this->makeItem($firmB);
        $this->interventionRule($firmA, $type, '180.00');
        $this->materialRule($firmB, $item, '40.00');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '45.00');
        $actor = $this->makeUser('ROLE_MANAGER');

        $today = new \DateTimeImmutable('2026-06-15');
        $this->makeApprovedCalculation($firmA, $type, $firmB, $item, $instrumentist, $actor, $today);

        $previewA = $this->invoiceService->previewEligibleLines($firmA, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'));
        self::assertCount(1, $previewA['lines'], json_encode($previewA));
        self::assertSame('FIRM_INTERVENTION_FEE', $previewA['lines'][0]['lineType']);
        self::assertSame('180.00', $previewA['totalAmount']);

        $previewB = $this->invoiceService->previewEligibleLines($firmB, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'));
        self::assertCount(1, $previewB['lines'], json_encode($previewB));
        self::assertSame('FIRM_MATERIAL_FEE', $previewB['lines'][0]['lineType']);
        self::assertSame('80.00', $previewB['totalAmount']);
    }

    public function test_calculated_not_approved_calculation_is_not_eligible(): void
    {
        $firm = $this->makeFirm('NotApproved');
        $type = $this->makeType('PTE');
        $item = $this->makeItem($firm);
        $this->interventionRule($firm, $type, '100.00');
        $this->materialRule($firm, $item, '10.00');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '40.00');
        $actor = $this->makeUser('ROLE_MANAGER');

        $today = new \DateTimeImmutable('2026-06-15');
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
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
        $intervention->setLabel('PTE');
        $intervention->setInterventionType($type);
        $intervention->setPrimaryFirm($firm);
        $this->em->persist($intervention); $this->em->flush();
        $this->created['interventions'][] = $intervention->getId();
        $mission->getInterventions()->add($intervention);

        $calc = $this->calcService->calculate($mission, $actor); // reste CALCULATED, jamais approuvé
        $this->created['calculations'][] = $calc->getId();
        foreach ($calc->getLines() as $l) { $this->created['lines'][] = $l->getId(); }

        $preview = $this->invoiceService->previewEligibleLines($firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'));
        self::assertSame([], $preview['lines'], 'un calcul CALCULATED (non APPROVED) ne doit jamais être éligible');
    }

    public function test_superseded_calculation_lines_are_never_eligible(): void
    {
        $firm = $this->makeFirm('Superseded');
        $type = $this->makeType('EPAULE');
        $item = $this->makeItem($firm);
        $this->interventionRule($firm, $type, '150.00');
        $this->materialRule($firm, $item, '20.00');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '40.00');
        $actor = $this->makeUser('ROLE_MANAGER');

        $today = new \DateTimeImmutable('2026-06-15');
        $calc = $this->makeApprovedCalculation($firm, $type, $firm, $item, $instrumentist, $actor, $today);
        $mission = $calc->getMission();

        // recalculate() nécessite de repasser par CALCULATED — mais approve() a déjà
        // verrouillé... non : recalculate() fonctionne tant que non LOCKED. On appelle
        // recalculate() directement : l'ancien calcul (celui qu'on a APPROVED) passe
        // SUPERSEDED.
        $new = $this->calcService->recalculate($mission, $actor);
        $this->created['calculations'][] = $new->getId();
        foreach ($new->getLines() as $l) { $this->created['lines'][] = $l->getId(); }

        $preview = $this->invoiceService->previewEligibleLines($firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'));
        self::assertSame([], $preview['lines'], 'les lignes d\'un calcul SUPERSEDED (même si l\'ancien était APPROVED) ne sont plus éligibles tant que le nouveau n\'est pas lui-même approuvé');
    }

    // ── Création / rattachement / verrouillage ───────────────────────────

    public function test_create_from_eligible_lines_attaches_lines_and_locks_calculation(): void
    {
        $firmA = $this->makeFirm('LockA');
        $firmB = $this->makeFirm('LockB');
        $type = $this->makeType('MENISQUE');
        $item = $this->makeItem($firmB);
        $this->interventionRule($firmA, $type, '200.00');
        $this->materialRule($firmB, $item, '30.00');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '50.00');
        $actor = $this->makeUser('ROLE_MANAGER');

        $today = new \DateTimeImmutable('2026-06-15');
        $calc = $this->makeApprovedCalculation($firmA, $type, $firmB, $item, $instrumentist, $actor, $today);

        $previewA = $this->invoiceService->previewEligibleLines($firmA, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'));
        $lineIdA = $previewA['lines'][0]['id'];

        $invoice = $this->trackInvoice($this->invoiceService->createFromEligibleLines(
            $firmA, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'), [$lineIdA], $actor,
        ));

        self::assertSame(InvoiceStatus::GENERATED, $invoice->getStatus());
        self::assertFalse($invoice->isLegacySource());
        self::assertCount(1, $invoice->getLines());
        $invoiceLine = $invoice->getLines()->first();
        self::assertSame($lineIdA, $invoiceLine->getFinancialCalculationLine()->getId());
        self::assertSame('200.00', $invoiceLine->getTotalAmount());
        self::assertFalse($invoiceLine->isLegacy());

        // Le calcul doit être passé LOCKED (§10/§30) dès l'intégration d'une seule ligne.
        $this->em->refresh($calc);
        self::assertSame(FinancialCalculationStatus::LOCKED, $calc->getStatus());

        // §30 : les autres lignes du même calcul (matériel firmB, instrumentiste) restent
        // sélectionnables malgré le LOCKED.
        self::assertTrue($calc->hasUnassignedFirmLines(), 'la ligne matériel (firmB) est encore libre');
        self::assertTrue($calc->hasUnassignedInstrumentistLines());
        self::assertFalse($calc->isFullyDocumented());

        $eventTypes = array_map(
            static fn (AuditEvent $e) => $e->getEventType()->value,
            $this->em->getRepository(AuditEvent::class)->findBy(['actor' => $actor], ['id' => 'ASC']),
        );
        self::assertContains(AuditEventType::FIRM_INVOICE_CREATED_FROM_CALCULATION->value, $eventTypes);
    }

    public function test_locked_calculation_still_allows_assigning_its_other_lines(): void
    {
        $firm = $this->makeFirm('MultiBenef');
        $type = $this->makeType('HANCHE');
        $item = $this->makeItem($firm);
        $this->interventionRule($firm, $type, '220.00');
        $this->materialRule($firm, $item, '25.00');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '48.00');
        $actor = $this->makeUser('ROLE_MANAGER');

        $today = new \DateTimeImmutable('2026-06-15');
        $calc = $this->makeApprovedCalculation($firm, $type, $firm, $item, $instrumentist, $actor, $today);

        // 1) Facture la ligne d'intervention seule (2 lignes firmes existent : intervention + matériel).
        $previewFirm = $this->invoiceService->previewEligibleLines($firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'));
        $interventionLine = current(array_filter($previewFirm['lines'], static fn ($l) => $l['lineType'] === 'FIRM_INTERVENTION_FEE'));
        $this->trackInvoice($this->invoiceService->createFromEligibleLines(
            $firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'), [$interventionLine['id']], $actor,
        ));

        $this->em->refresh($calc);
        self::assertSame(FinancialCalculationStatus::LOCKED, $calc->getStatus());

        // 2) Le calcul est déjà LOCKED — le décompte instrumentiste doit quand même
        //    pouvoir consommer la ligne INSTRUMENTIST_HOURLY du même calcul (§30).
        $previewInstr = $this->statementService->previewEligibleLines($instrumentist, 'EUR', (int) $today->format('Y'), (int) $today->format('m'));
        self::assertCount(1, $previewInstr['lines'], json_encode($previewInstr));

        $statement = $this->trackStatement($this->statementService->createFromEligibleLines(
            $instrumentist, 'EUR', (int) $today->format('Y'), (int) $today->format('m'), [$previewInstr['lines'][0]['id']], $actor,
        ));
        self::assertCount(1, $statement->getLines());

        // 3) Le matériel (firm) reste la seule ligne encore libre.
        $this->em->refresh($calc);
        self::assertTrue($calc->hasUnassignedFirmLines());
        self::assertFalse($calc->hasUnassignedInstrumentistLines());
        self::assertFalse($calc->isFullyDocumented());

        // 4) Facture le matériel — le calcul doit être ENTIÈREMENT documenté.
        $previewMaterial = $this->invoiceService->previewEligibleLines($firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'));
        self::assertCount(1, $previewMaterial['lines']);
        $this->trackInvoice($this->invoiceService->createFromEligibleLines(
            $firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'), [$previewMaterial['lines'][0]['id']], $actor,
        ));

        $this->em->refresh($calc);
        self::assertFalse($calc->hasUnassignedFirmLines());
        self::assertFalse($calc->hasUnassignedInstrumentistLines());
        self::assertTrue($calc->isFullyDocumented());
    }

    public function test_double_invoicing_the_same_line_is_rejected(): void
    {
        $firm = $this->makeFirm('DoubleBill');
        $type = $this->makeType('CHEVILLE');
        $item = $this->makeItem($firm);
        $this->interventionRule($firm, $type, '90.00');
        $this->materialRule($firm, $item, '15.00');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '40.00');
        $actor = $this->makeUser('ROLE_MANAGER');

        $today = new \DateTimeImmutable('2026-06-15');
        $this->makeApprovedCalculation($firm, $type, $firm, $item, $instrumentist, $actor, $today);

        $preview = $this->invoiceService->previewEligibleLines($firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'));
        $interventionLine = current(array_filter($preview['lines'], static fn ($l) => $l['lineType'] === 'FIRM_INTERVENTION_FEE'));

        $this->trackInvoice($this->invoiceService->createFromEligibleLines(
            $firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'), [$interventionLine['id']], $actor,
        ));

        try {
            $this->invoiceService->createFromEligibleLines(
                $firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'), [$interventionLine['id']], $actor,
            );
            self::fail('Devait lever DocumentLineSelectionException (double facturation).');
        } catch (DocumentLineSelectionException $e) {
            $codes = array_map(static fn ($a) => $a->code, $e->getAnomalies());
            self::assertContains('FINANCIAL_LINE_ALREADY_ASSIGNED', $codes);
        }

        self::assertCount(1, $this->em->getRepository(FirmInvoice::class)->findBy(['firm' => $firm]), 'aucune deuxième facture créée');
    }

    public function test_atomic_failure_creates_no_partial_document(): void
    {
        $firm = $this->makeFirm('Atomic');
        $type = $this->makeType('POIGNET');
        $item = $this->makeItem($firm);
        $this->interventionRule($firm, $type, '110.00');
        $this->materialRule($firm, $item, '18.00');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '40.00');
        $actor = $this->makeUser('ROLE_MANAGER');

        $today = new \DateTimeImmutable('2026-06-15');
        $calc = $this->makeApprovedCalculation($firm, $type, $firm, $item, $instrumentist, $actor, $today);

        $preview = $this->invoiceService->previewEligibleLines($firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'));
        $validLineId = $preview['lines'][0]['id'];
        $invalidLineId = 999999999; // n'existe pas

        try {
            $this->invoiceService->createFromEligibleLines(
                $firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'), [$validLineId, $invalidLineId], $actor,
            );
            self::fail('Devait lever DocumentLineSelectionException.');
        } catch (DocumentLineSelectionException) {
        }

        // wrapInTransaction() a fermé $this->em suite à l'exception (voir
        // freshEntityManager()) — on continue avec un EntityManager frais, y compris
        // pour tearDown().
        $this->em = $this->freshEntityManager();

        self::assertSame([], $this->em->getRepository(FirmInvoice::class)->findBy(['firm' => $firm]), 'aucun document partiel');
        $calcReloaded = $this->em->find(FinancialCalculation::class, $calc->getId());
        self::assertSame(FinancialCalculationStatus::APPROVED, $calcReloaded->getStatus(), 'le calcul ne doit pas être verrouillé si la création échoue');
    }

    // ── Annulation ────────────────────────────────────────────────────────

    public function test_cancel_generated_invoice_releases_lines_but_never_unlocks_calculation(): void
    {
        $firm = $this->makeFirm('CancelMe');
        $type = $this->makeType('EPAULE2');
        $item = $this->makeItem($firm);
        $this->interventionRule($firm, $type, '130.00');
        $this->materialRule($firm, $item, '22.00');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '40.00');
        $actor = $this->makeUser('ROLE_MANAGER');

        $today = new \DateTimeImmutable('2026-06-15');
        $calc = $this->makeApprovedCalculation($firm, $type, $firm, $item, $instrumentist, $actor, $today);

        $preview = $this->invoiceService->previewEligibleLines($firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'));
        $interventionLine = current(array_filter($preview['lines'], static fn ($l) => $l['lineType'] === 'FIRM_INTERVENTION_FEE'));

        $invoice = $this->trackInvoice($this->invoiceService->createFromEligibleLines(
            $firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'), [$interventionLine['id']], $actor,
        ));
        $this->em->refresh($calc);
        self::assertSame(FinancialCalculationStatus::LOCKED, $calc->getStatus());

        $invoice = $this->invoiceService->cancel($invoice, $actor, 'erreur de saisie');
        self::assertSame(InvoiceStatus::CANCELLED, $invoice->getStatus());
        self::assertCount(0, $invoice->getLines());

        // Le calcul reste LOCKED — jamais déverrouillé automatiquement (§10).
        $this->em->refresh($calc);
        self::assertSame(FinancialCalculationStatus::LOCKED, $calc->getStatus());

        // La ligne libérée peut être refacturée dans un NOUVEAU document.
        $previewAfter = $this->invoiceService->previewEligibleLines($firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'));
        self::assertCount(1, array_filter($previewAfter['lines'], static fn ($l) => $l['lineType'] === 'FIRM_INTERVENTION_FEE'), 'la ligne annulée redevient sélectionnable');

        $invoice2 = $this->trackInvoice($this->invoiceService->createFromEligibleLines(
            $firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'), [$interventionLine['id']], $actor,
        ));
        self::assertCount(1, $invoice2->getLines());
    }

    public function test_sent_invoice_cannot_be_cancelled(): void
    {
        $firm = $this->makeFirm('SentNoCancel');
        $type = $this->makeType('GENOU2');
        $item = $this->makeItem($firm);
        $this->interventionRule($firm, $type, '95.00');
        $this->materialRule($firm, $item, '12.00');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '40.00');
        $actor = $this->makeUser('ROLE_MANAGER');

        $today = new \DateTimeImmutable('2026-06-15');
        $this->makeApprovedCalculation($firm, $type, $firm, $item, $instrumentist, $actor, $today);

        $preview = $this->invoiceService->previewEligibleLines($firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'));
        $interventionLine = current(array_filter($preview['lines'], static fn ($l) => $l['lineType'] === 'FIRM_INTERVENTION_FEE'));

        $invoice = $this->trackInvoice($this->invoiceService->createFromEligibleLines(
            $firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'), [$interventionLine['id']], $actor,
        ));

        $invoice->setBillingEmailTo('firm@example.test');
        $invoice = $this->invoiceService->markSent($invoice, $actor);
        self::assertSame(InvoiceStatus::SENT, $invoice->getStatus());

        $this->expectException(DocumentAlreadyIssuedException::class);
        $this->invoiceService->cancel($invoice, $actor);
    }

    // ── Legacy inchangé ───────────────────────────────────────────────────

    public function test_legacy_generate_path_is_untouched_and_marked_legacy(): void
    {
        $firm = $this->makeFirm('LegacyPath');
        $type = $this->makeType('LEGACY');
        $rule = new PricingRule();
        $rule->setFirm($firm);
        $rule->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $rule->setInterventionType($type);
        $rule->setUnitPrice('75.00');
        $this->em->persist($rule); $this->em->flush();
        $this->created['rules'][] = $rule->getId();

        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $today = new \DateTimeImmutable('2026-06-15');
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
        $intervention->setLabel('Legacy');
        $this->em->persist($intervention); $this->em->flush();
        $this->created['interventions'][] = $intervention->getId();

        // Le chemin legacy relit Mission.interventions par fetch-join — l'ArrayCollection
        // déjà initialisée en mémoire sur $mission (construite via `new Mission()`) n'est
        // jamais re-peuplée par ce fetch-join tant que $mission reste géré par le même
        // EntityManager (identity map) — voir FirmInvoiceServiceLot1AdaptationTest. On
        // force le rechargement, comme ce test de référence.
        $this->em->clear();
        $firm = $this->em->find(Firm::class, $firm->getId());

        $invoice = $this->trackInvoice($this->invoiceService->generate(
            $firm, $today->modify('-1 day'), $today->modify('+1 day'), [$intervention->getId()], [],
        ));

        self::assertTrue($invoice->isLegacySource());
        self::assertSame(75.0, (float) $invoice->getTotalAmount());
        $line = $invoice->getLines()->first();
        self::assertTrue($line->isLegacy());
        self::assertNull($line->getFinancialCalculationLine());
    }
}
