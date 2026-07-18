<?php

namespace App\Tests\Integration;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\PricingRuleType;
use App\Exception\PricingRuleImmutableException;
use App\Exception\PricingRulePeriodOverlapException;
use App\Service\PricingRuleVersioningService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * EPIC Exécution & Valorisation, Lot 2 (D-072) — PricingRuleVersioningService est
 * final et dépend de PricingRuleWriteService/PricingRuleResolver (également final,
 * eux-mêmes couplés à une vraie connexion DB pour le verrouillage) : test
 * d'intégration sur base réelle, comme le reste de la suite PricingRule existante
 * (PricingRuleConcurrencyTest, PricingRuleResolverTest), pas un test unitaire mocké.
 */
final class PricingRuleVersioningServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PricingRuleVersioningService $service;
    private array $created = ['rules' => [], 'types' => [], 'items' => [], 'firms' => [], 'users' => [], 'events' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(PricingRuleVersioningService::class);
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen()) {
            // Nettoie tous les AuditEvent des acteurs de test, pas seulement ceux
            // explicitement consultés — un test qui échoue avant d'appeler
            // auditEventTypesForActor() peut quand même avoir produit des événements
            // (ex: createInitialRule() réussi avant qu'un replaceCurrentRuleFrom() ne lève).
            foreach ($this->created['users'] as $userId) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['actor' => $userId]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            $this->em->flush();
            foreach ($this->created['rules'] as $id) { $e = $this->em->find(PricingRule::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['items'] as $id) { $e = $this->em->find(MaterialItem::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['types'] as $id) { $e = $this->em->find(InterventionType::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['firms'] as $id) { $e = $this->em->find(Firm::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['users'] as $id) { $e = $this->em->find(User::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
        }
        parent::tearDown();
    }

    private function makeFirm(): Firm
    {
        $f = new Firm();
        $f->setName('VersioningFirm-' . bin2hex(random_bytes(4)));
        $this->em->persist($f); $this->em->flush();
        $this->created['firms'][] = $f->getId();
        return $f;
    }

    private function makeType(): InterventionType
    {
        $t = new InterventionType();
        $t->setCode('VT-' . bin2hex(random_bytes(4)));
        $t->setLabel('Type de test versioning');
        $this->em->persist($t); $this->em->flush();
        $this->created['types'][] = $t->getId();
        return $t;
    }

    private function makeActor(): User
    {
        $u = new User();
        $u->setEmail('versioning-' . bin2hex(random_bytes(4)) . '@test.com');
        $u->setRoles(['ROLE_MANAGER']);
        $u->setFirstname('Manager')->setLastname('Test')->setActive(true);
        $this->em->persist($u); $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    private function auditEventTypesForActor(User $actor): array
    {
        $events = $this->em->getRepository(AuditEvent::class)->findBy(['actor' => $actor], ['id' => 'ASC']);
        return array_map(static fn (AuditEvent $e) => $e->getEventType()->value, $events);
    }

    // ── createInitialRule() ──────────────────────────────────────────────────

    public function test_create_initial_rule_succeeds_and_audits(): void
    {
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $actor = $this->makeActor();

        $rule = $this->service->createInitialRule($firm, PricingRuleType::INTERVENTION_FEE, $type, null, '180.00', 'EUR', null, null, $actor);
        $this->created['rules'][] = $rule->getId();

        self::assertNotNull($rule->getId());
        self::assertSame([AuditEventType::PRICING_RULE_CREATED->value], $this->auditEventTypesForActor($actor));
    }

    // ── scheduleRule() ───────────────────────────────────────────────────────

    public function test_schedule_rule_requires_future_valid_from(): void
    {
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $actor = $this->makeActor();

        $this->expectException(PricingRuleImmutableException::class);
        $this->service->scheduleRule($firm, PricingRuleType::INTERVENTION_FEE, $type, null, '180.00', 'EUR', new \DateTimeImmutable('today'), null, $actor);
    }

    public function test_schedule_rule_succeeds_and_audits(): void
    {
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $actor = $this->makeActor();

        $rule = $this->service->scheduleRule($firm, PricingRuleType::INTERVENTION_FEE, $type, null, '200.00', 'EUR', new \DateTimeImmutable('+1 month'), null, $actor);
        $this->created['rules'][] = $rule->getId();

        self::assertSame([AuditEventType::PRICING_RULE_SCHEDULED->value], $this->auditEventTypesForActor($actor));
    }

    // ── replaceCurrentRuleFrom() — §7 du lot ────────────────────────────────

    public function test_replace_current_rule_closes_old_and_opens_new_atomically(): void
    {
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $actor = $this->makeActor();

        $current = $this->service->createInitialRule($firm, PricingRuleType::INTERVENTION_FEE, $type, null, '250.00', 'EUR', new \DateTimeImmutable('2026-01-01'), null, $actor);
        $this->created['rules'][] = $current->getId();

        $effectiveFrom = new \DateTimeImmutable('+1 month');
        $new = $this->service->replaceCurrentRuleFrom($current, '275.00', 'EUR', $effectiveFrom, $actor);
        $this->created['rules'][] = $new->getId();

        $reloadedCurrent = $this->em->find(PricingRule::class, $current->getId());
        self::assertSame($effectiveFrom->format('Y-m-d'), $reloadedCurrent->getValidTo()->format('Y-m-d'));
        self::assertSame('250.00', $reloadedCurrent->getUnitPrice(), 'l\'ancienne règle garde son montant original — jamais réécrit');
        self::assertSame($effectiveFrom->format('Y-m-d'), $new->getValidFrom()->format('Y-m-d'));
        self::assertNull($new->getValidTo());
        self::assertSame('275.00', $new->getUnitPrice());

        // Aucun état intermédiaire : au jour effectiveFrom, seule la nouvelle règle couvre.
        self::assertFalse($reloadedCurrent->coversDate($effectiveFrom));
        self::assertTrue($new->coversDate($effectiveFrom));

        self::assertSame([AuditEventType::PRICING_RULE_CREATED->value, AuditEventType::PRICING_RULE_REPLACED->value], $this->auditEventTypesForActor($actor));
    }

    public function test_replace_rejects_effective_date_in_the_past(): void
    {
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $actor = $this->makeActor();

        $current = $this->service->createInitialRule($firm, PricingRuleType::INTERVENTION_FEE, $type, null, '250.00', 'EUR', new \DateTimeImmutable('2025-01-01'), null, $actor);
        $this->created['rules'][] = $current->getId();

        $this->expectException(PricingRuleImmutableException::class);
        $this->service->replaceCurrentRuleFrom($current, '275.00', 'EUR', new \DateTimeImmutable('2025-06-01'), $actor);
    }

    public function test_replace_rejects_a_rule_not_yet_active(): void
    {
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $actor = $this->makeActor();

        $future = $this->service->scheduleRule($firm, PricingRuleType::INTERVENTION_FEE, $type, null, '200.00', 'EUR', new \DateTimeImmutable('+1 month'), null, $actor);
        $this->created['rules'][] = $future->getId();

        $this->expectException(PricingRuleImmutableException::class);
        $this->service->replaceCurrentRuleFrom($future, '210.00', 'EUR', new \DateTimeImmutable('+2 months'), $actor);
    }

    public function test_replace_rejects_an_already_closed_rule(): void
    {
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $actor = $this->makeActor();

        $closed = $this->service->createInitialRule($firm, PricingRuleType::INTERVENTION_FEE, $type, null, '250.00', 'EUR', new \DateTimeImmutable('2020-01-01'), new \DateTimeImmutable('2021-01-01'), $actor);
        $this->created['rules'][] = $closed->getId();

        $this->expectException(PricingRuleImmutableException::class);
        $this->service->replaceCurrentRuleFrom($closed, '260.00', 'EUR', new \DateTimeImmutable('+1 month'), $actor);
    }

    // ── updateFutureRule() / cancelFutureRule() — §2.4 ──────────────────────

    public function test_update_future_rule_succeeds_and_audits(): void
    {
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $actor = $this->makeActor();

        $future = $this->service->scheduleRule($firm, PricingRuleType::INTERVENTION_FEE, $type, null, '200.00', 'EUR', new \DateTimeImmutable('+1 month'), null, $actor);
        $this->created['rules'][] = $future->getId();

        $updated = $this->service->updateFutureRule($future, '220.00', null, null, null, $actor);

        self::assertSame('220.00', $updated->getUnitPrice());
        self::assertSame(
            [AuditEventType::PRICING_RULE_SCHEDULED->value, AuditEventType::PRICING_RULE_FUTURE_UPDATED->value],
            $this->auditEventTypesForActor($actor),
        );
    }

    public function test_update_future_rule_rejects_an_already_active_rule(): void
    {
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $actor = $this->makeActor();

        $active = $this->service->createInitialRule($firm, PricingRuleType::INTERVENTION_FEE, $type, null, '250.00', 'EUR', new \DateTimeImmutable('2026-01-01'), null, $actor);
        $this->created['rules'][] = $active->getId();

        $this->expectException(PricingRuleImmutableException::class);
        $this->service->updateFutureRule($active, '999.00', null, null, null, $actor);
    }

    public function test_cancel_future_rule_removes_it_physically_and_audits(): void
    {
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $actor = $this->makeActor();

        $future = $this->service->scheduleRule($firm, PricingRuleType::INTERVENTION_FEE, $type, null, '200.00', 'EUR', new \DateTimeImmutable('+1 month'), null, $actor);
        $futureId = $future->getId();

        $this->service->cancelFutureRule($future, $actor);

        self::assertNull($this->em->find(PricingRule::class, $futureId));
        self::assertSame(
            [AuditEventType::PRICING_RULE_SCHEDULED->value, AuditEventType::PRICING_RULE_FUTURE_CANCELLED->value],
            $this->auditEventTypesForActor($actor),
        );
    }

    public function test_cancel_future_rule_rejects_an_already_active_rule(): void
    {
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $actor = $this->makeActor();

        $active = $this->service->createInitialRule($firm, PricingRuleType::INTERVENTION_FEE, $type, null, '250.00', 'EUR', new \DateTimeImmutable('2026-01-01'), null, $actor);
        $this->created['rules'][] = $active->getId();

        $this->expectException(PricingRuleImmutableException::class);
        $this->service->cancelFutureRule($active, $actor);
    }

    // ── Chevauchement — §2.3 ─────────────────────────────────────────────────

    public function test_create_rejects_overlap_with_existing_rule(): void
    {
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $actor = $this->makeActor();

        $first = $this->service->createInitialRule($firm, PricingRuleType::INTERVENTION_FEE, $type, null, '250.00', 'EUR', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'), $actor);
        $this->created['rules'][] = $first->getId();

        $this->expectException(PricingRulePeriodOverlapException::class);
        $this->service->createInitialRule($firm, PricingRuleType::INTERVENTION_FEE, $type, null, '260.00', 'EUR', new \DateTimeImmutable('2026-06-01'), null, $actor);
    }

    // ── Résolution — §3.1/§3.2/§8 : par FK, jamais par code libre ────────────

    public function test_resolve_at_intervention_by_fk_never_by_code_string(): void
    {
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $actor = $this->makeActor();

        $rule = $this->service->createInitialRule($firm, PricingRuleType::INTERVENTION_FEE, $type, null, '180.00', 'EUR', new \DateTimeImmutable('2026-01-01'), null, $actor);
        $this->created['rules'][] = $rule->getId();

        $resolved = $this->service->resolveAt($firm, PricingRuleType::INTERVENTION_FEE, $type, null, new \DateTimeImmutable('2026-06-01'));
        self::assertNotNull($resolved);
        self::assertSame($rule->getId(), $resolved->getId());
    }

    public function test_resolve_at_never_uses_now_implicitly(): void
    {
        // Une règle valide uniquement en 2020 ne doit jamais être retournée pour "aujourd'hui" —
        // preuve que resolveAt() respecte strictement la date transmise, jamais now().
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $actor = $this->makeActor();

        $rule = $this->service->createInitialRule($firm, PricingRuleType::INTERVENTION_FEE, $type, null, '100.00', 'EUR', new \DateTimeImmutable('2020-01-01'), new \DateTimeImmutable('2020-12-31'), $actor);
        $this->created['rules'][] = $rule->getId();

        self::assertNull($this->service->resolveAt($firm, PricingRuleType::INTERVENTION_FEE, $type, null, new \DateTimeImmutable('today')));
        self::assertNotNull($this->service->resolveAt($firm, PricingRuleType::INTERVENTION_FEE, $type, null, new \DateTimeImmutable('2020-06-01')));
    }
}
