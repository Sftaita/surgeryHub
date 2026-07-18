<?php

namespace App\Tests\Integration;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\FinancialCalculation;
use App\Entity\FinancialCalculationLine;
use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\MissionIntervention;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\FinancialCalculationStatus;
use App\Enum\InstrumentistRateType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PricingRuleType;
use App\Exception\FinancialCalculationAnomaliesException;
use App\Exception\FinancialCalculationIneligibleException;
use App\Service\FinancialCalculationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * EPIC Exécution & Valorisation, Lot 3 (D-073) — §28 du lot : domaine/calcul, tarifs
 * manquants, append-only, workflow. Appels réels contre une base réelle (KernelTestCase),
 * jamais de mock de FinancialCalculationService (final, voir son docblock).
 */
final class FinancialCalculationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FinancialCalculationService $service;
    private array $created = [
        'calculations' => [], 'lines' => [], 'executions' => [], 'materialLines' => [],
        'interventions' => [], 'missions' => [], 'rates' => [], 'rules' => [],
        'items' => [], 'types' => [], 'firms' => [], 'sites' => [], 'users' => [],
    ];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(FinancialCalculationService::class);
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
            // Casse le self-lien superseded_by_calculation_id avant suppression.
            foreach ($this->created['calculations'] as $id) {
                $e = $this->em->find(FinancialCalculation::class, $id);
                if ($e) { $e->setSupersededByCalculation(null); }
            }
            $this->em->flush();
            foreach ($this->created['calculations'] as $id) { $e = $this->em->find(FinancialCalculation::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['executions'] as $id) { $e = $this->em->find(MissionExecution::class, $id); if ($e) $this->em->remove($e); }
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
        $u->setEmail('fc-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $h->setName('FC-' . bin2hex(random_bytes(3)));
        $this->em->persist($h); $this->em->flush();
        $this->created['sites'][] = $h->getId();
        return $h;
    }

    private function makeMission(
        MissionType $type,
        \DateTimeImmutable $startAt,
        \DateTimeImmutable $endAt,
        ?User $instrumentist,
        MissionStatus $status = MissionStatus::VALIDATED,
    ): Mission {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');

        $m = new Mission();
        $m->setType($type);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($surgeon);
        $m->setStartAt($startAt);
        $m->setEndAt($endAt);
        $m->setStatus($status);
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        $this->em->persist($m); $this->em->flush();
        $this->created['missions'][] = $m->getId();
        return $m;
    }

    private function addIntervention(Mission $mission, ?InterventionType $type, ?Firm $primaryFirm): MissionIntervention
    {
        $i = new MissionIntervention();
        $i->setMission($mission);
        $i->setCode($type?->getCode() ?? 'LEGACY');
        $i->setLabel($type?->getLabel() ?? 'Legacy intervention');
        $i->setInterventionType($type);
        $i->setPrimaryFirm($primaryFirm);
        $this->em->persist($i); $this->em->flush();
        $this->created['interventions'][] = $i->getId();
        $mission->getInterventions()->add($i);
        return $i;
    }

    private function addMaterialLine(Mission $mission, MissionIntervention $intervention, MaterialItem $item, string $quantity): MaterialLine
    {
        $l = new MaterialLine();
        $l->setMission($mission);
        $l->setMissionIntervention($intervention);
        $l->setItem($item);
        $l->setQuantity($quantity);
        $l->setCreatedBy($mission->getSurgeon());
        $this->em->persist($l); $this->em->flush();
        $this->created['materialLines'][] = $l->getId();
        $mission->getMaterialLines()->add($l);
        return $l;
    }

    private function addExecutionWithDuration(Mission $mission, int $minutes): MissionExecution
    {
        $e = new MissionExecution();
        $e->setMission($mission);
        $e->setActualDurationMinutes($minutes);
        $this->em->persist($e); $this->em->flush();
        $this->created['executions'][] = $e->getId();
        $mission->setExecution($e);
        return $e;
    }

    private function addExecutionWithActualStart(Mission $mission, \DateTimeImmutable $actualStartAt): MissionExecution
    {
        $e = new MissionExecution();
        $e->setMission($mission);
        $e->setActualStartAt($actualStartAt);
        $e->setActualEndAt($actualStartAt->modify('+1 hour'));
        $this->em->persist($e); $this->em->flush();
        $this->created['executions'][] = $e->getId();
        $mission->setExecution($e);
        return $e;
    }

    private function interventionRule(Firm $firm, InterventionType $type, string $price, string $currency = 'EUR'): PricingRule
    {
        $r = new PricingRule();
        $r->setFirm($firm);
        $r->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $r->setInterventionType($type);
        $r->setUnitPrice($price);
        $r->setCurrency($currency);
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

    private function hourlyRate(User $instrumentist, string $amount, \DateTimeImmutable $validFrom): InstrumentistRate
    {
        $r = new InstrumentistRate();
        $r->setInstrumentist($instrumentist);
        $r->setRateType(InstrumentistRateType::HOURLY_RATE);
        $r->setAmount($amount);
        $r->setCurrency('EUR');
        $r->setValidFrom($validFrom);
        $this->em->persist($r); $this->em->flush();
        $this->created['rates'][] = $r->getId();
        return $r;
    }

    private function consultationRate(User $instrumentist, string $amount, \DateTimeImmutable $validFrom): InstrumentistRate
    {
        $r = new InstrumentistRate();
        $r->setInstrumentist($instrumentist);
        $r->setRateType(InstrumentistRateType::CONSULTATION_FEE);
        $r->setAmount($amount);
        $r->setCurrency('EUR');
        $r->setValidFrom($validFrom);
        $this->em->persist($r); $this->em->flush();
        $this->created['rates'][] = $r->getId();
        return $r;
    }

    private function trackCalculation(FinancialCalculation $c): FinancialCalculation
    {
        $this->created['calculations'][] = $c->getId();
        foreach ($c->getLines() as $l) {
            $this->created['lines'][] = $l->getId();
        }
        return $c;
    }

    // ── Domaine / calcul ──────────────────────────────────────────────────

    public function test_calculate_produces_firm_and_instrumentist_lines_across_two_firms(): void
    {
        $firmA = $this->makeFirm('FirmA');
        $firmB = $this->makeFirm('FirmB');
        $type = $this->makeType('LCA');
        $item = $this->makeItem($firmB);
        $this->interventionRule($firmA, $type, '180.00');
        $this->materialRule($firmB, $item, '40.00');

        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '45.00', new \DateTimeImmutable('2020-01-01'));
        $actor = $this->makeUser('ROLE_MANAGER');

        $mission = $this->makeMission(
            MissionType::BLOCK,
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            new \DateTimeImmutable('2026-06-01 10:00:00'),
            $instrumentist,
        );
        $intervention = $this->addIntervention($mission, $type, $firmA);
        $this->addMaterialLine($mission, $intervention, $item, '4.00');

        $calculation = $this->trackCalculation($this->service->calculate($mission, $actor));

        self::assertSame(1, $calculation->getVersion());
        self::assertSame(FinancialCalculationStatus::CALCULATED, $calculation->getStatus());
        self::assertSame($actor->getId(), $calculation->getCalculatedBy()?->getId());
        self::assertNotNull($calculation->getCalculatedAt());
        self::assertSame('2026-06-01', $calculation->getEffectiveAt()?->format('Y-m-d'), 'sans MissionExecution, effectiveAt = planifié');
        self::assertCount(3, $calculation->getLines());

        $totals = $calculation->totalsByCurrency();
        self::assertSame('340.00', $totals['EUR']['FIRM']);
        self::assertSame('90.00', $totals['EUR']['INSTRUMENTIST']);

        self::assertSame(
            [AuditEventType::FINANCIAL_CALCULATION_CREATED->value],
            array_map(static fn (AuditEvent $e) => $e->getEventType()->value, $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $mission])),
        );
    }

    public function test_consultation_mission_produces_consultation_fee_not_hourly(): void
    {
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->consultationRate($instrumentist, '35.00', new \DateTimeImmutable('2020-01-01'));
        $actor = $this->makeUser('ROLE_MANAGER');

        $mission = $this->makeMission(
            MissionType::CONSULTATION,
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            new \DateTimeImmutable('2026-06-01 08:30:00'),
            $instrumentist,
        );

        $calculation = $this->trackCalculation($this->service->calculate($mission, $actor));

        self::assertCount(1, $calculation->getLines());
        $line = $calculation->getLines()->first();
        self::assertSame('INSTRUMENTIST_CONSULTATION_FEE', $line->getLineType()->value);
        self::assertSame('35.00', $line->getTotalAmount());
        self::assertNull($line->getDurationMinutes());
    }

    public function test_effective_at_uses_actual_start_when_execution_exists(): void
    {
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '40.00', new \DateTimeImmutable('2020-01-01'));
        $actor = $this->makeUser('ROLE_MANAGER');

        $mission = $this->makeMission(
            MissionType::BLOCK,
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            new \DateTimeImmutable('2026-06-01 10:00:00'),
            $instrumentist,
        );
        $this->addExecutionWithActualStart($mission, new \DateTimeImmutable('2026-06-03 09:00:00'));

        $calculation = $this->trackCalculation($this->service->calculate($mission, $actor));

        self::assertSame('2026-06-03', $calculation->getEffectiveAt()?->format('Y-m-d'), 'horaires réels priment sur le planifié');
    }

    public function test_resolve_effective_at_is_deterministic_never_now(): void
    {
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        // Tarif valable uniquement en 2021 — si resolveEffectiveAt() lisait now() (2026),
        // ce tarif ne résoudrait jamais et le calcul échouerait avec une anomalie.
        $rate = new InstrumentistRate();
        $rate->setInstrumentist($instrumentist);
        $rate->setRateType(InstrumentistRateType::HOURLY_RATE);
        $rate->setAmount('30.00');
        $rate->setCurrency('EUR');
        $rate->setValidFrom(new \DateTimeImmutable('2021-01-01'));
        $rate->setValidTo(new \DateTimeImmutable('2021-12-31'));
        $this->em->persist($rate); $this->em->flush();
        $this->created['rates'][] = $rate->getId();

        $actor = $this->makeUser('ROLE_MANAGER');
        $mission = $this->makeMission(
            MissionType::BLOCK,
            new \DateTimeImmutable('2021-06-15 08:00:00'),
            new \DateTimeImmutable('2021-06-15 09:00:00'),
            $instrumentist,
        );

        $calculation = $this->trackCalculation($this->service->calculate($mission, $actor));

        self::assertSame('30.00', $calculation->getLines()->first()->getTotalAmount());
    }

    public function test_snapshot_captures_firm_and_intervention_labels(): void
    {
        $firm = $this->makeFirm('SnapshotFirm');
        $type = $this->makeType('EPAULE');
        $this->interventionRule($firm, $type, '200.00');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '40.00', new \DateTimeImmutable('2020-01-01'));
        $actor = $this->makeUser('ROLE_MANAGER');

        $mission = $this->makeMission(
            MissionType::BLOCK,
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            new \DateTimeImmutable('2026-06-01 09:00:00'),
            $instrumentist,
        );
        $this->addIntervention($mission, $type, $firm);

        $calculation = $this->trackCalculation($this->service->calculate($mission, $actor));

        $firmLine = null;
        foreach ($calculation->getLines() as $l) {
            if ($l->getLineType()->value === 'FIRM_INTERVENTION_FEE') { $firmLine = $l; }
        }
        self::assertNotNull($firmLine);
        $snapshot = $firmLine->getSnapshot();
        self::assertSame($firm->getId(), $snapshot['firmId']);
        self::assertStringContainsString('SnapshotFirm', $snapshot['firmNameSnapshot']);
        self::assertSame($type->getId(), $snapshot['interventionTypeId']);
        self::assertArrayHasKey('pricingRuleId', $snapshot);
    }

    public function test_totals_by_currency_never_sums_across_currencies(): void
    {
        $firmEur = $this->makeFirm('EurFirm');
        $firmUsd = $this->makeFirm('UsdFirm');
        $typeEur = $this->makeType('EUR-TYPE');
        $typeUsd = $this->makeType('USD-TYPE');
        $this->interventionRule($firmEur, $typeEur, '100.00', 'EUR');
        $this->interventionRule($firmUsd, $typeUsd, '120.00', 'USD');

        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '10.00', new \DateTimeImmutable('2020-01-01'));
        $actor = $this->makeUser('ROLE_MANAGER');

        $mission = $this->makeMission(
            MissionType::BLOCK,
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            new \DateTimeImmutable('2026-06-01 09:00:00'),
            $instrumentist,
        );
        $this->addIntervention($mission, $typeEur, $firmEur);
        $this->addIntervention($mission, $typeUsd, $firmUsd);

        $calculation = $this->trackCalculation($this->service->calculate($mission, $actor));

        $totals = $calculation->totalsByCurrency();
        self::assertSame('100.00', $totals['EUR']['FIRM']);
        self::assertSame('120.00', $totals['USD']['FIRM']);
        self::assertSame('10.00', $totals['EUR']['INSTRUMENTIST']);
        self::assertArrayNotHasKey('INSTRUMENTIST', $totals['USD']);
    }

    /** @dataProvider provideRoundingCases */
    public function test_hourly_rounding_policy(int $minutes, string $rate, string $expectedQuantity, string $expectedTotal): void
    {
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, $rate, new \DateTimeImmutable('2020-01-01'));
        $actor = $this->makeUser('ROLE_MANAGER');

        $mission = $this->makeMission(
            MissionType::BLOCK,
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            new \DateTimeImmutable('2026-06-01 08:01:00'), // planifié non pertinent, l'exécution explicite prime
            $instrumentist,
        );
        $this->addExecutionWithDuration($mission, $minutes);

        $calculation = $this->trackCalculation($this->service->calculate($mission, $actor));

        $line = $calculation->getLines()->first();
        self::assertSame($expectedQuantity, $line->getQuantity());
        self::assertSame($expectedTotal, $line->getTotalAmount());
        self::assertSame($minutes, $line->getDurationMinutes());
    }

    public static function provideRoundingCases(): array
    {
        return [
            '1 minute' => [1, '48.00', '0.0167', '0.80'],
            '30 minutes' => [30, '48.00', '0.5000', '24.00'],
            '90 minutes' => [90, '48.00', '1.5000', '72.00'],
            'tarif non divisible par 60 (40min, 47.00)' => [40, '47.00', '0.6667', '31.33'],
        ];
    }

    // ── Tarifs manquants (§14) ────────────────────────────────────────────

    public function test_missing_primary_firm_produces_anomaly_without_persisting(): void
    {
        $type = $this->makeType('SANS-FIRME');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '40.00', new \DateTimeImmutable('2020-01-01'));
        $actor = $this->makeUser('ROLE_MANAGER');

        $mission = $this->makeMission(
            MissionType::BLOCK,
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            new \DateTimeImmutable('2026-06-01 09:00:00'),
            $instrumentist,
        );
        $this->addIntervention($mission, $type, null);

        try {
            $this->service->calculate($mission, $actor);
            self::fail('Devait lever FinancialCalculationAnomaliesException.');
        } catch (FinancialCalculationAnomaliesException $e) {
            $codes = array_map(static fn ($a) => $a->code, $e->getAnomalies());
            self::assertContains('MISSING_PRIMARY_FIRM', $codes);
        }

        self::assertSame([], $this->em->getRepository(FinancialCalculation::class)->findBy(['mission' => $mission]), 'aucune persistance partielle');
    }

    public function test_missing_intervention_type_legacy_row_produces_anomaly(): void
    {
        $firm = $this->makeFirm('LegacyFirm');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '40.00', new \DateTimeImmutable('2020-01-01'));
        $actor = $this->makeUser('ROLE_MANAGER');

        $mission = $this->makeMission(
            MissionType::BLOCK,
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            new \DateTimeImmutable('2026-06-01 09:00:00'),
            $instrumentist,
        );
        $this->addIntervention($mission, null, $firm);

        try {
            $this->service->calculate($mission, $actor);
            self::fail('Devait lever FinancialCalculationAnomaliesException.');
        } catch (FinancialCalculationAnomaliesException $e) {
            $codes = array_map(static fn ($a) => $a->code, $e->getAnomalies());
            self::assertContains('MISSING_INTERVENTION_TYPE', $codes);
        }
    }

    public function test_missing_firm_intervention_rate_produces_anomaly(): void
    {
        $firm = $this->makeFirm('NoRateFirm');
        $type = $this->makeType('NORATE');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '40.00', new \DateTimeImmutable('2020-01-01'));
        $actor = $this->makeUser('ROLE_MANAGER');

        $mission = $this->makeMission(
            MissionType::BLOCK,
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            new \DateTimeImmutable('2026-06-01 09:00:00'),
            $instrumentist,
        );
        $this->addIntervention($mission, $type, $firm); // aucune PricingRule créée

        try {
            $this->service->calculate($mission, $actor);
            self::fail('Devait lever FinancialCalculationAnomaliesException.');
        } catch (FinancialCalculationAnomaliesException $e) {
            $codes = array_map(static fn ($a) => $a->code, $e->getAnomalies());
            self::assertContains('MISSING_FIRM_INTERVENTION_RATE', $codes);
        }
    }

    public function test_missing_material_rate_produces_anomaly(): void
    {
        $firm = $this->makeFirm('NoMaterialRateFirm');
        $item = $this->makeItem($firm); // aucune PricingRule MATERIAL_FEE créée
        $type = $this->makeType('WITHMAT');
        $this->interventionRule($firm, $type, '100.00');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '40.00', new \DateTimeImmutable('2020-01-01'));
        $actor = $this->makeUser('ROLE_MANAGER');

        $mission = $this->makeMission(
            MissionType::BLOCK,
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            new \DateTimeImmutable('2026-06-01 09:00:00'),
            $instrumentist,
        );
        $intervention = $this->addIntervention($mission, $type, $firm);
        $this->addMaterialLine($mission, $intervention, $item, '2.00');

        try {
            $this->service->calculate($mission, $actor);
            self::fail('Devait lever FinancialCalculationAnomaliesException.');
        } catch (FinancialCalculationAnomaliesException $e) {
            $codes = array_map(static fn ($a) => $a->code, $e->getAnomalies());
            self::assertContains('MISSING_FIRM_MATERIAL_RATE', $codes);
        }
    }

    public function test_missing_instrumentist_rate_produces_anomaly(): void
    {
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST'); // aucun tarif créé
        $actor = $this->makeUser('ROLE_MANAGER');

        $mission = $this->makeMission(
            MissionType::BLOCK,
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            new \DateTimeImmutable('2026-06-01 09:00:00'),
            $instrumentist,
        );

        try {
            $this->service->calculate($mission, $actor);
            self::fail('Devait lever FinancialCalculationAnomaliesException.');
        } catch (FinancialCalculationAnomaliesException $e) {
            $codes = array_map(static fn ($a) => $a->code, $e->getAnomalies());
            self::assertContains('MISSING_INSTRUMENTIST_RATE', $codes);
        }
    }

    public function test_multiple_anomalies_are_collected_in_a_single_exception(): void
    {
        $type = $this->makeType('MULTI-ANOM');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST'); // aucun tarif créé
        $actor = $this->makeUser('ROLE_MANAGER');

        $mission = $this->makeMission(
            MissionType::BLOCK,
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            new \DateTimeImmutable('2026-06-01 09:00:00'),
            $instrumentist,
        );
        $this->addIntervention($mission, $type, null); // MISSING_PRIMARY_FIRM

        try {
            $this->service->calculate($mission, $actor);
            self::fail('Devait lever FinancialCalculationAnomaliesException.');
        } catch (FinancialCalculationAnomaliesException $e) {
            $codes = array_map(static fn ($a) => $a->code, $e->getAnomalies());
            self::assertContains('MISSING_PRIMARY_FIRM', $codes);
            self::assertContains('MISSING_INSTRUMENTIST_RATE', $codes);
            self::assertGreaterThanOrEqual(2, count($e->getAnomalies()), 'toutes les anomalies en un seul rapport, pas un échec au premier élément');
        }

        self::assertSame(
            [AuditEventType::FINANCIAL_CALCULATION_FAILED->value],
            array_map(static fn (AuditEvent $e) => $e->getEventType()->value, $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $mission])),
        );
    }

    // ── Append-only / workflow ────────────────────────────────────────────

    public function test_calculate_rejects_non_validated_mission(): void
    {
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $actor = $this->makeUser('ROLE_MANAGER');
        $mission = $this->makeMission(
            MissionType::BLOCK,
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            new \DateTimeImmutable('2026-06-01 09:00:00'),
            $instrumentist,
            MissionStatus::ASSIGNED,
        );

        $this->expectException(FinancialCalculationIneligibleException::class);
        $this->service->calculate($mission, $actor);
    }

    public function test_calculate_rejects_mission_without_instrumentist(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        $mission = $this->makeMission(
            MissionType::BLOCK,
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            new \DateTimeImmutable('2026-06-01 09:00:00'),
            null,
        );

        $this->expectException(FinancialCalculationIneligibleException::class);
        $this->service->calculate($mission, $actor);
    }

    public function test_calculate_rejects_when_active_calculation_already_exists(): void
    {
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '40.00', new \DateTimeImmutable('2020-01-01'));
        $actor = $this->makeUser('ROLE_MANAGER');
        $mission = $this->makeMission(
            MissionType::BLOCK,
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            new \DateTimeImmutable('2026-06-01 09:00:00'),
            $instrumentist,
        );

        $this->trackCalculation($this->service->calculate($mission, $actor));

        $this->expectException(FinancialCalculationIneligibleException::class);
        $this->service->calculate($mission, $actor);
    }

    public function test_recalculate_supersedes_old_and_never_mutates_it(): void
    {
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '40.00', new \DateTimeImmutable('2020-01-01'));
        $actor = $this->makeUser('ROLE_MANAGER');
        $mission = $this->makeMission(
            MissionType::BLOCK,
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            new \DateTimeImmutable('2026-06-01 09:00:00'),
            $instrumentist,
        );

        $first = $this->trackCalculation($this->service->calculate($mission, $actor));
        $firstOriginalTotal = $first->getLines()->first()->getTotalAmount();
        $firstId = $first->getId();

        $second = $this->trackCalculation($this->service->recalculate($mission, $actor));

        self::assertSame(2, $second->getVersion());
        self::assertSame(FinancialCalculationStatus::CALCULATED, $second->getStatus());

        $this->em->refresh($first);
        self::assertSame(FinancialCalculationStatus::SUPERSEDED, $first->getStatus());
        self::assertNotNull($first->getSupersededAt());
        self::assertSame($second->getId(), $first->getSupersededByCalculation()?->getId());
        self::assertSame($firstOriginalTotal, $first->getLines()->first()->getTotalAmount(), 'jamais réécrit rétroactivement');
        self::assertSame($firstId, $first->getId());

        $eventTypes = array_map(
            static fn (AuditEvent $e) => $e->getEventType()->value,
            $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $mission], ['id' => 'ASC']),
        );
        self::assertSame([
            AuditEventType::FINANCIAL_CALCULATION_CREATED->value,
            AuditEventType::FINANCIAL_CALCULATION_SUPERSEDED->value,
            AuditEventType::FINANCIAL_CALCULATION_RECALCULATED->value,
        ], $eventTypes);
    }

    public function test_recalculate_rejects_when_current_is_locked(): void
    {
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '40.00', new \DateTimeImmutable('2020-01-01'));
        $actor = $this->makeUser('ROLE_MANAGER');
        $mission = $this->makeMission(
            MissionType::BLOCK,
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            new \DateTimeImmutable('2026-06-01 09:00:00'),
            $instrumentist,
        );

        $calc = $this->trackCalculation($this->service->calculate($mission, $actor));
        $this->service->approve($calc, $actor);
        $this->service->lock($calc, $actor);

        $this->expectException(FinancialCalculationIneligibleException::class);
        $this->service->recalculate($mission, $actor);
    }

    public function test_approve_lock_transitions_are_linear(): void
    {
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '40.00', new \DateTimeImmutable('2020-01-01'));
        $actor = $this->makeUser('ROLE_MANAGER');
        $mission = $this->makeMission(
            MissionType::BLOCK,
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            new \DateTimeImmutable('2026-06-01 09:00:00'),
            $instrumentist,
        );
        $calc = $this->trackCalculation($this->service->calculate($mission, $actor));

        // lock() avant approve() : refusé.
        try {
            $this->service->lock($calc, $actor);
            self::fail('lock() ne doit jamais réussir depuis CALCULATED.');
        } catch (FinancialCalculationIneligibleException) {
        }

        $approved = $this->service->approve($calc, $actor);
        self::assertSame(FinancialCalculationStatus::APPROVED, $approved->getStatus());
        self::assertNotNull($approved->getApprovedAt());
        self::assertSame($actor->getId(), $approved->getApprovedBy()?->getId());

        // approve() une seconde fois : refusé (déjà APPROVED).
        try {
            $this->service->approve($approved, $actor);
            self::fail('approve() ne doit jamais réussir deux fois.');
        } catch (FinancialCalculationIneligibleException) {
        }

        $locked = $this->service->lock($approved, $actor);
        self::assertSame(FinancialCalculationStatus::LOCKED, $locked->getStatus());
        self::assertNotNull($locked->getLockedAt());
    }

    public function test_cancel_succeeds_from_calculated_but_not_from_locked(): void
    {
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '40.00', new \DateTimeImmutable('2020-01-01'));
        $actor = $this->makeUser('ROLE_MANAGER');
        $mission = $this->makeMission(
            MissionType::BLOCK,
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            new \DateTimeImmutable('2026-06-01 09:00:00'),
            $instrumentist,
        );
        $calc = $this->trackCalculation($this->service->calculate($mission, $actor));

        $cancelled = $this->service->cancel($calc, $actor, 'test');
        self::assertSame(FinancialCalculationStatus::CANCELLED, $cancelled->getStatus());
        self::assertNotNull($cancelled->getCancelledAt());

        $mission2 = $this->makeMission(
            MissionType::BLOCK,
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            new \DateTimeImmutable('2026-06-01 09:00:00'),
            $instrumentist,
        );
        $calc2 = $this->trackCalculation($this->service->calculate($mission2, $actor));
        $this->service->approve($calc2, $actor);
        $this->service->lock($calc2, $actor);

        $this->expectException(FinancialCalculationIneligibleException::class);
        $this->service->cancel($calc2, $actor);
    }

    public function test_has_locked_calculation_reflects_lock_state(): void
    {
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '40.00', new \DateTimeImmutable('2020-01-01'));
        $actor = $this->makeUser('ROLE_MANAGER');
        $mission = $this->makeMission(
            MissionType::BLOCK,
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            new \DateTimeImmutable('2026-06-01 09:00:00'),
            $instrumentist,
        );
        $calc = $this->trackCalculation($this->service->calculate($mission, $actor));

        self::assertFalse($this->service->hasLockedCalculation($mission));

        $this->service->approve($calc, $actor);
        self::assertFalse($this->service->hasLockedCalculation($mission));

        $this->service->lock($calc, $actor);
        self::assertTrue($this->service->hasLockedCalculation($mission));
    }
}
