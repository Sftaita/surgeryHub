<?php

namespace App\Tests\Integration;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\FinancialCalculation;
use App\Entity\FinancialCalculationLine;
use App\Entity\FirmInvoice;
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
use App\Service\AuditService;
use App\Service\FinancialCalculationService;
use App\Service\FirmInvoiceService;
use App\Service\InstrumentistRateResolver;
use App\Service\MissionExecutionService;
use App\Service\PricingRuleResolver;
use App\Service\RepresentativePolicyResolver;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * EPIC Exécution & Valorisation, Lot 4 (D-074) — §14/§22/§34 du lot : deux créations de
 * facture concurrentes sur la MÊME FinancialCalculationLine ne doivent jamais toutes les
 * deux réussir. Même méthode que FinancialCalculationConcurrencyTest (Lot 3) : connexions
 * DBAL réellement distinctes, un worker tient le verrou pessimiste sur le
 * FinancialCalculation le temps du test.
 */
final class FirmInvoiceConcurrencyTest extends KernelTestCase
{
    private const LOCK_TIMEOUT_SECONDS = 2;

    private EntityManagerInterface $em;
    private array $created = [
        'invoices' => [], 'missions' => [], 'interventions' => [], 'calculations' => [],
        'rules' => [], 'rates' => [], 'types' => [], 'firms' => [], 'sites' => [], 'users' => [],
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
            foreach ($this->created['invoices'] as $id) { $e = $this->em->find(FirmInvoice::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            // FinancialCalculation ne cascade pas la suppression de ses lignes
            // (cascade: ['persist'] uniquement, append-only par conception) — supprimées
            // explicitement avant le calcul lui-même (FK financial_calculation_id).
            foreach ($this->created['calculations'] as $id) {
                $calc = $this->em->find(FinancialCalculation::class, $id);
                if ($calc) {
                    foreach ($calc->getLines() as $l) { $this->em->remove($l); }
                }
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
            new RepresentativePolicyResolver($em),
            $audit,
        );
    }

    private function firmInvoiceServiceFor(EntityManagerInterface $em): FirmInvoiceService
    {
        return new FirmInvoiceService($em, $this->financialCalculationServiceFor($em), new AuditService($em));
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
        $u->setEmail('fic-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setPassword('x');
        $this->em->persist($u); $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    /** Construit un calcul APPROVED avec une seule ligne FIRM_INTERVENTION_FEE. */
    private function makeApprovedCalculationWithOneFirmLine(): array
    {
        $firm = new Firm();
        $firm->setName('FICC-' . bin2hex(random_bytes(3)));
        $this->em->persist($firm); $this->em->flush();
        $this->created['firms'][] = $firm->getId();

        $type = new InterventionType();
        $type->setCode('FICC-' . bin2hex(random_bytes(3)));
        $type->setLabel('FICC');
        $this->em->persist($type); $this->em->flush();
        $this->created['types'][] = $type->getId();

        $rule = new PricingRule();
        $rule->setFirm($firm);
        $rule->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $rule->setInterventionType($type);
        $rule->setUnitPrice('160.00');
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
        $site->setName('FICC-Site-' . bin2hex(random_bytes(3)));
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
        $intervention->setLabel('FICC');
        $intervention->setInterventionType($type);
        $intervention->setPrimaryFirm($firm);
        $this->em->persist($intervention); $this->em->flush();
        $this->created['interventions'][] = $intervention->getId();
        $mission->getInterventions()->add($intervention);

        $actor = $this->makeUser('ROLE_MANAGER');
        $calcService = $this->financialCalculationServiceFor($this->em);
        $calc = $calcService->calculate($mission, $actor);
        $calc = $calcService->approve($calc, $actor);
        $this->created['calculations'][] = $calc->getId();

        $firmLine = null;
        foreach ($calc->getLines() as $l) {
            if ($l->getLineType()->value === 'FIRM_INTERVENTION_FEE') { $firmLine = $l; }
        }

        return [$firm, $firmLine, $actor, $today];
    }

    public function test_two_concurrent_invoice_creations_on_the_same_line_only_one_succeeds(): void
    {
        [$firm, $line, $actor, $today] = $this->makeApprovedCalculationWithOneFirmLine();
        $lineId = $line->getId();
        $calculationId = $line->getFinancialCalculation()->getId();

        // Worker B : tient le verrou pessimiste sur le FinancialCalculation, transaction
        // non committée — reproduit fidèlement la fenêtre de contention réelle de
        // createFromEligibleLines() (verrouille chaque calcul distinct avant de vérifier
        // l'éligibilité des lignes).
        $emB = $this->freshEntityManager();
        $emB->getConnection()->beginTransaction();
        $calcB = $emB->find(FinancialCalculation::class, $calculationId);
        $emB->lock($calcB, LockMode::PESSIMISTIC_WRITE);

        // Worker A : tentative réelle de createFromEligibleLines() sur la MÊME ligne,
        // EntityManager frais avec timeout court — doit être bloqué réellement.
        $emA = $this->freshEntityManager();
        $this->setLockTimeout($emA, self::LOCK_TIMEOUT_SECONDS);
        $firmA = $emA->find(Firm::class, $firm->getId());
        $actorA = $emA->find(User::class, $actor->getId());

        $blocked = false;
        try {
            $this->firmInvoiceServiceFor($emA)->createFromEligibleLines(
                $firmA, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'), [$lineId], $actorA,
            );
        } catch (\Throwable $e) {
            $blocked = $this->isLockTimeoutError($e);
        }
        self::assertTrue($blocked, 'createFromEligibleLines() doit être réellement bloqué par le verrou pessimiste tenu sur le même FinancialCalculation.');

        // B libère le verrou (il ne représentait qu'un concurrent en cours).
        $emB->getConnection()->rollBack();

        // A retente avec un EntityManager frais : doit réussir proprement.
        $emA2 = $this->freshEntityManager();
        $firmA2 = $emA2->find(Firm::class, $firm->getId());
        $actorA2 = $emA2->find(User::class, $actor->getId());
        $invoice = $this->firmInvoiceServiceFor($emA2)->createFromEligibleLines(
            $firmA2, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'), [$lineId], $actorA2,
        );
        $this->created['invoices'][] = $invoice->getId();

        self::assertCount(1, $invoice->getLines());

        $all = $this->em->getRepository(FirmInvoice::class)->findBy(['firm' => $firm]);
        self::assertCount(1, $all, 'aucun doublon produit par la contention.');

        // $this->em avait déjà chargé cette ligne (identity map) avant la création
        // concurrente sur $emA2 — refresh() force la relecture de l'association inverse,
        // mais rattache alors un FirmInvoiceLine géré par $emA2 (un autre EntityManager)
        // à l'identity map de $this->em, ce qui ferait échouer un flush() ultérieur
        // (y compris dans tearDown()). clear() immédiatement après isole ce contrôle.
        $lineFinal = $this->em->find(FinancialCalculationLine::class, $lineId);
        $this->em->refresh($lineFinal);
        self::assertNotNull($lineFinal->getFirmInvoiceLine(), 'la ligne est bien rattachée à exactement une facture.');
        $this->em->clear();
    }
}
