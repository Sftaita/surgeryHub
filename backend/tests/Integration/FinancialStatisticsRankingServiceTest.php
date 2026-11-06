<?php

namespace App\Tests\Integration;

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
use App\Enum\InstrumentistRateType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PricingRuleType;
use App\Service\FinancialCalculationService;
use App\Service\FinancialStatisticsRankingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use App\Tests\Support\DeclaresActualHours;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — §31 du lot : groupements par firme,
 * instrumentiste, chirurgien, intervention, matériel — snapshots historiques
 * préservés après renommage d'entité.
 */
final class FinancialStatisticsRankingServiceTest extends KernelTestCase
{
    use DeclaresActualHours;

    private EntityManagerInterface $em;
    private FinancialCalculationService $calcService;
    private FinancialStatisticsRankingService $ranking;
    private array $created = [
        'calculations' => [], 'materialLines' => [], 'interventions' => [], 'missions' => [],
        'rates' => [], 'rules' => [], 'items' => [], 'types' => [], 'firms' => [], 'sites' => [], 'users' => [],
    ];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->calcService = self::getContainer()->get(FinancialCalculationService::class);
        $this->ranking = self::getContainer()->get(FinancialStatisticsRankingService::class);
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

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('fsrs-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $f->setName('FSRS-' . bin2hex(random_bytes(4)));
        $this->em->persist($f); $this->em->flush();
        $this->created['firms'][] = $f->getId();
        return $f;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('FSRS-Site-' . bin2hex(random_bytes(3)));
        $this->em->persist($h); $this->em->flush();
        $this->created['sites'][] = $h->getId();
        return $h;
    }

    /** @return array{0: Mission, 1: FinancialCalculation, 2: User, 3: User, 4: Firm, 5: User, 6: MaterialItem} */
    private function makeApprovedMission(
        Firm $firm,
        string $interventionPrice,
        string $materialPrice,
        string $hourlyRate,
        \DateTimeImmutable $missionDate,
        int $durationMinutes = 90,
        ?InterventionType $type = null,
        ?User $surgeon = null,
        ?User $instrumentist = null,
        string $currency = 'EUR',
        ?InterventionType $secondType = null,
        string $secondInterventionPrice = '0.00',
    ): array {
        $type ??= (function () {
            $t = new InterventionType();
            $t->setCode('FSRS-' . bin2hex(random_bytes(3)));
            $t->setLabel('FSRS Type');
            $this->em->persist($t); $this->em->flush();
            $this->created['types'][] = $t->getId();
            return $t;
        })();

        $item = new MaterialItem();
        $item->setFirm($firm);
        $item->setLabel('FSRS Item');
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

        $site = $this->makeSite();
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
        $this->declareActualHours($mission); // D-143 — heures réelles déclarées, jamais le seul planifié
        $this->created['missions'][] = $mission->getId();

        $intervention = new MissionIntervention();
        $intervention->setMission($mission);
        $intervention->setCode($type->getCode());
        $intervention->setLabel('FSRS Intervention');
        $intervention->setInterventionType($type);
        $intervention->setPrimaryFirm($firm);
        $this->em->persist($intervention); $this->em->flush();
        $this->created['interventions'][] = $intervention->getId();
        $mission->getInterventions()->add($intervention);

        if ($secondType !== null && $secondType !== $type) {
            $secondRule = new PricingRule();
            $secondRule->setFirm($firm);
            $secondRule->setRuleType(PricingRuleType::INTERVENTION_FEE);
            $secondRule->setInterventionType($secondType);
            $secondRule->setUnitPrice($secondInterventionPrice);
            $secondRule->setCurrency($currency);
            $this->em->persist($secondRule); $this->em->flush();
            $this->created['rules'][] = $secondRule->getId();
        }
        if ($secondType !== null) {
            $second = new MissionIntervention();
            $second->setMission($mission);
            $second->setCode($secondType->getCode());
            $second->setLabel('FSRS Intervention 2');
            $second->setInterventionType($secondType);
            $second->setPrimaryFirm($firm);
            $second->setOrderIndex(1);
            $this->em->persist($second); $this->em->flush();
            $this->created['interventions'][] = $second->getId();
            $mission->getInterventions()->add($second);
        }

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
        $calc = $this->calcService->approve($calc, $actor);

        return [$mission, $calc, $instrumentist, $surgeon, $firm, $actor, $item];
    }

    private function makeType(): InterventionType
    {
        $t = new InterventionType();
        $t->setCode('FSRS-' . bin2hex(random_bytes(3)));
        $t->setLabel('FSRS Type');
        $this->em->persist($t); $this->em->flush();
        $this->created['types'][] = $t->getId();
        return $t;
    }

