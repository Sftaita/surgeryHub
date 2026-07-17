<?php

namespace App\Tests\Integration;

use App\Entity\Firm;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\PricingRule;
use App\Enum\PricingRuleType;
use App\Exception\PricingRulePeriodOverlapException;
use App\Service\PricingRuleResolver;
use App\Service\PricingRuleWriteService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Correctif final Lot 1 — non-régression pour PricingRuleWriteService.
 *
 * Avant correction, cette même classe de test PROUVAIT la vulnérabilité (voir git log) :
 * deux EntityManager indépendants pouvaient chacun lire hasOverlap()=false avant que
 * l'autre ne committe, produisant deux PricingRule actives réellement chevauchantes.
 * Elle prouve maintenant l'inverse : le verrouillage pessimiste déterministe (Firm puis
 * InterventionType/MaterialItem, voir PricingRuleWriteService) rend cette fenêtre de
 * course impossible à exploiter.
 *
 * Méthode : connexions DBAL réellement distinctes (EntityManager séparés, pas de thread
 * simulé). Pour prouver le blocage RÉEL sans dépendre d'un minutage fragile, le worker B
 * tient une transaction ouverte (verrou pris, non committée) pendant que le worker A
 * tente son écriture avec un `innodb_lock_wait_timeout` volontairement court : si le
 * verrou ne bloquait pas réellement, A réussirait instantanément ; s'il bloque
 * réellement, A échoue de façon déterministe par timeout MySQL après N secondes, jamais
 * par un pari sur l'ordonnancement.
 *
 * Note d'implémentation : Doctrine::wrapInTransaction() ferme l'EntityManager (close())
 * dans son bloc finally dès qu'une exception traverse la transaction — y compris notre
 * timeout de verrou volontaire. C'est le comportement réel utilisé par
 * PricingRuleWriteService en production (chaque requête HTTP a de toute façon son
 * propre EntityManager). Ce test le respecte : chaque tentative "worker A" utilise un
 * EntityManager fraîchement ouvert, jamais réutilisé après un échec — fidèle à ce que
 * seraient deux vraies requêtes HTTP successives.
 */
final class PricingRuleConcurrencyTest extends KernelTestCase
{
    private const LOCK_TIMEOUT_SECONDS = 2;

