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

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — §31 du lot : groupements par firme,
 * instrumentiste, chirurgien, intervention, matériel — snapshots historiques
 * préservés après renommage d'entité.
 */
final class FinancialStatisticsRankingServiceTest extends KernelTestCase
{
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
        $this->em->persist($interventionRule); $this->em->flush();
        $this->created['rules'][] = $interventionRule->getId();

        $materialRule = new PricingRule();
        $materialRule->setFirm($firm);
        $materialRule->setRuleType(PricingRuleType::MATERIAL_FEE);
        $materialRule->setMaterialItem($item);
        $materialRule->setUnitPrice($materialPrice);
        $this->em->persist($materialRule); $this->em->flush();
        $this->created['rules'][] = $materialRule->getId();

        $instrumentist ??= $this->makeUser('ROLE_INSTRUMENTIST');
        $rate = new InstrumentistRate();
        $rate->setInstrumentist($instrumentist);
        $rate->setRateType(InstrumentistRateType::HOURLY_RATE);
        $rate->setAmount($hourlyRate);
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
}
