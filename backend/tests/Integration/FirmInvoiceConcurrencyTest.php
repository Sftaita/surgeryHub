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
use App\Service\MissionPopulationClauseBuilder;
use App\Service\PricingRuleResolver;
use App\Service\RepresentativePolicyResolver;
use App\Service\RequiredChoiceGroupResolver;
use App\Repository\EncodingTrackingRepository;
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
            new RequiredChoiceGroupResolver($em),
            $audit,
        );
    }

    private function firmInvoiceServiceFor(EntityManagerInterface $em): FirmInvoiceService
    {
        return new FirmInvoiceService(
            $em,
            $this->financialCalculationServiceFor($em),
            new AuditService($em),
            new EncodingTrackingRepository($em->getConnection(), $em, new MissionPopulationClauseBuilder()),
            new \App\Service\FirmBilling\FirmBillingLineEventRecorder($em),
        );
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
        // createDraft() (verrouille chaque calcul distinct avant de vérifier
        // l'éligibilité des lignes).
        $emB = $this->freshEntityManager();
        $emB->getConnection()->beginTransaction();
        $calcB = $emB->find(FinancialCalculation::class, $calculationId);
        $emB->lock($calcB, LockMode::PESSIMISTIC_WRITE);

        // Worker A : tentative réelle de createDraft() sur la MÊME ligne,
        // EntityManager frais avec timeout court — doit être bloqué réellement.
        $emA = $this->freshEntityManager();
        $this->setLockTimeout($emA, self::LOCK_TIMEOUT_SECONDS);
        $firmA = $emA->find(Firm::class, $firm->getId());
        $actorA = $emA->find(User::class, $actor->getId());

        $blocked = false;
        try {
            $this->firmInvoiceServiceFor($emA)->generateDraft($this->firmInvoiceServiceFor($emA)->createDraft(
                $firmA, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'), [$lineId], $actorA,
            ), $actorA);
        } catch (\Throwable $e) {
            $blocked = $this->isLockTimeoutError($e);
        }
        self::assertTrue($blocked, 'createDraft() doit être réellement bloqué par le verrou pessimiste tenu sur le même FinancialCalculation.');

        // B libère le verrou (il ne représentait qu'un concurrent en cours).
        $emB->getConnection()->rollBack();

        // A retente avec un EntityManager frais : doit réussir proprement.
        $emA2 = $this->freshEntityManager();
        $firmA2 = $emA2->find(Firm::class, $firm->getId());
        $actorA2 = $emA2->find(User::class, $actor->getId());
        $invoice = $this->firmInvoiceServiceFor($emA2)->generateDraft($this->firmInvoiceServiceFor($emA2)->createDraft(
            $firmA2, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'), [$lineId], $actorA2,
        ), $actorA2);
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

    // ── Revue PR #1 — courses brouillon / génération / recalcul (REPEATABLE READ) ──
    //
    // Le worker A ouvre sa transaction et lit (instantané InnoDB figé), le worker B valide
    // un changement concurrent, puis A reprend : A doit agir sur l'état COURANT, jamais sur
    // son instantané (un « lock() puis refresh() » relit l'instantané, pas la ligne à jour).

    /** @return array{0: Firm, 1: FinancialCalculationLine, 2: User, 3: \DateTimeImmutable, 4: FirmInvoice} */
    private function approvedLineInADraft(): array
    {
        [$firm, $line, $actor, $today] = $this->makeApprovedCalculationWithOneFirmLine();
        $draft = $this->firmInvoiceServiceFor($this->em)->createDraft($firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'), [$line->getId()], $actor);
        $this->created['invoices'][] = $draft->getId();
        return [$firm, $line, $actor, $today, $draft];
    }

    private function openSnapshot(EntityManagerInterface $em, int $calculationId): void
    {
        $em->getConnection()->beginTransaction();
        self::assertNotFalse($em->getConnection()->fetchOne('SELECT status FROM financial_calculation WHERE id = ?', [$calculationId]));
    }

    private function closeSnapshot(EntityManagerInterface $em): void
    {
        while ($em->getConnection()->isTransactionActive()) {
            $em->getConnection()->rollBack();
        }
    }

    public function test_generation_never_relocks_a_calculation_superseded_while_it_waited(): void
    {
        [, $line, $actor, , $draft] = $this->approvedLineInADraft();
        $calcId = $line->getFinancialCalculation()->getId();

        $emA = $this->freshEntityManager();
        $this->openSnapshot($emA, $calcId); // A voit APPROVED
        $this->freshEntityManager()->getConnection()->executeStatement(
            "UPDATE financial_calculation SET status = 'SUPERSEDED' WHERE id = ?", [$calcId], // recalcul concurrent validé
        );

        $generated = false;
        $codes = [];
        try {
            $this->firmInvoiceServiceFor($emA)->generateDraft($emA->find(FirmInvoice::class, $draft->getId()), $emA->find(User::class, $actor->getId()));
            $generated = true;
        } catch (\App\Exception\DocumentLineSelectionException $e) {
            $codes = array_map(static fn ($a) => $a->code, $e->getAnomalies());
        } finally {
            $this->closeSnapshot($emA);
        }

        self::assertFalse($generated, 'la génération a utilisé son instantané périmé (calcul encore « APPROVED »)');
        self::assertContains('FINANCIAL_LINE_STALE', $codes);
        self::assertSame('SUPERSEDED', $this->freshEntityManager()->getConnection()->fetchOne('SELECT status FROM financial_calculation WHERE id = ?', [$calcId]), 'jamais re-verrouillé par-dessus SUPERSEDED');
    }

    public function test_recalculation_never_supersedes_a_calculation_locked_by_a_concurrent_generation(): void
    {
        [, $line, $actor, , $draft] = $this->approvedLineInADraft();
        $calcId = $line->getFinancialCalculation()->getId();
        $missionId = $line->getFinancialCalculation()->getMission()->getId();

        $emA = $this->freshEntityManager();
        $this->openSnapshot($emA, $calcId); // A voit APPROVED
        $emB = $this->freshEntityManager();
        $this->firmInvoiceServiceFor($emB)->generateDraft($emB->find(FirmInvoice::class, $draft->getId()), $emB->find(User::class, $actor->getId())); // B génère : LOCKED, validé

        $recalculated = false;
        try {
            $new = $this->financialCalculationServiceFor($emA)->recalculate($emA->find(Mission::class, $missionId), $emA->find(User::class, $actor->getId()));
            $recalculated = true;
            $this->created['calculations'][] = $new->getId();
        } catch (\App\Exception\FinancialCalculationIneligibleException) {
        } finally {
            $this->closeSnapshot($emA);
        }

        self::assertFalse($recalculated, 'le recalcul a remplacé un calcul déjà facturé (LOCKED)');
        self::assertSame('LOCKED', $this->freshEntityManager()->getConnection()->fetchOne('SELECT status FROM financial_calculation WHERE id = ?', [$calcId]));
    }

    public function test_move_never_detaches_a_line_from_a_draft_generated_concurrently(): void
    {
        [$firm, $line, $actor, $today, $source] = $this->approvedLineInADraft();
        $target = new FirmInvoice();
        $target->setFirm($firm);
        $target->setCurrency('EUR');
        $target->setPeriodStart($today->modify('-1 day'));
        $target->setPeriodEnd($today->modify('+1 day'));
        $target->setStatus(\App\Enum\InvoiceStatus::DRAFT);
        $target->setLegacySource(false);
        $target->setTotalAmount('0.00');
        $this->em->persist($target); $this->em->flush();
        $this->created['invoices'][] = $target->getId();

        $emA = $this->freshEntityManager();
        $this->openSnapshot($emA, $line->getFinancialCalculation()->getId()); // A voit la source DRAFT
        $emB = $this->freshEntityManager();
        $this->firmInvoiceServiceFor($emB)->generateDraft($emB->find(FirmInvoice::class, $source->getId()), $emB->find(User::class, $actor->getId())); // source GENERATED, validé

        $moved = false;
        try {
            $this->firmInvoiceServiceFor($emA)->moveLinesToDraft($emA->find(FirmInvoice::class, $target->getId()), [$line->getId()], $emA->find(User::class, $actor->getId()));
            $moved = true;
        } catch (\Throwable) {
        } finally {
            $this->closeSnapshot($emA);
        }

        $conn = $this->freshEntityManager()->getConnection();
        self::assertFalse($moved, 'une ligne d\'une facture générée a été déplacée');
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM firm_invoice_line WHERE invoice_id = ?', [$source->getId()]), 'la facture générée garde sa ligne');
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM firm_invoice_line WHERE invoice_id = ?', [$target->getId()]));
    }

}