    /** @param array<int, array<string, string>> $totals @return array<string, array<string, string>> */
    private function totalsBy(array $totals): array
    {
        $by = [];
        foreach ($totals as $t) { $by[$t['currency']] = $t; }
        return $by;
    }

    private function filter(?int $firmId = null, ?int $instrumentistId = null, ?int $surgeonId = null, ?int $interventionTypeId = null): FinancialStatisticsFilter
    {
        return new FinancialStatisticsFilter(
            from: new \DateTimeImmutable('2026-05-01'),
            to: new \DateTimeImmutable('2026-06-01'),
            surgeonId: $surgeonId,
            firmId: $firmId,
            instrumentistId: $instrumentistId,
            interventionTypeId: $interventionTypeId,
        );
    }

    // ── Par firme ─────────────────────────────────────────────────────────

    public function test_by_firm_groups_and_sums_revenue(): void
    {
        $firm = $this->makeFirm();
        $this->makeApprovedMission($firm, '200.00', '50.00', '40.00', new \DateTimeImmutable('2026-05-10 09:00:00'));

        $result = $this->ranking->byFirm($this->filter(firmId: $firm->getId()), 1, 20, 'generatedRevenue', 'DESC');

        self::assertCount(1, $result['items']);
        $item = $result['items'][0];
        self::assertSame($firm->getId(), $item->firmId);
        self::assertSame('200.00', $item->interventionRevenue);
        self::assertSame('50.00', $item->materialRevenue);
        self::assertSame('250.00', $item->generatedRevenue);
        self::assertSame(1, $item->missionCount);
    }

    public function test_by_firm_preserves_snapshot_after_firm_renamed(): void
    {
        $firm = $this->makeFirm();
        $originalName = $firm->getName();
        $this->makeApprovedMission($firm, '100.00', '0.00', '0.00', new \DateTimeImmutable('2026-05-11 09:00:00'));

        $firm->setName('RENAMED-' . bin2hex(random_bytes(3)));
        $this->em->flush();

        $result = $this->ranking->byFirm($this->filter(firmId: $firm->getId()), 1, 20, 'generatedRevenue', 'DESC');

        self::assertSame($originalName, $result['items'][0]->firmNameSnapshot, 'le libellé doit venir du snapshot au moment du calcul, jamais du nom actuel (§12 du lot).');
    }

    // ── Par instrumentiste ────────────────────────────────────────────────

    public function test_by_instrumentist_groups_hourly_and_consultation(): void
    {
        $firm = $this->makeFirm();
        [, , $instrumentist] = $this->makeApprovedMission($firm, '0.00', '0.00', '30.00', new \DateTimeImmutable('2026-05-12 09:00:00'), 120);

        $result = $this->ranking->byInstrumentist($this->filter(instrumentistId: $instrumentist->getId()), 1, 20, 'generatedCompensation', 'DESC');

        self::assertCount(1, $result['items']);
        $item = $result['items'][0];
        self::assertSame($instrumentist->getId(), $item->instrumentistId);
        self::assertSame(120, $item->executedMinutes);
        self::assertSame('60.00', $item->hourlyCompensation); // 2h * 30.00
        self::assertSame('0.00', $item->consultationFees);
    }

    // ── Par chirurgien ────────────────────────────────────────────────────

    public function test_by_surgeon_never_receives_paid_or_remaining_fields(): void
    {
        $firm = $this->makeFirm();
        [, , , $surgeon] = $this->makeApprovedMission($firm, '300.00', '0.00', '40.00', new \DateTimeImmutable('2026-05-13 09:00:00'));

        $result = $this->ranking->bySurgeon($this->filter(surgeonId: $surgeon->getId()), 1, 20, 'generatedFirmRevenue', 'DESC');

        self::assertCount(1, $result['items']);
        $item = $result['items'][0];
        self::assertSame($surgeon->getId(), $item->surgeonId);
        self::assertSame('300.00', $item->generatedFirmRevenue);
        self::assertFalse(property_exists($item, 'paidAmount'), 'le chirurgien est un axe analytique, jamais un bénéficiaire financier (§14 du lot).');
    }

    // ── Par intervention ──────────────────────────────────────────────────

    public function test_by_intervention_splits_intervention_and_material_revenue(): void
    {
        $firm = $this->makeFirm();
        $this->makeApprovedMission($firm, '150.00', '25.00', '0.00', new \DateTimeImmutable('2026-05-14 09:00:00'));

        $result = $this->ranking->byIntervention($this->filter(firmId: $firm->getId()), 1, 20, 'interventionRevenue', 'DESC');

        self::assertCount(1, $result['items']);
        $item = $result['items'][0];
        self::assertSame('150.00', $item->interventionRevenue);
        self::assertSame('25.00', $item->materialRevenue);
    }