    /** EntityManager dédié aux fixtures et au tearDown — jamais utilisé pour la tentative d'écriture sous verrou. */
    private EntityManagerInterface $em;
    private ContainerInterface $container;
    private array $created = ['rules' => [], 'types' => [], 'items' => [], 'firms' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->container = self::getContainer();
        $this->em = $this->container->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen()) {
            foreach ($this->created['rules'] as $id) { $e = $this->em->find(PricingRule::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['items'] as $id) { $e = $this->em->find(MaterialItem::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['types'] as $id) { $e = $this->em->find(InterventionType::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['firms'] as $id) { $e = $this->em->find(Firm::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
        }
        parent::tearDown();
    }

    /** Ouvre une connexion DBAL réellement distincte — représente un worker HTTP concurrent. */
    private function freshEntityManager(): EntityManagerInterface
    {
        return new \Doctrine\ORM\EntityManager(
            \Doctrine\DBAL\DriverManager::getConnection($this->em->getConnection()->getParams()),
            $this->em->getConfiguration(),
        );
    }

    private function writeServiceFor(EntityManagerInterface $em): PricingRuleWriteService
    {
        return new PricingRuleWriteService($em, new PricingRuleResolver($em));
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

    private function interventionRule(Firm $firm, InterventionType $type, string $price, ?string $validFrom = null, ?string $validTo = null): PricingRule
    {
        $r = new PricingRule();
        $r->setFirm($firm);
        $r->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $r->setInterventionType($type);
        $r->setUnitPrice($price);
        if ($validFrom !== null) { $r->setValidFrom(new \DateTimeImmutable($validFrom)); }
        if ($validTo !== null) { $r->setValidTo(new \DateTimeImmutable($validTo)); }
        return $r;
    }

    private function materialRule(Firm $firm, MaterialItem $item, string $price): PricingRule
    {
        $r = new PricingRule();
        $r->setFirm($firm);
        $r->setRuleType(PricingRuleType::MATERIAL_FEE);
        $r->setMaterialItem($item);
        $r->setUnitPrice($price);
        return $r;
    }

    /**
     * Réplique manuellement PricingRuleWriteService::create() SANS committer, pour tenir
     * le verrou ouvert le temps du test — c'est la seule façon de prouver, dans un même
     * process PHP (pas de vrai threading), qu'une seconde connexion se heurte réellement
     * au verrou plutôt que de supposer que l'implémentation le fait.
     */
    private function beginPendingCreate(EntityManagerInterface $em, PricingRuleResolver $resolver, PricingRule $rule): void
    {
        $em->getConnection()->beginTransaction();

        $firm = $em->find(Firm::class, $rule->getFirm()->getId());
        $em->lock($firm, LockMode::PESSIMISTIC_WRITE);

        if ($rule->getRuleType() === PricingRuleType::INTERVENTION_FEE) {
            $it = $em->find(InterventionType::class, $rule->getInterventionType()->getId());
            $em->lock($it, LockMode::PESSIMISTIC_WRITE);
        } else {
            $mi = $em->find(MaterialItem::class, $rule->getMaterialItem()->getId());
            $em->lock($mi, LockMode::PESSIMISTIC_WRITE);
        }

        if ($resolver->hasOverlap($rule)) {
            $em->getConnection()->rollBack();
            throw new PricingRulePeriodOverlapException('B: chevauchement détecté avant même de tenir le verrou.');
        }

        $em->persist($rule);
        $em->flush();
        // Volontairement pas de commit() ici — transaction laissée ouverte, verrou tenu.
    }

    private function commitPending(EntityManagerInterface $em, PricingRule $rule): void
    {
        $em->getConnection()->commit();
        $this->created['rules'][] = $rule->getId();
    }

    private function rollbackPending(EntityManagerInterface $em): void
    {
        $em->getConnection()->rollBack();
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

    /**
     * Construit et tente une PricingRule sur un EntityManager fraîchement ouvert avec un
     * timeout de verrou court, et affirme que la tentative a bien été bloquée par le
     * verrou tenu ailleurs (jamais une réussite silencieuse). $buildRule reçoit ce même
     * EntityManager frais pour construire sa PricingRule — Firm/InterventionType/
     * MaterialItem doivent être re-fetchés via CET EntityManager (Doctrine::lock()
     * exige une entité managée par l'EM qui verrouille, pas juste "persistée quelque
     * part"), jamais réutilisés depuis l'EM de fixtures.
     */
    private function assertBlockedByLock(\Closure $buildRule, bool $isUpdate = false): void
    {
        $em = $this->freshEntityManager();
        $this->setLockTimeout($em, self::LOCK_TIMEOUT_SECONDS);
        $service = $this->writeServiceFor($em);
        $rule = $buildRule($em);

        $blocked = false;
        try {
            $isUpdate ? $service->update($rule) : $service->create($rule);
        } catch (\Throwable $e) {
            $blocked = $this->isLockTimeoutError($e);
        }
        self::assertTrue($blocked, 'La tentative devait être réellement bloquée par le verrou tenu par le worker concurrent, pas réussir en silence.');
    }

    // ── Cas A — deux créations identiques simultanées : une seule réussit ──────────

    public function test_case_a_two_identical_concurrent_creates_only_one_succeeds(): void
    {
        $firm = $this->makeFirm('CaseA');
        $type = $this->makeType('LCA');

        $emB = $this->freshEntityManager();
        $resolverB = new PricingRuleResolver($emB);
        $firmB = $emB->find(Firm::class, $firm->getId());
        $typeB = $emB->find(InterventionType::class, $type->getId());

        $ruleB = $this->interventionRule($firmB, $typeB, '120.00');
        $this->beginPendingCreate($emB, $resolverB, $ruleB);

        // Worker A tente EXACTEMENT la même cible pendant que B tient le verrou.
        $this->assertBlockedByLock(fn (EntityManagerInterface $em) => $this->interventionRule(
            $em->find(Firm::class, $firm->getId()),
            $em->find(InterventionType::class, $type->getId()),
            '100.00',
        ));

        $this->commitPending($emB, $ruleB);

        // Nouvelle tentative de A (EntityManager frais), verrou libéré : B est déjà
        // committée, A doit être refusée proprement — jamais un doublon silencieux.
        $emA2 = $this->freshEntityManager();
        $this->expectException(PricingRulePeriodOverlapException::class);
        $this->writeServiceFor($emA2)->create($this->interventionRule(
            $emA2->find(Firm::class, $firm->getId()),
            $emA2->find(InterventionType::class, $type->getId()),
            '100.00',
        ));
    }

    // ── Cas B — deux périodes qui se chevauchent : une seule réussit ───────────────

    public function test_case_b_overlapping_periods_only_one_succeeds(): void
    {
        $firm = $this->makeFirm('CaseB');
        $type = $this->makeType('PTE');

        $emB = $this->freshEntityManager();
        $resolverB = new PricingRuleResolver($emB);
        $firmB = $emB->find(Firm::class, $firm->getId());
        $typeB = $emB->find(InterventionType::class, $type->getId());

        // B pose 2026 complet.
        $ruleB = $this->interventionRule($firmB, $typeB, '250.00', '2026-01-01', '2026-12-31');
        $this->beginPendingCreate($emB, $resolverB, $ruleB);

        // A tente une période qui chevauche les 15 derniers jours de B.
        $this->assertBlockedByLock(fn (EntityManagerInterface $em) => $this->interventionRule(
            $em->find(Firm::class, $firm->getId()),
            $em->find(InterventionType::class, $type->getId()),
            '275.00', '2026-12-15', null,
        ));

        $this->commitPending($emB, $ruleB);

        $emA2 = $this->freshEntityManager();
        $this->expectException(PricingRulePeriodOverlapException::class);
        $this->writeServiceFor($emA2)->create($this->interventionRule(
            $emA2->find(Firm::class, $firm->getId()),
            $emA2->find(InterventionType::class, $type->getId()),
            '275.00', '2026-12-15', null,
        ));
    }

    // ── Cas C — périodes adjacentes, non chevauchantes : les deux réussissent ──────

    public function test_case_c_adjacent_non_overlapping_periods_both_succeed(): void
    {
        $firm = $this->makeFirm('CaseC');
        $type = $this->makeType('MENISQUE');

        $emB = $this->freshEntityManager();
        $resolverB = new PricingRuleResolver($emB);
        $firmB = $emB->find(Firm::class, $firm->getId());
        $typeB = $emB->find(InterventionType::class, $type->getId());

        // B : jusqu'au 31/12/2026 inclus.
        $ruleB = $this->interventionRule($firmB, $typeB, '150.00', null, '2026-12-31');
        $this->beginPendingCreate($emB, $resolverB, $ruleB);

        // A : à partir du 01/01/2027 — adjacente, pas de chevauchement. Doit quand même
        // attendre le verrou de cible (même Firm+InterventionType) puis réussir une fois
        // B committée, PAS échouer en conflit métier (le chevauchement n'existe pas).
        $this->assertBlockedByLock(fn (EntityManagerInterface $em) => $this->interventionRule(
            $em->find(Firm::class, $firm->getId()),
            $em->find(InterventionType::class, $type->getId()),
            '160.00', '2027-01-01', null,
        ));

        $this->commitPending($emB, $ruleB);

        $emA2 = $this->freshEntityManager();
        $created = $this->writeServiceFor($emA2)->create($this->interventionRule(
            $emA2->find(Firm::class, $firm->getId()),
            $emA2->find(InterventionType::class, $type->getId()),
            '160.00', '2027-01-01', null,
        ));
        self::assertNotNull($created->getId());
        $this->created['rules'][] = $created->getId();
    }

    // ── Cas D — deux cibles différentes : aucun blocage inutile ────────────────────

    public function test_case_d_different_targets_do_not_block_each_other(): void
    {
        $firmSN = $this->makeFirm('SmithNephew');
        $firmMedacta = $this->makeFirm('Medacta');
        $lca = $this->makeType('LCA');
        $pte = $this->makeType('PTE');

        $emB = $this->freshEntityManager();
        $resolverB = new PricingRuleResolver($emB);
        $firmSN_B = $emB->find(Firm::class, $firmSN->getId());
        $lcaB = $emB->find(InterventionType::class, $lca->getId());

        // B tient le verrou sur (SmithNephew, LCA), transaction volontairement non
        // committée pendant toute la durée du test.
        $ruleB = $this->interventionRule($firmSN_B, $lcaB, '180.00');
        $this->beginPendingCreate($emB, $resolverB, $ruleB);

        // A écrit sur une cible totalement différente (Medacta, PTE) — ne doit PAS
        // attendre le verrou de B. Mesuré : doit rester rapide (pas de contention).
        $emA = $this->freshEntityManager();
        $start = microtime(true);
        $created = $this->writeServiceFor($emA)->create($this->interventionRule(
            $emA->find(Firm::class, $firmMedacta->getId()),
            $emA->find(InterventionType::class, $pte->getId()),
            '300.00',
        ));
        $elapsed = microtime(true) - $start;

        self::assertNotNull($created->getId());
        $this->created['rules'][] = $created->getId();
        self::assertLessThan(
            1.0,
            $elapsed,
            'Une cible différente ne doit jamais attendre le verrou d\'une autre cible (coût de performance assumé ' .
            'uniquement au sein de la MÊME firme, voir PricingRuleWriteService).',
        );

        $this->rollbackPending($emB);
    }

    // ── Cas E — création et modification simultanées ───────────────────────────────

    public function test_case_e_concurrent_create_and_update_cannot_produce_an_overlap(): void
    {
        $firm = $this->makeFirm('CaseE');
        $type = $this->makeType('EPAULE');

        // Règle existante : 2025 uniquement, créée normalement (pas de concurrence ici).
        $emSetup = $this->freshEntityManager();
        $existing = $this->writeServiceFor($emSetup)->create($this->interventionRule(
            $emSetup->find(Firm::class, $firm->getId()),
            $emSetup->find(InterventionType::class, $type->getId()),
            '90.00', '2025-01-01', '2025-12-31',
        ));
        $existingId = $existing->getId();
        $this->created['rules'][] = $existingId;

        $emB = $this->freshEntityManager();
        $resolverB = new PricingRuleResolver($emB);
        $firmB = $emB->find(Firm::class, $firm->getId());
        $typeB = $emB->find(InterventionType::class, $type->getId());

        // B crée une NOUVELLE règle sur 2026, verrou tenu, non committée.
        $ruleB = $this->interventionRule($firmB, $typeB, '95.00', '2026-01-01', null);
        $this->beginPendingCreate($emB, $resolverB, $ruleB);

        // A tente d'étendre la règle existante jusqu'en juin 2026 — chevaucherait la
        // règle B si elle passait avant que B ne committe.
        $emA = $this->freshEntityManager();
        $existingA = $emA->find(PricingRule::class, $existingId);
        $existingA->setValidTo(new \DateTimeImmutable('2026-06-30'));
        $this->setLockTimeout($emA, self::LOCK_TIMEOUT_SECONDS);
        $blocked = false;
        try {
            $this->writeServiceFor($emA)->update($existingA);
        } catch (\Throwable $e) {
            $blocked = $this->isLockTimeoutError($e);
        }
        self::assertTrue($blocked, 'La modification de A doit être bloquée pendant que B tient le verrou de la même cible.');

        $this->commitPending($emB, $ruleB);

        // Toujours en conflit maintenant que B est committée : l'update doit être
        // refusé, jamais silencieusement accepté avec un chevauchement réel.
        $emA2 = $this->freshEntityManager();
        $existingRetry = $emA2->find(PricingRule::class, $existingId);
        $existingRetry->setValidTo(new \DateTimeImmutable('2026-06-30'));
        $this->expectException(PricingRulePeriodOverlapException::class);
        $this->writeServiceFor($emA2)->update($existingRetry);
    }

    // ── Cas F — matériel : même protection sur une cible MATERIAL_FEE ──────────────

    public function test_case_f_material_fee_targets_are_protected_too(): void
    {
        $firm = $this->makeFirm('CaseF');
        $item = $this->makeItem($firm);

        $emB = $this->freshEntityManager();
        $resolverB = new PricingRuleResolver($emB);
        $firmB = $emB->find(Firm::class, $firm->getId());
        $itemB = $emB->find(MaterialItem::class, $item->getId());

        $ruleB = $this->materialRule($firmB, $itemB, '35.00');
        $this->beginPendingCreate($emB, $resolverB, $ruleB);

        $this->assertBlockedByLock(fn (EntityManagerInterface $em) => $this->materialRule(
            $em->find(Firm::class, $firm->getId()),
            $em->find(MaterialItem::class, $item->getId()),
            '40.00',
        ));

        $this->commitPending($emB, $ruleB);

        $emA2 = $this->freshEntityManager();
        $this->expectException(PricingRulePeriodOverlapException::class);
        $this->writeServiceFor($emA2)->create($this->materialRule(
            $emA2->find(Firm::class, $firm->getId()),
            $emA2->find(MaterialItem::class, $item->getId()),
            '40.00',
        ));
    }
}
