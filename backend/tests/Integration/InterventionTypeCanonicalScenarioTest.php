<?php

namespace App\Tests\Integration;

use App\Dto\FinancialStatisticsFilter;
use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\FinancialCalculation;
use App\Entity\FirmServiceOffering;
use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\InterventionType;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\InstrumentistRateType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PricingRuleType;
use App\Service\FinancialCalculationService;
use App\Service\FinancialStatisticsRankingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Task 11, section 9 — scénario obligatoire du référentiel canonique : UN SEUL
 * InterventionType (PTG), DEUX FirmServiceOffering (Smith & Nephew, Zimmer) avec des
 * forfaits INTERVENTION_FEE différents, plusieurs missions par firme. Vérifie que
 * (1) chaque firme conserve son propre forfait, (2) le moteur financier applique le bon
 * tarif selon la firme, (3) toutes les missions sont rattachées au même InterventionType,
 * (4) les statistiques retournent un seul total PTG, (5) la ventilation par firme reste
 * correcte, (6) calculer une firme n'altère jamais le montant déjà figé de l'autre.
 */
final class InterventionTypeCanonicalScenarioTest extends WebTestCase
{
    private const PASSWORD = 'CanonicalPTG15!';
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private array $createdIds = [
        'users' => [], 'firms' => [], 'types' => [], 'offerings' => [], 'rules' => [],
        'sites' => [], 'missions' => [], 'rates' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['missions'] as $id) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $id]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                $m = $this->em->find(Mission::class, $id);
                if ($m !== null) {
                    foreach ($this->em->getRepository(FinancialCalculation::class)->findBy(['mission' => $m]) as $calc) {
                        foreach ($this->em->getRepository(\App\Entity\FinancialCalculationLine::class)->findBy(['financialCalculation' => $calc]) as $line) {
                            $this->em->remove($line);
                        }
                        $this->em->flush();
                        $this->em->remove($calc);
                    }
                    $this->em->flush();
                    foreach ($m->getInterventions() as $i) { $this->em->remove($i); }
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['rules'] as $id) {
                $e = $this->em->find(PricingRule::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            foreach ($this->createdIds['offerings'] as $id) {
                $e = $this->em->find(FirmServiceOffering::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['types'] as $id) {
                $e = $this->em->find(InterventionType::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['firms'] as $id) {
                $e = $this->em->find(Firm::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            foreach ($this->createdIds['sites'] as $id) {
                $e = $this->em->find(Hospital::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['users'] as $id) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['actor' => $id]) as $evt) {
                    $this->em->remove($evt);
                }
                foreach ($this->em->getRepository(InstrumentistRate::class)->findBy(['instrumentist' => $id]) as $rate) {
                    $this->em->remove($rate);
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['users'] as $id) {
                $e = $this->em->find(User::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('ptg-canonical-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Test');
        $u->setLastname('User');
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeMission(InterventionType $type, Firm $firm, User $surgeon, User $instrumentist, Hospital $site, string $startAt): Mission
    {
        $mission = new Mission();
        $mission->setType(MissionType::BLOCK);
        $mission->setSite($site);
        $mission->setSurgeon($surgeon);
        $mission->setCreatedBy($surgeon);
        $mission->setStartAt(new \DateTimeImmutable($startAt, new \DateTimeZone(self::TZ)));
        $mission->setEndAt((new \DateTimeImmutable($startAt, new \DateTimeZone(self::TZ)))->modify('+2 hours'));
        $mission->setStatus(MissionStatus::VALIDATED);
        $mission->setInstrumentist($instrumentist);
        $this->em->persist($mission);
        $this->em->flush();
        $this->createdIds['missions'][] = $mission->getId();

        $intervention = new MissionIntervention();
        $intervention->setMission($mission);
        $intervention->setCode($type->getCode());
        $intervention->setLabel($type->getLabel());
        $intervention->setInterventionType($type);
        $intervention->setPrimaryFirm($firm);
        $this->em->persist($intervention);
        $this->em->flush();
        $mission->getInterventions()->add($intervention);

        return $mission;
    }

    public function test_one_intervention_type_two_firm_forfaits_correct_resolution_and_unified_stats(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $managerUser = $this->em->find(User::class, $manager->getId());

        // ── UN SEUL InterventionType (PTG) ───────────────────────────────────
        $type = new InterventionType();
        $type->setCode('PTG-' . bin2hex(random_bytes(4)));
        $type->setLabel('Prothèse totale de genou');
        $this->em->persist($type);
        $this->em->flush();
        $this->createdIds['types'][] = $type->getId();

        // ── Deux firmes, deux offres commerciales sur le MÊME type ──────────
        $smith = new Firm();
        $smith->setName('SmithNephew-' . bin2hex(random_bytes(3)));
        $this->em->persist($smith);
        $zimmer = new Firm();
        $zimmer->setName('Zimmer-' . bin2hex(random_bytes(3)));
        $this->em->persist($zimmer);
        $this->em->flush();
        $this->createdIds['firms'][] = $smith->getId();
        $this->createdIds['firms'][] = $zimmer->getId();

        foreach ([$smith, $zimmer] as $firm) {
            $offering = new FirmServiceOffering();
            $offering->setFirm($firm);
            $offering->setInterventionType($type);
            $this->em->persist($offering);
            $this->em->flush();
            $this->createdIds['offerings'][] = $offering->getId();
        }

        // ── Forfaits DIFFÉRENTS par firme, même InterventionType ────────────
        $smithRule = new PricingRule();
        $smithRule->setFirm($smith);
        $smithRule->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $smithRule->setInterventionType($type);
        $smithRule->setUnitPrice('800.00');
        $this->em->persist($smithRule);

        $zimmerRule = new PricingRule();
        $zimmerRule->setFirm($zimmer);
        $zimmerRule->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $zimmerRule->setInterventionType($type);
        $zimmerRule->setUnitPrice('650.00');
        $this->em->persist($zimmerRule);
        $this->em->flush();
        $this->createdIds['rules'][] = $smithRule->getId();
        $this->createdIds['rules'][] = $zimmerRule->getId();

        // ── Site, chirurgien, instrumentiste, taux horaire ───────────────────
        $site = new Hospital();
        $site->setName('SitePTG-' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $this->em->flush();
        $this->createdIds['sites'][] = $site->getId();

        $surgeon = $this->em->find(User::class, $this->createUser('ROLE_SURGEON')->getId());
        $instrumentist = $this->em->find(User::class, $this->createUser('ROLE_INSTRUMENTIST')->getId());

        $rate = new InstrumentistRate();
        $rate->setInstrumentist($instrumentist);
        $rate->setRateType(InstrumentistRateType::HOURLY_RATE);
        $rate->setAmount('40.00');
        $rate->setCurrency('EUR');
        $rate->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($rate);
        $this->em->flush();
        $this->createdIds['rates'][] = $rate->getId();

        // ── Plusieurs missions par firme, toutes rattachées au MÊME type ─────
        $smithMission1 = $this->makeMission($type, $smith, $surgeon, $instrumentist, $site, '2026-06-01 08:00:00');
        $smithMission2 = $this->makeMission($type, $smith, $surgeon, $instrumentist, $site, '2026-06-02 08:00:00');
        $zimmerMission1 = $this->makeMission($type, $zimmer, $surgeon, $instrumentist, $site, '2026-06-03 08:00:00');

        // ── point 3 : toutes les missions référencent le même InterventionType ──
        foreach ([$smithMission1, $smithMission2, $zimmerMission1] as $mission) {
            foreach ($mission->getInterventions() as $intervention) {
                self::assertSame($type->getId(), $intervention->getInterventionType()->getId());
            }
        }

        /** @var FinancialCalculationService $calcService */
        $calcService = static::getContainer()->get(FinancialCalculationService::class);

        // ── point 1 + 2 : chaque firme conserve son propre forfait, appliqué correctement ──
        $smithCalc1 = $calcService->calculate($smithMission1, $managerUser);
        $smithCalc2 = $calcService->calculate($smithMission2, $managerUser);
        $zimmerCalc1 = $calcService->calculate($zimmerMission1, $managerUser);

        self::assertEqualsWithDelta(800.0, (float) $this->interventionFeeLine($smithCalc1)->getTotalAmount(), 0.001, 'Smith & Nephew doit appliquer son propre forfait (800), jamais celui de Zimmer.');
        self::assertEqualsWithDelta(800.0, (float) $this->interventionFeeLine($smithCalc2)->getTotalAmount(), 0.001);
        self::assertEqualsWithDelta(650.0, (float) $this->interventionFeeLine($zimmerCalc1)->getTotalAmount(), 0.001, 'Zimmer doit appliquer son propre forfait (650), jamais celui de Smith.');

        // ── point 6 : calculer Zimmer n'a pas altéré le montant déjà figé de Smith ──
        $this->em->clear();
        $reloadedSmithCalc1 = $this->em->find(FinancialCalculation::class, $smithCalc1->getId());
        self::assertEqualsWithDelta(800.0, (float) $this->interventionFeeLine($reloadedSmithCalc1)->getTotalAmount(), 0.001, 'Le calcul Smith ne doit jamais avoir été réécrit par un calcul ultérieur sur une autre firme.');

        // ── point 4 : les statistiques retournent UN SEUL total PTG (pas un par firme) ──
        /** @var FinancialStatisticsRankingService $stats */
        $stats = static::getContainer()->get(FinancialStatisticsRankingService::class);
        $filter = new FinancialStatisticsFilter(
            from: new \DateTimeImmutable('2026-05-01'),
            to: new \DateTimeImmutable('2026-07-01'),
            interventionTypeId: $type->getId(),
        );
        $byIntervention = $stats->byIntervention($filter, 1, 50, 'missionCount', 'desc');
        $ptgRows = array_values(array_filter($byIntervention['items'], fn ($dto) => $dto->interventionTypeId === $type->getId()));
        self::assertCount(1, $ptgRows, 'Un seul bucket statistique doit exister pour ce InterventionType, quelle que soit la firme.');
        self::assertSame(3, $ptgRows[0]->missionCount, 'Le total doit agréger les 3 missions (2 Smith + 1 Zimmer) sous le même InterventionType canonique.');

        // ── point 5 : la ventilation par firme reste correcte sous ce filtre ──
        $byFirm = $stats->byFirm($filter, 1, 50, 'generatedRevenue', 'desc');
        $revenueByFirmId = [];
        foreach ($byFirm['items'] as $dto) {
            $revenueByFirmId[$dto->firmId] = (float) $dto->interventionRevenue;
        }
        self::assertEqualsWithDelta(1600.0, $revenueByFirmId[$smith->getId()] ?? 0.0, 0.001, 'Smith & Nephew : 2 missions x 800 = 1600.');
        self::assertEqualsWithDelta(650.0, $revenueByFirmId[$zimmer->getId()] ?? 0.0, 0.001, 'Zimmer : 1 mission x 650 = 650.');
    }

    private function interventionFeeLine(FinancialCalculation $calculation): \App\Entity\FinancialCalculationLine
    {
        foreach ($calculation->getLines() as $line) {
            if ($line->getLineType()->value === 'FIRM_INTERVENTION_FEE') {
                return $line;
            }
        }
        self::fail('Aucune ligne FIRM_INTERVENTION_FEE trouvée dans le calcul.');
    }
}
