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
use App\Service\FinancialStatisticsPdfExporter;
use App\Service\FinancialStatisticsQueryService;
use App\Service\PdfService;
use App\Enum\StatisticsGranularity;
use App\Exception\StatisticsExportException;
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

    // ── D-186 — export PDF : exhaustivité, ordre, totaux, volume ─────────

    private function exporter(int $pageSize = FinancialStatisticsPdfExporter::PAGE_SIZE, int $maxRows = FinancialStatisticsPdfExporter::MAX_ROWS): FinancialStatisticsPdfExporter
    {
        $c = self::getContainer();
        return new FinancialStatisticsPdfExporter($this->em, $c->get(PdfService::class), $c->get(FinancialStatisticsQueryService::class), $this->ranking, $pageSize, $maxRows);
    }

    /** @return string[] première cellule de chaque ligne du rapport, dans l'ordre */
    private function firstColumn(array $report): array
    {
        return array_map(static fn (array $row) => $row['cells'][0]['text'], $report['rows']);
    }

    /** Cinq chirurgiens au solde identique : le départage doit être stable d'une page à l'autre. */
    private function fiveTiedSurgeons(Firm $firm): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->makeApprovedMission($firm, '100.00', '0.00', '40.00', new \DateTimeImmutable('2026-05-1' . $i . ' 09:00:00'), 60);
        }
    }

    public function test_pagination_with_tied_sort_values_never_duplicates_or_skips_a_row(): void
    {
        $firm = $this->makeFirm();
        $this->fiveTiedSurgeons($firm);
        $f = $this->filter(firmId: $firm->getId());

        $all = array_map(static fn ($i) => $i->surgeonId, $this->ranking->bySurgeon($f, 1, 100, 'netBalance', 'DESC')['items']);
        $paged = [];
        for ($page = 1; $page <= 3; $page++) {
            foreach ($this->ranking->bySurgeon($f, $page, 2, 'netBalance', 'DESC')['items'] as $item) {
                $paged[] = $item->surgeonId;
            }
        }

        self::assertCount(5, array_unique($all));
        self::assertSame($all, $paged, 'pages 1 à 3 (2 lignes) = liste complète, même ordre, sans doublon ni trou.');
        $sorted = $all;
        sort($sorted);
        self::assertSame($sorted, $all, 'à valeurs égales, départage par identifiant croissant.');
    }

    public function test_export_reads_every_page_in_the_selected_sort_order(): void
    {
        $firm = $this->makeFirm();
        $this->fiveTiedSurgeons($firm);
        $this->makeApprovedMission($firm, '400.00', '0.00', '40.00', new \DateTimeImmutable('2026-05-20 09:00:00'), 60); // +360, en tête
        $f = $this->filter(firmId: $firm->getId());

        $api = $this->ranking->bySurgeon($f, 1, 100, 'netBalance', 'DESC');
        $report = $this->exporter(pageSize: 2)->report('by-surgeon', $f, StatisticsGranularity::DAY, 'netBalance', 'DESC');

        self::assertSame(6, $report['rowCount'], 'export > taille de page (2) : les 3 pages sont lues.');
        self::assertSame(array_map(static fn ($i) => $i->surgeonNameSnapshot, $api['items']), $this->firstColumn($report));
        self::assertCount(6, array_unique($this->firstColumn($report)));
        self::assertSame('+360,00 €', $report['rows'][0]['cells'][5]['text']);

        $asc = $this->exporter(pageSize: 2)->report('by-surgeon', $f, StatisticsGranularity::DAY, 'netBalance', 'ASC');
        self::assertSame(array_map(static fn ($i) => $i->surgeonNameSnapshot, $this->ranking->bySurgeon($f, 1, 100, 'netBalance', 'ASC')['items']), $this->firstColumn($asc));
    }

    public function test_export_amounts_and_totals_match_the_api_despite_pagination(): void
    {
        $firm = $this->makeFirm();
        $this->makeApprovedMission($firm, '200.00', '0.00', '40.00', new \DateTimeImmutable('2026-05-10 09:00:00'), 60); // +160
        $this->makeApprovedMission($firm, '50.00', '0.00', '60.00', new \DateTimeImmutable('2026-05-11 09:00:00'), 120); // −70
        $this->makeApprovedMission($firm, '100.00', '0.00', '0.00', new \DateTimeImmutable('2026-05-12 09:00:00'), 60); // +100, sans frais
        $f = $this->filter(firmId: $firm->getId());

        $api = $this->ranking->bySurgeon($f, 1, 1, 'generatedFirmRevenue', 'DESC'); // page écran de 1 ligne
        $report = $this->exporter(pageSize: 1)->report('by-surgeon', $f, StatisticsGranularity::DAY, 'generatedFirmRevenue', 'DESC');
        $eur = $this->totalsBy($api['totals'])['EUR'];

        self::assertSame(['350.00', '160.00', '190.00'], [$eur['generatedFirmRevenue'], $eur['generatedInstrumentistCompensation'], $eur['netBalance']]);
        self::assertSame(['+350,00 €', '−160,00 €', '+190,00 €'], array_map(static fn ($c) => $c['text'], $report['summaries'][0]['cards']));
        $total = $report['totalRows'][0]['cells'];
        self::assertSame(['TOTAL GÉNÉRAL', '+350,00 €', '−160,00 €', '+190,00 €'], [$total[0]['text'], $total[3]['text'], $total[4]['text'], $total[5]['text']]);
        self::assertSame(['pos', 'neg', 'pos'], [$total[3]['class'], $total[4]['class'], $total[5]['class']]);

        // Chaque ligne du PDF = la ligne API correspondante, signe appliqué une seule fois.
        $full = $this->ranking->bySurgeon($f, 1, 100, 'generatedFirmRevenue', 'DESC')['items'];
        foreach ($full as $k => $item) {
            $cells = $report['rows'][$k]['cells'];
            self::assertSame($item->surgeonNameSnapshot, $cells[0]['text']);
            self::assertSame('+' . number_format((float) $item->generatedFirmRevenue, 2, ',', ' ') . ' €', $cells[3]['text']);
            $fee = (float) $item->generatedInstrumentistCompensation;
            self::assertSame(($fee > 0 ? '−' : '') . number_format($fee, 2, ',', ' ') . ' €', $cells[4]['text']);
        }
        self::assertSame('−70,00 €', $report['rows'][2]['cells'][5]['text']);
        self::assertSame('neg', $report['rows'][2]['cells'][5]['class']);

        // Concordance dans le HTML réellement rendu : chaque ligne, dans l'ordre, puis le total.
        $html = self::getContainer()->get('twig')->render('pdf/financial_statistics.html.twig', $report);
        $pos = 0;
        foreach ($full as $item) {
            $next = strpos($html, $item->surgeonNameSnapshot, $pos);
            self::assertNotFalse($next, $item->surgeonNameSnapshot . ' absent ou hors ordre');
            $pos = $next;
        }
        self::assertGreaterThan($pos, strpos($html, 'TOTAL GÉNÉRAL'));
        self::assertStringContainsString('Du 01/05/2026 au 31/05/2026 inclus', $html);
        self::assertStringContainsString($firm->getName(), $html);
        self::assertStringContainsString('3 ligne(s) exportée(s)', $html);
    }

    public function test_export_of_intervention_view_keeps_mission_level_expenses_once(): void
    {
        $firm = $this->makeFirm();
        $this->makeApprovedMission($firm, '100.00', '30.00', '40.00', new \DateTimeImmutable('2026-05-14 09:00:00'), type: $this->makeType(), secondType: $this->makeType(), secondInterventionPrice: '80.00');
        $this->makeApprovedMission($firm, '50.00', '10.00', '60.00', new \DateTimeImmutable('2026-05-15 09:00:00'), 120);
        $f = $this->filter(firmId: $firm->getId());

        $api = $this->ranking->byIntervention($f, 1, 1, 'totalRevenue', 'DESC');
        $report = $this->exporter(pageSize: 1)->report('by-intervention', $f, StatisticsGranularity::DAY, 'totalRevenue', 'DESC');

        self::assertSame(3, $report['rowCount']);
        self::assertSame(3, $api['total']);
        self::assertSame(['+270,00 €', '−180,00 €', '+90,00 €'], array_map(static fn ($c) => $c['text'], $report['summaries'][0]['cards']), 'dépenses = 180 (mission à deux types comptée une fois).');
        self::assertSame('Total dépenses (au niveau mission)', $report['summaries'][0]['cards'][1]['label']);
        $labels = array_map(static fn ($c) => $c['label'], $report['columns']);
        self::assertNotContains('Frais instrumentiste', $labels);
        self::assertNotContains('Solde net', $labels, "aucun solde par intervention : la dépense n'est jamais imputée à un type.");
    }

    public function test_export_keeps_currencies_separate(): void
    {
        $firm = $this->makeFirm();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $this->makeApprovedMission($firm, '100.00', '0.00', '40.00', new \DateTimeImmutable('2026-05-10 09:00:00'), 60, surgeon: $surgeon);
        $this->makeApprovedMission($firm, '300.00', '0.00', '50.00', new \DateTimeImmutable('2026-05-11 09:00:00'), 60, surgeon: $surgeon, currency: 'USD');

        $report = $this->exporter(pageSize: 1)->report('by-surgeon', $this->filter(surgeonId: $surgeon->getId()), StatisticsGranularity::DAY, 'generatedFirmRevenue', 'DESC');

        self::assertSame(2, $report['rowCount']);
        self::assertSame(['EUR', 'USD'], array_map(static fn ($s) => $s['currency'], $report['summaries']));
        self::assertSame(['TOTAL GÉNÉRAL (EUR)', 'TOTAL GÉNÉRAL (USD)'], array_map(static fn ($r) => $r['cells'][0]['text'], $report['totalRows']));
        self::assertSame(['+60,00 €', '+250,00 USD'], array_map(static fn ($r) => $r['cells'][5]['text'], $report['totalRows']));
    }

    public function test_top_materials_export_covers_every_material_not_only_the_top_n(): void
    {
        $firm = $this->makeFirm();
        foreach (['10.00', '30.00', '20.00'] as $k => $price) {
            $this->makeApprovedMission($firm, '0.00', $price, '0.00', new \DateTimeImmutable('2026-05-1' . $k . ' 09:00:00'), 60);
        }
        $f = $this->filter(firmId: $firm->getId());

        $top1 = $this->ranking->topMaterials($f, 1, 'generatedRevenue', 'DESC');
        self::assertCount(1, $top1['items']);
        self::assertSame(3, $top1['groupCount']);

        $report = $this->exporter(pageSize: 2)->report('top-materials', $f, StatisticsGranularity::DAY, 'generatedRevenue', 'DESC');
        self::assertSame(3, $report['rowCount']);
        self::assertSame(['+30,00 €', '+20,00 €', '+10,00 €'], array_map(static fn ($r) => $r['cells'][5]['text'], $report['rows']));
        self::assertSame('+60,00 €', $report['totalRows'][0]['cells'][5]['text']);
    }

    public function test_empty_export_renders_a_pdf_without_rows_or_totals(): void
    {
        $f = $this->filter(firmId: $this->makeFirm()->getId());

        foreach (['by-firm', 'by-instrumentist', 'by-surgeon', 'by-intervention', 'top-materials'] as $view) {
            $sort = match ($view) {
                'by-instrumentist' => 'generatedCompensation', 'by-surgeon' => 'generatedFirmRevenue', 'by-intervention' => 'interventionRevenue', default => 'generatedRevenue',
            };
            $report = $this->exporter()->report($view, $f, StatisticsGranularity::DAY, $sort, 'DESC');
            self::assertSame(0, $report['rowCount'], $view);
            self::assertSame([], $report['totalRows'], $view);
        }
        $export = $this->exporter()->export('by-surgeon', $f, StatisticsGranularity::DAY, 'generatedFirmRevenue', 'DESC');
        self::assertStringStartsWith('%PDF-', $export['pdf']);
        self::assertSame(0, $export['rowCount']);
    }

    public function test_export_above_the_pdf_engine_ceiling_is_refused_explicitly(): void
    {
        $firm = $this->makeFirm();
        for ($i = 0; $i < 3; $i++) {
            $this->makeApprovedMission($firm, '100.00', '0.00', '40.00', new \DateTimeImmutable('2026-05-1' . $i . ' 09:00:00'), 60);
        }

        try {
            $this->exporter(pageSize: 1, maxRows: 2)->report('by-surgeon', $this->filter(firmId: $firm->getId()), StatisticsGranularity::DAY, 'netBalance', 'DESC');
            self::fail('un export au-delà du plafond doit être refusé, jamais tronqué.');
        } catch (StatisticsExportException $e) {
            self::assertSame(StatisticsExportException::TOO_LARGE, $e->getErrorCode());
            self::assertSame(422, $e->getStatusCode());
            self::assertSame(['rowCount' => 3, 'maxRows' => 2], $e->getExtra());
        }
    }

    public function test_export_is_refused_when_data_changes_between_pages(): void
    {
        $table = new \ReflectionMethod(FinancialStatisticsPdfExporter::class, 'table');
        $row = static fn (int $id) => (object) ['surgeonId' => $id, 'currency' => 'EUR', 'surgeonNameSnapshot' => 'S' . $id];
        $spec = [['Chirurgien', 'surgeonNameSnapshot', 'text']];
        $cases = [
            'totaux modifiés' => fn (int $page) => ['items' => [$row($page)], 'total' => 2, 'totals' => [['currency' => 'EUR', 'x' => $page === 1 ? '1.00' : '2.00']]],
            'ligne en double' => fn (int $page) => ['items' => [$row(1)], 'total' => 2, 'totals' => []],
            'ligne disparue' => fn (int $page) => ['items' => $page === 1 ? [$row(1)] : [], 'total' => 2, 'totals' => []],
        ];
        foreach ($cases as $label => $fetch) {
            try {
                $table->invoke($this->exporter(pageSize: 1), $fetch, 'surgeonId', $spec);
                self::fail($label);
            } catch (StatisticsExportException $e) {
                self::assertSame(StatisticsExportException::DATA_CHANGED, $e->getErrorCode(), $label);
                self::assertSame(409, $e->getStatusCode(), $label);
            }
        }
    }

    public function test_large_multi_page_export_repeats_headers_and_renders(): void
    {
        $f = new FinancialStatisticsFilter(from: new \DateTimeImmutable('2025-01-01'), to: new \DateTimeImmutable('2026-02-05'), firmId: $this->makeFirm()->getId());

        $report = $this->exporter()->report('timeseries', $f, StatisticsGranularity::DAY, '', 'DESC');
        self::assertSame(400, $report['rowCount'], 'un point par jour, aucun omis.');
        self::assertSame(400, array_sum(array_map('count', $report['pages'])));
        $lastPage = $report['pages'][array_key_last($report['pages'])];
        self::assertSame('01/01/2025', $report['pages'][0][0]['cells'][0]['text']);
        self::assertSame('04/02/2026', $lastPage[array_key_last($lastPage)]['cells'][0]['text']);

        $html = self::getContainer()->get('twig')->render('pdf/financial_statistics.html.twig', $report);
        self::assertSame(count($report['pages']), substr_count($html, '<thead>'), 'en-tête répété sur chaque page.');

        $export = $this->exporter()->export('timeseries', $f, StatisticsGranularity::DAY, '', 'DESC');
        self::assertStringStartsWith('%PDF-', $export['pdf']);
        self::assertGreaterThanOrEqual(count($report['pages']), preg_match_all('#/Type\s*/Page[^s]#', $export['pdf']));

        $this->expectException(StatisticsExportException::class);
        $this->exporter(maxRows: 399)->report('timeseries', $f, StatisticsGranularity::DAY, '', 'DESC');
    }
}
