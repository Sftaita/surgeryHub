<?php

namespace App\Tests\Integration;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\FinancialCalculation;
use App\Entity\FinancialCalculationLine;
use App\Entity\FirmServiceOffering;
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
use App\Enum\MaterialBillingStatus;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PricingRuleType;
use App\Exception\FinancialCalculationAnomaliesException;
use App\Service\FinancialCalculationService;
use App\Service\PricingRuleWriteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Refonte Catalogue/Prestations (D-092) — moteur de neutralisation "présence d'un
 * délégué". Appels réels contre une base réelle (KernelTestCase), jamais de mock de
 * FinancialCalculationService (final).
 */
final class RepresentativePolicyFinancialCalculationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FinancialCalculationService $service;
    private PricingRuleWriteService $ruleWriter;
    private array $created = [
        'calculations' => [], 'lines' => [], 'materialLines' => [],
        'interventions' => [], 'missions' => [], 'rates' => [], 'rules' => [],
        'items' => [], 'types' => [], 'offerings' => [], 'firms' => [], 'sites' => [], 'users' => [],
    ];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(FinancialCalculationService::class);
        $this->ruleWriter = self::getContainer()->get(PricingRuleWriteService::class);
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

            foreach ($this->created['lines'] as $id) { $e = $this->em->find(FinancialCalculationLine::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['calculations'] as $id) {
                $e = $this->em->find(FinancialCalculation::class, $id);
                if ($e) { $e->setSupersededByCalculation(null); }
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
            foreach ($this->created['offerings'] as $id) { $e = $this->em->find(FirmServiceOffering::class, $id); if ($e) $this->em->remove($e); }
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

    private function makeItem(Firm $firm, MaterialBillingStatus $billingStatus = MaterialBillingStatus::UNSPECIFIED): MaterialItem
    {
        $mi = new MaterialItem();
        $mi->setFirm($firm);
        $mi->setLabel('Item-' . bin2hex(random_bytes(3)));
        $mi->setUnit('pièce');
        $mi->setReferenceCode(bin2hex(random_bytes(4)));
        $mi->setBillingStatus($billingStatus);
        $this->em->persist($mi); $this->em->flush();
        $this->created['items'][] = $mi->getId();
        return $mi;
    }

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('rp-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $h->setName('RP-' . bin2hex(random_bytes(3)));
        $this->em->persist($h); $this->em->flush();
        $this->created['sites'][] = $h->getId();
        return $h;
    }

    private function makeMission(?User $instrumentist): Mission
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');

        $m = new Mission();
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($surgeon);
        $m->setStartAt(new \DateTimeImmutable('2026-06-01 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-06-01 10:00:00'));
        $m->setStatus(MissionStatus::VALIDATED);
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        $this->em->persist($m); $this->em->flush();
        $this->created['missions'][] = $m->getId();
        return $m;
    }

    private function addIntervention(Mission $mission, ?InterventionType $type, ?Firm $primaryFirm, ?bool $representativePresent = null): MissionIntervention
    {
        $i = new MissionIntervention();
        $i->setMission($mission);
        $i->setCode($type?->getCode() ?? 'LEGACY');
        $i->setLabel($type?->getLabel() ?? 'Legacy intervention');
        $i->setInterventionType($type);
        $i->setPrimaryFirm($primaryFirm);
        $i->setRepresentativePresent($representativePresent);
        $this->em->persist($i); $this->em->flush();
        $this->created['interventions'][] = $i->getId();
        $mission->getInterventions()->add($i);
        return $i;
    }

    private function addMaterialLine(Mission $mission, ?MissionIntervention $intervention, MaterialItem $item, string $quantity): MaterialLine
    {
        $l = new MaterialLine();
        $l->setMission($mission);
        if ($intervention !== null) {
            $l->setMissionIntervention($intervention);
        }
        $l->setItem($item);
        $l->setQuantity($quantity);
        $l->setCreatedBy($mission->getSurgeon());
        $this->em->persist($l); $this->em->flush();
        $this->created['materialLines'][] = $l->getId();
        $mission->getMaterialLines()->add($l);
        return $l;
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

    /** Passe par le service d'écriture réel (pas une construction directe) pour couvrir l'auto-promotion billingStatus. */
    private function materialRule(Firm $firm, MaterialItem $item, string $price): PricingRule
    {
        $r = new PricingRule();
        $r->setFirm($firm);
        $r->setRuleType(PricingRuleType::MATERIAL_FEE);
        $r->setMaterialItem($item);
        $r->setUnitPrice($price);
        $this->ruleWriter->create($r);
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

    private function addOffering(
        Firm $firm,
        InterventionType $type,
        bool $representativePresenceRelevant = false,
        bool $suppressesInterventionFee = false,
        bool $suppressesOwnMaterialFees = false,
        bool $feeApplicable = true,
    ): FirmServiceOffering {
        $o = new FirmServiceOffering();
        $o->setFirm($firm);
        $o->setInterventionType($type);
        $o->setRepresentativePresenceRelevant($representativePresenceRelevant);
        $o->setRepresentativeSuppressesInterventionFee($suppressesInterventionFee);
        $o->setRepresentativeSuppressesOwnMaterialFees($suppressesOwnMaterialFees);
        $o->setFeeApplicable($feeApplicable);
        $this->em->persist($o); $this->em->flush();
        $this->created['offerings'][] = $o->getId();
        return $o;
    }

    private function trackCalculation(FinancialCalculation $c): FinancialCalculation
    {
        $this->created['calculations'][] = $c->getId();
        foreach ($c->getLines() as $l) {
            $this->created['lines'][] = $l->getId();
        }
        return $c;
    }

    private function lineOfType(FinancialCalculation $c, string $lineType, ?int $materialItemId = null): FinancialCalculationLine
    {
        foreach ($c->getLines() as $l) {
            if ($l->getLineType()->value !== $lineType) continue;
            if ($materialItemId !== null && ($l->getSnapshot()['materialItemId'] ?? null) !== $materialItemId) continue;
            return $l;
        }
        self::fail("Aucune ligne $lineType trouvée.");
    }

    private function standardInstrumentist(): User
    {
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '40.00');
        return $instrumentist;
    }

    // ── 1. ConMed LCA sans délégué ──────────────────────────────────────────

    public function test_conmed_lca_without_representative_charges_normally(): void
    {
        $conmed = $this->makeFirm('ConMed');
        $lca = $this->makeType('LCA');
        $this->interventionRule($conmed, $lca, '150.00');
        $item = $this->makeItem($conmed);
        $this->materialRule($conmed, $item, '45.00');
        $this->addOffering($conmed, $lca, representativePresenceRelevant: true, suppressesInterventionFee: true, suppressesOwnMaterialFees: true);

        $instrumentist = $this->standardInstrumentist();
        $mission = $this->makeMission($instrumentist);
        $intervention = $this->addIntervention($mission, $lca, $conmed, representativePresent: false);
        $this->addMaterialLine($mission, $intervention, $item, '1.00');

        $calculation = $this->trackCalculation($this->service->calculate($mission, $this->makeUser('ROLE_MANAGER')));

        $feeLine = $this->lineOfType($calculation, 'FIRM_INTERVENTION_FEE');
        self::assertSame('150.00', $feeLine->getGrossAmount());
        self::assertSame('0.00', $feeLine->getAdjustmentAmount());
        self::assertSame('150.00', $feeLine->getTotalAmount());

        $matLine = $this->lineOfType($calculation, 'FIRM_MATERIAL_FEE');
        self::assertSame('45.00', $matLine->getTotalAmount());
    }

    // ── 2. ConMed LCA avec délégué ──────────────────────────────────────────

    public function test_conmed_lca_with_representative_zeroes_fee_and_material_but_keeps_trace(): void
    {
        $conmed = $this->makeFirm('ConMed');
        $lca = $this->makeType('LCA');
        $this->interventionRule($conmed, $lca, '150.00');
        $item = $this->makeItem($conmed);
        $this->materialRule($conmed, $item, '45.00');
        $this->addOffering($conmed, $lca, representativePresenceRelevant: true, suppressesInterventionFee: true, suppressesOwnMaterialFees: true);

        $instrumentist = $this->standardInstrumentist();
        $mission = $this->makeMission($instrumentist);
        $intervention = $this->addIntervention($mission, $lca, $conmed, representativePresent: true);
        $this->addMaterialLine($mission, $intervention, $item, '1.00');

        $calculation = $this->trackCalculation($this->service->calculate($mission, $this->makeUser('ROLE_MANAGER')));

        $feeLine = $this->lineOfType($calculation, 'FIRM_INTERVENTION_FEE');
        self::assertSame('150.00', $feeLine->getGrossAmount(), 'la trace du montant brut doit être conservée');
        self::assertSame('-150.00', $feeLine->getAdjustmentAmount());
        self::assertSame('0.00', $feeLine->getTotalAmount());
        self::assertNotNull($feeLine->getSnapshot()['adjustmentReasonSnapshot']);
        self::assertTrue($feeLine->getSnapshot()['representativePresentSnapshot']);

        $matLine = $this->lineOfType($calculation, 'FIRM_MATERIAL_FEE');
        self::assertSame('45.00', $matLine->getGrossAmount());
        self::assertSame('-45.00', $matLine->getAdjustmentAmount());
        self::assertSame('0.00', $matLine->getTotalAmount());

        // La ligne n'est jamais supprimée — elle reste persistée avec sa trace complète.
        self::assertCount(3, $calculation->getLines()); // forfait + matériel + horaire instrumentiste
    }

    // ── 3. ConMed + matériel Smith & Nephew dans la même intervention ──────

    public function test_conmed_material_suppressed_but_other_firm_material_charged_normally(): void
    {
        $conmed = $this->makeFirm('ConMed');
        $sn = $this->makeFirm('Smith & Nephew');
        $lca = $this->makeType('LCA');
        $this->interventionRule($conmed, $lca, '150.00');
        $conmedItem = $this->makeItem($conmed);
        $snItem = $this->makeItem($sn);
        $this->materialRule($conmed, $conmedItem, '45.00');
        $this->materialRule($sn, $snItem, '20.00');
        $this->addOffering($conmed, $lca, representativePresenceRelevant: true, suppressesInterventionFee: true, suppressesOwnMaterialFees: true);

        $instrumentist = $this->standardInstrumentist();
        $mission = $this->makeMission($instrumentist);
        $intervention = $this->addIntervention($mission, $lca, $conmed, representativePresent: true);
        $this->addMaterialLine($mission, $intervention, $conmedItem, '1.00');
        $this->addMaterialLine($mission, $intervention, $snItem, '1.00');

        $calculation = $this->trackCalculation($this->service->calculate($mission, $this->makeUser('ROLE_MANAGER')));

        $conmedLine = $this->lineOfType($calculation, 'FIRM_MATERIAL_FEE', $conmedItem->getId());
        self::assertSame('0.00', $conmedLine->getTotalAmount());

        $snLine = $this->lineOfType($calculation, 'FIRM_MATERIAL_FEE', $snItem->getId());
        self::assertSame('20.00', $snLine->getTotalAmount(), 'la règle ConMed ne doit jamais contaminer une ligne Smith & Nephew');
        self::assertSame('0.00', $snLine->getAdjustmentAmount());
    }

    // ── 4. SN avec politique "aucun impact" ─────────────────────────────────

    public function test_sn_representative_present_but_policy_has_no_impact(): void
    {
        $sn = $this->makeFirm('Smith & Nephew');
        $meniscal = $this->makeType('MENISCAL');
        $this->interventionRule($sn, $meniscal, '80.00');
        $item = $this->makeItem($sn);
        $this->materialRule($sn, $item, '15.00');
        // representativePresenceRelevant=false — la question ne devrait même pas être posée,
        // mais si une valeur legacy true traîne, elle ne doit produire aucun effet.
        $this->addOffering($sn, $meniscal, representativePresenceRelevant: false);

        $instrumentist = $this->standardInstrumentist();
        $mission = $this->makeMission($instrumentist);
        $intervention = $this->addIntervention($mission, $meniscal, $sn, representativePresent: true);
        $this->addMaterialLine($mission, $intervention, $item, '1.00');

        $calculation = $this->trackCalculation($this->service->calculate($mission, $this->makeUser('ROLE_MANAGER')));

        $feeLine = $this->lineOfType($calculation, 'FIRM_INTERVENTION_FEE');
        self::assertSame('80.00', $feeLine->getTotalAmount());
        self::assertSame('0.00', $feeLine->getAdjustmentAmount());
        self::assertNotEmpty($feeLine->getWarnings(), 'réponse "délégué présent" sans politique pertinente = avertissement informatif');
        self::assertSame('STALE_REPRESENTATIVE_PRESENCE_ANSWER', $feeLine->getWarnings()[0]['code']);

        $matLine = $this->lineOfType($calculation, 'FIRM_MATERIAL_FEE');
        self::assertSame('15.00', $matLine->getTotalAmount());
    }

    // ── 5. Même firme, deux prestations, politiques différentes ────────────

    public function test_same_firm_two_offerings_different_policies(): void
    {
        $conmed = $this->makeFirm('ConMed');
        $lca = $this->makeType('LCA');
        $ptg = $this->makeType('PTG');
        $this->interventionRule($conmed, $lca, '150.00');
        $this->interventionRule($conmed, $ptg, '300.00');
        $this->addOffering($conmed, $lca, representativePresenceRelevant: true, suppressesInterventionFee: true);
        $this->addOffering($conmed, $ptg, representativePresenceRelevant: true, suppressesInterventionFee: false);

        $instrumentist = $this->standardInstrumentist();

        $missionLca = $this->makeMission($instrumentist);
        $this->addIntervention($missionLca, $lca, $conmed, representativePresent: true);
        $calcLca = $this->trackCalculation($this->service->calculate($missionLca, $this->makeUser('ROLE_MANAGER')));
        self::assertSame('0.00', $this->lineOfType($calcLca, 'FIRM_INTERVENTION_FEE')->getTotalAmount());

        $missionPtg = $this->makeMission($instrumentist);
        $this->addIntervention($missionPtg, $ptg, $conmed, representativePresent: true);
        $calcPtg = $this->trackCalculation($this->service->calculate($missionPtg, $this->makeUser('ROLE_MANAGER')));
        self::assertSame('300.00', $this->lineOfType($calcPtg, 'FIRM_INTERVENTION_FEE')->getTotalAmount(), 'PTG a sa propre politique, non affectée par celle de LCA');
    }

    // ── 6. Plusieurs MissionIntervention dans une Mission — aucune contamination ──

    public function test_two_interventions_in_same_mission_never_contaminate_each_other(): void
    {
        $conmed = $this->makeFirm('ConMed');
        $sn = $this->makeFirm('Smith & Nephew');
        $lca = $this->makeType('LCA');
        $meniscal = $this->makeType('MENISCAL');
        $this->interventionRule($conmed, $lca, '150.00');
        $this->interventionRule($sn, $meniscal, '80.00');
        $this->addOffering($conmed, $lca, representativePresenceRelevant: true, suppressesInterventionFee: true);
        $this->addOffering($sn, $meniscal, representativePresenceRelevant: true, suppressesInterventionFee: true);

        $instrumentist = $this->standardInstrumentist();
        $mission = $this->makeMission($instrumentist);
        $this->addIntervention($mission, $lca, $conmed, representativePresent: true);   // ConMed délégué présent → neutralisé
        $this->addIntervention($mission, $meniscal, $sn, representativePresent: false); // SN délégué absent → normal

        $calculation = $this->trackCalculation($this->service->calculate($mission, $this->makeUser('ROLE_MANAGER')));

        $lcaLine = null; $meniscalLine = null;
        foreach ($calculation->getLines() as $l) {
            if ($l->getLineType()->value !== 'FIRM_INTERVENTION_FEE') continue;
            if (($l->getSnapshot()['interventionCodeSnapshot'] ?? null) === $lca->getCode()) $lcaLine = $l;
            if (($l->getSnapshot()['interventionCodeSnapshot'] ?? null) === $meniscal->getCode()) $meniscalLine = $l;
        }

        self::assertSame('0.00', $lcaLine->getTotalAmount());
        self::assertSame('80.00', $meniscalLine->getTotalAmount(), 'la neutralisation ConMed ne doit jamais affecter la ligne SN de la même mission');
    }

    // ── 7. Matériel volontairement non facturable ───────────────────────────

    public function test_not_billable_material_produces_no_line_and_no_warning(): void
    {
        $firm = $this->makeFirm('NotBillableFirm');
        $item = $this->makeItem($firm, MaterialBillingStatus::NOT_BILLABLE);

        $instrumentist = $this->standardInstrumentist();
        $mission = $this->makeMission($instrumentist);
        $this->addMaterialLine($mission, null, $item, '1.00');

        $calculation = $this->trackCalculation($this->service->calculate($mission, $this->makeUser('ROLE_MANAGER')));

        foreach ($calculation->getLines() as $l) {
            self::assertNotSame('FIRM_MATERIAL_FEE', $l->getLineType()->value, 'un matériel NOT_BILLABLE ne doit jamais générer de ligne');
        }
    }

    // ── 8. Matériel facturable sans tarif ───────────────────────────────────

    public function test_billable_material_without_rate_blocks_calculation(): void
    {
        $firm = $this->makeFirm('BillableNoRateFirm');
        $item = $this->makeItem($firm, MaterialBillingStatus::BILLABLE); // aucune PricingRule créée

        $instrumentist = $this->standardInstrumentist();
        $mission = $this->makeMission($instrumentist);
        $this->addMaterialLine($mission, null, $item, '1.00');

        try {
            $this->service->calculate($mission, $this->makeUser('ROLE_MANAGER'));
            self::fail('Devait lever FinancialCalculationAnomaliesException.');
        } catch (FinancialCalculationAnomaliesException $e) {
            $codes = array_map(static fn ($a) => $a->code, $e->getAnomalies());
            self::assertContains('MISSING_FIRM_MATERIAL_RATE', $codes);
        }
    }

    // ── 9. Tarif absent involontaire (UNSPECIFIED) — jamais un zéro valide ─

    public function test_unspecified_billing_status_without_rate_blocks_never_silently_zero(): void
    {
        $firm = $this->makeFirm('UnspecifiedFirm');
        $item = $this->makeItem($firm, MaterialBillingStatus::UNSPECIFIED); // défaut, aucune PricingRule

        $instrumentist = $this->standardInstrumentist();
        $mission = $this->makeMission($instrumentist);
        $this->addMaterialLine($mission, null, $item, '1.00');

        try {
            $this->service->calculate($mission, $this->makeUser('ROLE_MANAGER'));
            self::fail('Devait lever FinancialCalculationAnomaliesException — jamais un 0€ silencieux.');
        } catch (FinancialCalculationAnomaliesException $e) {
            $codes = array_map(static fn ($a) => $a->code, $e->getAnomalies());
            self::assertContains('MISSING_FIRM_MATERIAL_RATE', $codes);
        }

        self::assertSame([], $this->em->getRepository(FinancialCalculation::class)->findBy(['mission' => $mission]), 'aucune persistance partielle');
    }

    // ── §16 — feeApplicable=false : pas de forfait prévu, jamais un warning ─

    public function test_fee_not_applicable_produces_no_line_and_no_anomaly(): void
    {
        $firm = $this->makeFirm('NoFeeFirm');
        $type = $this->makeType('NOFEE');
        $this->addOffering($firm, $type, feeApplicable: false); // aucune PricingRule créée non plus

        $instrumentist = $this->standardInstrumentist();
        $mission = $this->makeMission($instrumentist);
        $this->addIntervention($mission, $type, $firm);

        $calculation = $this->trackCalculation($this->service->calculate($mission, $this->makeUser('ROLE_MANAGER')));

        foreach ($calculation->getLines() as $l) {
            self::assertNotSame('FIRM_INTERVENTION_FEE', $l->getLineType()->value, 'feeApplicable=false ne doit jamais générer de ligne à 0€');
        }
    }

    public function test_fee_applicable_true_without_rate_blocks_never_confused_with_no_fee(): void
    {
        $firm = $this->makeFirm('FeeApplicableNoRateFirm');
        $type = $this->makeType('FEEAPPLICABLE');
        $this->addOffering($firm, $type, feeApplicable: true); // pas de PricingRule créée

        $instrumentist = $this->standardInstrumentist();
        $mission = $this->makeMission($instrumentist);
        $this->addIntervention($mission, $type, $firm);

        try {
            $this->service->calculate($mission, $this->makeUser('ROLE_MANAGER'));
            self::fail('Devait lever FinancialCalculationAnomaliesException.');
        } catch (FinancialCalculationAnomaliesException $e) {
            $codes = array_map(static fn ($a) => $a->code, $e->getAnomalies());
            self::assertContains('MISSING_FIRM_INTERVENTION_RATE', $codes);
        }
    }

    // ── §10 — réponse obligatoire avant tout calcul si la question est pertinente ──

    public function test_missing_representative_presence_answer_blocks_calculation(): void
    {
        $conmed = $this->makeFirm('ConMed');
        $lca = $this->makeType('LCA');
        $this->interventionRule($conmed, $lca, '150.00');
        $this->addOffering($conmed, $lca, representativePresenceRelevant: true, suppressesInterventionFee: true);

        $instrumentist = $this->standardInstrumentist();
        $mission = $this->makeMission($instrumentist);
        $this->addIntervention($mission, $lca, $conmed, representativePresent: null); // jamais répondu

        try {
            $this->service->calculate($mission, $this->makeUser('ROLE_MANAGER'));
            self::fail('Devait lever FinancialCalculationAnomaliesException.');
        } catch (FinancialCalculationAnomaliesException $e) {
            $codes = array_map(static fn ($a) => $a->code, $e->getAnomalies());
            self::assertContains('MISSING_REPRESENTATIVE_PRESENCE_ANSWER', $codes);
        }
    }

    /**
     * §G de l'arbitrage — sans PricingRule applicable, la présence du délégué ne doit
     * JAMAIS transformer une absence de tarif en 0€ commercial valide.
     */
    public function test_representative_present_never_turns_a_missing_rate_into_a_valid_zero(): void
    {
        $conmed = $this->makeFirm('ConMed');
        $lca = $this->makeType('LCA');
        // Aucune PricingRule INTERVENTION_FEE créée pour ConMed × LCA.
        $this->addOffering($conmed, $lca, representativePresenceRelevant: true, suppressesInterventionFee: true);

        $instrumentist = $this->standardInstrumentist();
        $mission = $this->makeMission($instrumentist);
        $this->addIntervention($mission, $lca, $conmed, representativePresent: true);

        try {
            $this->service->calculate($mission, $this->makeUser('ROLE_MANAGER'));
            self::fail('Devait lever FinancialCalculationAnomaliesException — jamais un 0€ implicite.');
        } catch (FinancialCalculationAnomaliesException $e) {
            $codes = array_map(static fn ($a) => $a->code, $e->getAnomalies());
            self::assertContains('MISSING_FIRM_INTERVENTION_RATE', $codes);
        }
    }

    // ── §10 de l'arbitrage — historisation : un ancien calcul reste inchangé ───

    public function test_old_calculation_unaffected_by_later_policy_change(): void
    {
        $conmed = $this->makeFirm('ConMed');
        $lca = $this->makeType('LCA');
        $this->interventionRule($conmed, $lca, '150.00');
        $offering = $this->addOffering($conmed, $lca, representativePresenceRelevant: true, suppressesInterventionFee: true);

        $instrumentist = $this->standardInstrumentist();
        $mission = $this->makeMission($instrumentist);
        $this->addIntervention($mission, $lca, $conmed, representativePresent: true);

        $calculation = $this->trackCalculation($this->service->calculate($mission, $this->makeUser('ROLE_MANAGER')));
        $originalTotal = $this->lineOfType($calculation, 'FIRM_INTERVENTION_FEE')->getTotalAmount();
        self::assertSame('0.00', $originalTotal);

        // Le manager change la politique après coup.
        $offering->setRepresentativeSuppressesInterventionFee(false);
        $this->em->flush();

        $this->em->refresh($calculation);
        $stillSameLine = $this->lineOfType($calculation, 'FIRM_INTERVENTION_FEE');
        self::assertSame('0.00', $stillSameLine->getTotalAmount(), 'un calcul déjà figé ne doit jamais changer rétroactivement');
        self::assertSame('150.00', $stillSameLine->getGrossAmount());
    }

    // ── Architecture — RepresentativePolicyResolver ne fournit jamais de montant ────

    public function test_representative_policy_resolver_returns_defaults_when_no_offering_exists(): void
    {
        $firm = $this->makeFirm('NoOfferingFirm');
        $type = $this->makeType('NOOFFERING');
        $this->interventionRule($firm, $type, '99.00');

        $instrumentist = $this->standardInstrumentist();
        $mission = $this->makeMission($instrumentist);
        $this->addIntervention($mission, $type, $firm); // aucune FirmServiceOffering créée

        $calculation = $this->trackCalculation($this->service->calculate($mission, $this->makeUser('ROLE_MANAGER')));

        self::assertSame('99.00', $this->lineOfType($calculation, 'FIRM_INTERVENTION_FEE')->getTotalAmount(), 'sans FirmServiceOffering, le comportement doit être identique à avant ce lot');
    }
}
