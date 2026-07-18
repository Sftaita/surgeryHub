<?php

namespace App\Tests\Integration;

use App\Entity\AuditEvent;
use App\Entity\InstrumentistRate;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\InstrumentistRateType;
use App\Exception\InstrumentistRateImmutableException;
use App\Exception\InstrumentistRatePeriodOverlapException;
use App\Service\InstrumentistRateService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * EPIC Exécution & Valorisation, Lot 2 (D-072) — miroir de
 * PricingRuleVersioningServiceTest pour InstrumentistRate.
 */
final class InstrumentistRateServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private InstrumentistRateService $service;
    private array $createdRateIds = [];
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(InstrumentistRateService::class);
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen()) {
            foreach ($this->createdUserIds as $userId) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['actor' => $userId]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            $this->em->flush();
            foreach ($this->createdRateIds as $id) {
                $e = $this->em->find(InstrumentistRate::class, $id);
                if ($e) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdUserIds as $id) {
                $e = $this->em->find(User::class, $id);
                if ($e) { $this->em->remove($e); }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    private function makeInstrumentist(): User
    {
        $u = new User();
        $u->setEmail('rate-instr-' . bin2hex(random_bytes(4)) . '@test.com');
        $u->setRoles(['ROLE_INSTRUMENTIST']);
        $u->setActive(true);
        $this->em->persist($u); $this->em->flush();
        $this->createdUserIds[] = $u->getId();
        return $u;
    }

    private function makeActor(): User
    {
        $u = new User();
        $u->setEmail('rate-manager-' . bin2hex(random_bytes(4)) . '@test.com');
        $u->setRoles(['ROLE_MANAGER']);
        $u->setFirstname('Manager')->setLastname('Test')->setActive(true);
        $this->em->persist($u); $this->em->flush();
        $this->createdUserIds[] = $u->getId();
        return $u;
    }

    private function auditEventTypesForActor(User $actor): array
    {
        $events = $this->em->getRepository(AuditEvent::class)->findBy(['actor' => $actor], ['id' => 'ASC']);
        return array_map(static fn (AuditEvent $e) => $e->getEventType()->value, $events);
    }

    // ── createInitialRate() / resolveAt() ────────────────────────────────────

    public function test_create_initial_rate_succeeds_and_resolves(): void
    {
        $instrumentist = $this->makeInstrumentist();
        $actor = $this->makeActor();

        $rate = $this->service->createInitialRate($instrumentist, InstrumentistRateType::HOURLY_RATE, '45.00', 'EUR', new \DateTimeImmutable('2026-01-01'), null, $actor);
        $this->createdRateIds[] = $rate->getId();

        $resolved = $this->service->resolveAt($instrumentist, InstrumentistRateType::HOURLY_RATE, new \DateTimeImmutable('2026-06-01'));
        self::assertNotNull($resolved);
        self::assertSame('45.00', $resolved->getAmount());
        self::assertSame([AuditEventType::INSTRUMENTIST_RATE_CREATED->value], $this->auditEventTypesForActor($actor));
    }

    public function test_consultation_fee_is_independent_of_hourly_rate(): void
    {
        $instrumentist = $this->makeInstrumentist();
        $actor = $this->makeActor();

        $hourly = $this->service->createInitialRate($instrumentist, InstrumentistRateType::HOURLY_RATE, '45.00', 'EUR', new \DateTimeImmutable('2026-01-01'), null, $actor);
        $this->createdRateIds[] = $hourly->getId();
        $consultation = $this->service->createInitialRate($instrumentist, InstrumentistRateType::CONSULTATION_FEE, '30.00', 'EUR', new \DateTimeImmutable('2026-01-01'), null, $actor);
        $this->createdRateIds[] = $consultation->getId();

        $resolvedHourly = $this->service->resolveAt($instrumentist, InstrumentistRateType::HOURLY_RATE, new \DateTimeImmutable('2026-06-01'));
        $resolvedConsultation = $this->service->resolveAt($instrumentist, InstrumentistRateType::CONSULTATION_FEE, new \DateTimeImmutable('2026-06-01'));

        self::assertSame('45.00', $resolvedHourly->getAmount());
        self::assertSame('30.00', $resolvedConsultation->getAmount());
    }

    public function test_resolve_at_never_uses_now_implicitly(): void
    {
        $instrumentist = $this->makeInstrumentist();
        $actor = $this->makeActor();

        $rate = $this->service->createInitialRate($instrumentist, InstrumentistRateType::HOURLY_RATE, '40.00', 'EUR', new \DateTimeImmutable('2020-01-01'), new \DateTimeImmutable('2020-12-31'), $actor);
        $this->createdRateIds[] = $rate->getId();

        self::assertNull($this->service->resolveAt($instrumentist, InstrumentistRateType::HOURLY_RATE, new \DateTimeImmutable('today')));
        self::assertNotNull($this->service->resolveAt($instrumentist, InstrumentistRateType::HOURLY_RATE, new \DateTimeImmutable('2020-06-01')));
    }

    // ── replaceCurrentRateFrom() — §7 du lot ─────────────────────────────────

    public function test_replace_current_rate_closes_old_and_opens_new(): void
    {
        $instrumentist = $this->makeInstrumentist();
        $actor = $this->makeActor();

        $current = $this->service->createInitialRate($instrumentist, InstrumentistRateType::HOURLY_RATE, '45.00', 'EUR', new \DateTimeImmutable('2026-01-01'), null, $actor);
        $this->createdRateIds[] = $current->getId();

        $effectiveFrom = new \DateTimeImmutable('+1 month');
        $new = $this->service->replaceCurrentRateFrom($current, '50.00', 'EUR', $effectiveFrom, $actor);
        $this->createdRateIds[] = $new->getId();

        $reloadedCurrent = $this->em->find(InstrumentistRate::class, $current->getId());
        self::assertSame($effectiveFrom->format('Y-m-d'), $reloadedCurrent->getValidTo()->format('Y-m-d'));
        self::assertSame('45.00', $reloadedCurrent->getAmount(), 'jamais réécrit rétroactivement');
        self::assertSame('50.00', $new->getAmount());
        self::assertNull($new->getValidTo());

        self::assertFalse($reloadedCurrent->coversDate($effectiveFrom));
        self::assertTrue($new->coversDate($effectiveFrom));
    }

    public function test_replace_rejects_effective_date_in_the_past(): void
    {
        $instrumentist = $this->makeInstrumentist();
        $actor = $this->makeActor();

        $current = $this->service->createInitialRate($instrumentist, InstrumentistRateType::HOURLY_RATE, '45.00', 'EUR', new \DateTimeImmutable('2025-01-01'), null, $actor);
        $this->createdRateIds[] = $current->getId();

        $this->expectException(InstrumentistRateImmutableException::class);
        $this->service->replaceCurrentRateFrom($current, '50.00', 'EUR', new \DateTimeImmutable('2025-06-01'), $actor);
    }

    // ── updateFutureRate() / cancelFutureRate() ──────────────────────────────

    public function test_update_future_rate_rejects_an_already_active_rate(): void
    {
        $instrumentist = $this->makeInstrumentist();
        $actor = $this->makeActor();

        $active = $this->service->createInitialRate($instrumentist, InstrumentistRateType::HOURLY_RATE, '45.00', 'EUR', new \DateTimeImmutable('2026-01-01'), null, $actor);
        $this->createdRateIds[] = $active->getId();

        $this->expectException(InstrumentistRateImmutableException::class);
        $this->service->updateFutureRate($active, '999.00', null, null, null, $actor);
    }

    public function test_cancel_future_rate_removes_it_physically(): void
    {
        $instrumentist = $this->makeInstrumentist();
        $actor = $this->makeActor();

        $future = $this->service->scheduleRate($instrumentist, InstrumentistRateType::HOURLY_RATE, '45.00', 'EUR', new \DateTimeImmutable('+1 month'), null, $actor);
        $futureId = $future->getId();

        $this->service->cancelFutureRate($future, $actor);

        self::assertNull($this->em->find(InstrumentistRate::class, $futureId));
    }

    // ── Chevauchement ─────────────────────────────────────────────────────

    public function test_create_rejects_overlap_with_existing_rate(): void
    {
        $instrumentist = $this->makeInstrumentist();
        $actor = $this->makeActor();

        $first = $this->service->createInitialRate($instrumentist, InstrumentistRateType::HOURLY_RATE, '45.00', 'EUR', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'), $actor);
        $this->createdRateIds[] = $first->getId();

        $this->expectException(InstrumentistRatePeriodOverlapException::class);
        $this->service->createInitialRate($instrumentist, InstrumentistRateType::HOURLY_RATE, '50.00', 'EUR', new \DateTimeImmutable('2026-06-01'), null, $actor);
    }

    public function test_different_rate_types_do_not_conflict(): void
    {
        $instrumentist = $this->makeInstrumentist();
        $actor = $this->makeActor();

        $hourly = $this->service->createInitialRate($instrumentist, InstrumentistRateType::HOURLY_RATE, '45.00', 'EUR', new \DateTimeImmutable('2026-01-01'), null, $actor);
        $this->createdRateIds[] = $hourly->getId();

        // Même période, type différent : aucun chevauchement.
        $consultation = $this->service->createInitialRate($instrumentist, InstrumentistRateType::CONSULTATION_FEE, '30.00', 'EUR', new \DateTimeImmutable('2026-01-01'), null, $actor);
        $this->createdRateIds[] = $consultation->getId();

        self::assertNotNull($consultation->getId());
    }
}