    // ── Top matériels ─────────────────────────────────────────────────────

    public function test_top_materials_distinguishes_two_different_references_with_similar_names(): void
    {
        $firm = $this->makeFirm();

        // Chaque appel crée son propre InterventionType (jamais partagé) : deux
        // PricingRule INTERVENTION_FEE actives simultanément pour le même
        // (firm, interventionType) se chevaucheraient (D-072, refusé par
        // PricingRuleResolver) — hors du périmètre de ce test, qui ne porte que sur le
        // matériel.
        [, , , , , , $itemA] = $this->makeApprovedMission($firm, '0.00', '40.00', '0.00', new \DateTimeImmutable('2026-05-15 09:00:00'), 30);
        [, , , , , , $itemB] = $this->makeApprovedMission($firm, '0.00', '60.00', '0.00', new \DateTimeImmutable('2026-05-16 09:00:00'), 30);

        $result = $this->ranking->topMaterials($this->filter(firmId: $firm->getId()), 20, 'generatedRevenue', 'DESC');

        self::assertCount(2, $result['items'], 'deux MaterialItem distincts doivent produire deux lignes distinctes, jamais fusionnées par libellé.');
        $ids = array_map(static fn ($i) => $i->materialId, $result['items']);
        self::assertContains($itemA->getId(), $ids);
        self::assertContains($itemB->getId(), $ids);
    }

    // ── D-186 — recettes (+), dépenses (−), solde net ────────────────────

    public function test_by_surgeon_net_balance_positive_when_revenue_exceeds_fees(): void
    {
        $firm = $this->makeFirm();
        // 200 + 50 de recettes, 90 min × 40 = 60 de frais
        [, , , $surgeon] = $this->makeApprovedMission($firm, '200.00', '50.00', '40.00', new \DateTimeImmutable('2026-05-10 09:00:00'));

        $result = $this->ranking->bySurgeon($this->filter(surgeonId: $surgeon->getId()), 1, 20, 'netBalance', 'DESC');

        $item = $result['items'][0];
        self::assertSame('250.00', $item->generatedFirmRevenue);
        self::assertSame('60.00', $item->generatedInstrumentistCompensation, 'les frais restent une magnitude positive : le signe n\'est appliqué qu\'une fois, à l\'affichage.');
        self::assertSame('190.00', $item->netBalance);
    }

    public function test_by_surgeon_net_balance_negative_when_fees_exceed_revenue(): void
    {
        $firm = $this->makeFirm();
        // 50 de recettes, 120 min × 60 = 120 de frais
        [, , , $surgeon] = $this->makeApprovedMission($firm, '50.00', '0.00', '60.00', new \DateTimeImmutable('2026-05-10 09:00:00'), 120);

        $result = $this->ranking->bySurgeon($this->filter(surgeonId: $surgeon->getId()), 1, 20, 'netBalance', 'DESC');

        self::assertSame('-70.00', $result['items'][0]->netBalance);
        self::assertSame('-70.00', $this->totalsBy($result['totals'])['EUR']['netBalance']);
    }

    public function test_by_surgeon_without_instrumentist_fees_has_net_equal_to_revenue(): void
    {
        $firm = $this->makeFirm();
        [, , , $surgeon] = $this->makeApprovedMission($firm, '300.00', '0.00', '0.00', new \DateTimeImmutable('2026-05-13 09:00:00'));

        $item = $this->ranking->bySurgeon($this->filter(surgeonId: $surgeon->getId()), 1, 20, 'generatedFirmRevenue', 'DESC')['items'][0];

        self::assertSame('0.00', $item->generatedInstrumentistCompensation);
        self::assertSame('300.00', $item->netBalance);
    }

    public function test_totals_cover_every_filtered_row_even_when_paginated(): void
    {
        $firm = $this->makeFirm();
        $this->makeApprovedMission($firm, '200.00', '0.00', '40.00', new \DateTimeImmutable('2026-05-10 09:00:00'));
        $this->makeApprovedMission($firm, '100.00', '0.00', '20.00', new \DateTimeImmutable('2026-05-11 09:00:00'));

        $result = $this->ranking->bySurgeon($this->filter(firmId: $firm->getId()), 1, 1, 'generatedFirmRevenue', 'DESC');

        self::assertCount(1, $result['items'], 'une seule ligne par page');
        self::assertSame(2, $result['total']);
        $eur = $this->totalsBy($result['totals'])['EUR'];
        self::assertSame('300.00', $eur['generatedFirmRevenue'], 'le total couvre les deux chirurgiens, pas la seule page affichée.');
        self::assertSame('90.00', $eur['generatedInstrumentistCompensation']); // 60 + 30
        self::assertSame('210.00', $eur['netBalance']);
    }

    public function test_sorting_by_net_balance_orders_rows(): void
    {
        $firm = $this->makeFirm();
        [, , , $low] = $this->makeApprovedMission($firm, '50.00', '0.00', '60.00', new \DateTimeImmutable('2026-05-10 09:00:00'), 120); // −70
        [, , , $high] = $this->makeApprovedMission($firm, '200.00', '0.00', '40.00', new \DateTimeImmutable('2026-05-11 09:00:00')); // +140

        $asc = $this->ranking->bySurgeon($this->filter(firmId: $firm->getId()), 1, 20, 'netBalance', 'ASC')['items'];
        self::assertSame([$low->getId(), $high->getId()], array_map(static fn ($i) => $i->surgeonId, $asc));
    }

    public function test_multiple_currencies_are_never_mixed_in_totals(): void
    {
        $firm = $this->makeFirm();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $this->makeApprovedMission($firm, '100.00', '0.00', '40.00', new \DateTimeImmutable('2026-05-10 09:00:00'), 60, surgeon: $surgeon);
        $this->makeApprovedMission($firm, '300.00', '0.00', '50.00', new \DateTimeImmutable('2026-05-11 09:00:00'), 60, surgeon: $surgeon, currency: 'USD');

        $result = $this->ranking->bySurgeon($this->filter(surgeonId: $surgeon->getId()), 1, 20, 'generatedFirmRevenue', 'DESC');

        self::assertCount(2, $result['items'], 'une ligne par devise');
        $totals = $this->totalsBy($result['totals']);
        self::assertSame(['EUR', 'USD'], array_keys($totals));
        self::assertSame('60.00', $totals['EUR']['netBalance']);
        self::assertSame('250.00', $totals['USD']['netBalance']);
    }

    public function test_by_intervention_never_imputes_instrumentist_pay_to_an_intervention(): void
    {
        $firm = $this->makeFirm();
        $primary = $this->makeType();
        $secondary = $this->makeType();
        // 2 interventions (100 + 80), matériel 30 rattaché à la primaire, frais mission 90 min × 40 = 60
        $this->makeApprovedMission($firm, '100.00', '30.00', '40.00', new \DateTimeImmutable('2026-05-14 09:00:00'), type: $primary, secondType: $secondary, secondInterventionPrice: '80.00');

        $result = $this->ranking->byIntervention($this->filter(firmId: $firm->getId()), 1, 20, 'interventionRevenue', 'DESC');

        $byType = [];
        foreach ($result['items'] as $i) { $byType[$i->interventionTypeId ?? 'null'] = $i; }
        self::assertSame([$primary->getId(), $secondary->getId()], array_keys($byType), 'aucun bucket « Non ventilé » : seulement les deux types.');
        self::assertFalse(property_exists($byType[$primary->getId()], 'instrumentistCompensation'), 'la rémunération appartient à la mission, jamais à une intervention.');
        self::assertFalse(property_exists($byType[$primary->getId()], 'netBalance'));
        self::assertSame('130.00', $byType[$primary->getId()]->totalRevenue);
        self::assertSame('80.00', $byType[$secondary->getId()]->totalRevenue);
        self::assertSame(1, $byType[$primary->getId()]->interventionCount);
        self::assertSame('130.00', $byType[$primary->getId()]->averageRevenuePerIntervention);
        // Indicateur contextuel : la mission entière (60) dans CHAQUE groupe auquel elle appartient…
        self::assertSame('60.00', $byType[$primary->getId()]->averageAssociatedMissionCost);
        self::assertSame('60.00', $byType[$secondary->getId()]->averageAssociatedMissionCost);

        // …mais comptée une seule fois dans les totaux.
        $eur = $this->totalsBy($result['totals'])['EUR'];
        self::assertSame('210.00', $eur['totalRevenue'], 'interventions + matériel, chaque ligne comptée une seule fois.');
        self::assertSame('60.00', $eur['missionInstrumentistCompensation']);
        self::assertSame('150.00', $eur['netBalance']);
    }

    public function test_by_intervention_counts_interventions_and_averages_associated_mission_cost(): void
    {
        $type = $this->makeType();
        // Une firme par mission : deux PricingRule actives sur le même (firme, type) se chevaucheraient (D-072).
        $this->makeApprovedMission($this->makeFirm(), '150.00', '25.00', '40.00', new \DateTimeImmutable('2026-05-14 09:00:00'), type: $type); // coût 60
        $this->makeApprovedMission($this->makeFirm(), '150.00', '0.00', '20.00', new \DateTimeImmutable('2026-05-15 09:00:00'), type: $type, secondType: $type, secondInterventionPrice: '150.00'); // 2 × même type, coût 30

        $items = $this->ranking->byIntervention($this->filter(interventionTypeId: $type->getId()), 1, 20, 'interventionRevenue', 'DESC')['items'];
        $item = array_values(array_filter($items, static fn ($i) => $i->interventionTypeId === $type->getId()))[0];

        self::assertSame(3, $item->interventionCount);
        self::assertSame(2, $item->missionCount);
        self::assertSame('475.00', $item->totalRevenue); // 150 + 25 + 150 + 150
        self::assertSame('158.33', $item->averageRevenuePerIntervention);
        self::assertSame('45.00', $item->averageAssociatedMissionCost, '(60 + 30) / 2 missions : la mission à deux interventions du même type compte une fois.');
    }

    public function test_by_intervention_keeps_unlinked_material_in_its_own_line(): void
    {
        $firm = $this->makeFirm();
        [$mission] = $this->makeApprovedMission($firm, '100.00', '0.00', '0.00', new \DateTimeImmutable('2026-05-14 09:00:00'));
        $line = $mission->getMaterialLines()->first();
        self::assertNotFalse($line);

        // Même calcul, matériel détaché de son intervention : la ligne dédiée l'expose, sans dépense.
        $this->em->getConnection()->executeStatement('UPDATE material_line SET mission_intervention_id = NULL WHERE id = ?', [$line->getId()]);
        $result = $this->ranking->byIntervention($this->filter(firmId: $firm->getId()), 1, 20, 'interventionRevenue', 'DESC');

        $unlinked = array_values(array_filter($result['items'], static fn ($i) => $i->interventionTypeId === null));
        self::assertCount(1, $unlinked);
        self::assertSame('Matériel non rattaché à une intervention', $unlinked[0]->interventionNameSnapshot);
        self::assertNull($unlinked[0]->averageAssociatedMissionCost);
    }

    public function test_same_missions_reconcile_across_every_analytic_view(): void
    {
        $firm = $this->makeFirm();
        $this->makeApprovedMission($firm, '100.00', '30.00', '40.00', new \DateTimeImmutable('2026-05-14 09:00:00'), type: $this->makeType(), secondType: $this->makeType(), secondInterventionPrice: '80.00');
        $this->makeApprovedMission($firm, '50.00', '10.00', '60.00', new \DateTimeImmutable('2026-05-15 09:00:00'), 120);
        $f = $this->filter(firmId: $firm->getId());

        $firmT = $this->totalsBy($this->ranking->byFirm($f, 1, 20, 'generatedRevenue', 'DESC')['totals'])['EUR'];
        $surgT = $this->totalsBy($this->ranking->bySurgeon($f, 1, 20, 'generatedFirmRevenue', 'DESC')['totals'])['EUR'];
        $intT = $this->totalsBy($this->ranking->byIntervention($f, 1, 20, 'interventionRevenue', 'DESC')['totals'])['EUR'];
        $instT = $this->totalsBy($this->ranking->byInstrumentist($f, 1, 20, 'generatedCompensation', 'DESC')['totals'])['EUR'];
        $matT = $this->totalsBy($this->ranking->topMaterials($f, 1, 'generatedRevenue', 'DESC')['totals'])['EUR'];

        self::assertSame('270.00', $firmT['generatedRevenue']); // 100 + 80 + 30 + 50 + 10
        self::assertSame($firmT['generatedRevenue'], $surgT['generatedFirmRevenue']);
        self::assertSame($firmT['generatedRevenue'], $intT['totalRevenue']);
        self::assertSame($firmT['materialRevenue'], $intT['materialRevenue']);
        self::assertSame('40.00', $matT['generatedRevenue'], 'le total matériel couvre tous les matériels, pas seulement le top 1 affiché.');
        self::assertSame('180.00', $instT['generatedCompensation']); // 60 + 120
        self::assertSame($instT['generatedCompensation'], $surgT['generatedInstrumentistCompensation']);
        self::assertSame($instT['generatedCompensation'], $intT['missionInstrumentistCompensation'], 'la mission à deux types n\'est jamais comptée deux fois.');
        self::assertSame('90.00', $surgT['netBalance']);
        self::assertSame($surgT['netBalance'], $intT['netBalance']);
    }
}
