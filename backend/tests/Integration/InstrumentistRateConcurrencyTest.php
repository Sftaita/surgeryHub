<?php

namespace App\Tests\Integration;

use App\Entity\InstrumentistRate;
use App\Entity\User;
use App\Enum\InstrumentistRateType;
use App\Exception\InstrumentistRatePeriodOverlapException;
use App\Service\InstrumentistRateResolver;
use App\Service\InstrumentistRateWriteService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * EPIC Exécution & Valorisation, Lot 2 (D-072) — miroir condensé de
 * PricingRuleConcurrencyTest pour InstrumentistRateWriteService. Même méthode :
 * connexions DBAL réellement distinctes, verrou tenu par un worker B pendant qu'un
 * worker A tente avec un innodb_lock_wait_timeout court — un blocage réel se manifeste
 * par un timeout déterministe, jamais par un pari sur l'ordonnancement.
 */
final class InstrumentistRateConcurrencyTest extends KernelTestCase
{
    private const LOCK_TIMEOUT_SECONDS = 2;

    private EntityManagerInterface $em;
    private array $created = ['rates' => [], 'users' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen()) {
            foreach ($this->created['rates'] as $id) { $e = $this->em->find(InstrumentistRate::class, $id); if ($e) $this->em->remove($e); }
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

    private function writeServiceFor(EntityManagerInterface $em): InstrumentistRateWriteService
    {
        return new InstrumentistRateWriteService($em, new InstrumentistRateResolver($em));
    }

    private function makeInstrumentist(): User
    {
        $u = new User();
        $u->setEmail('rate-concurrency-' . bin2hex(random_bytes(4)) . '@test.com');
        $u->setRoles(['ROLE_INSTRUMENTIST']);
        $u->setActive(true);
        $this->em->persist($u); $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    private function rate(User $instrumentist, string $amount, string $validFrom, ?string $validTo = null): InstrumentistRate
    {
        $r = new InstrumentistRate();
        $r->setInstrumentist($instrumentist);
        $r->setRateType(InstrumentistRateType::HOURLY_RATE);
        $r->setAmount($amount);
        $r->setValidFrom(new \DateTimeImmutable($validFrom));
        if ($validTo !== null) { $r->setValidTo(new \DateTimeImmutable($validTo)); }
        return $r;
    }

    private function beginPendingCreate(EntityManagerInterface $em, InstrumentistRateResolver $resolver, InstrumentistRate $rate): void
    {
        $em->getConnection()->beginTransaction();
        $instrumentist = $em->find(User::class, $rate->getInstrumentist()->getId());
        $em->lock($instrumentist, LockMode::PESSIMISTIC_WRITE);

        if ($resolver->hasOverlap($rate)) {
            $em->getConnection()->rollBack();
            throw new InstrumentistRatePeriodOverlapException('B: chevauchement détecté avant même de tenir le verrou.');
        }

        $em->persist($rate);
        $em->flush();
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

    public function test_two_identical_concurrent_creates_only_one_succeeds(): void
    {
        $instrumentist = $this->makeInstrumentist();

        $emB = $this->freshEntityManager();
        $resolverB = new InstrumentistRateResolver($emB);
        $instrB = $emB->find(User::class, $instrumentist->getId());
        $ruleB = $this->rate($instrB, '45.00', '2026-01-01');
        $this->beginPendingCreate($emB, $resolverB, $ruleB);

        $emA = $this->freshEntityManager();
        $this->setLockTimeout($emA, self::LOCK_TIMEOUT_SECONDS);
        $blocked = false;
        try {
            $this->writeServiceFor($emA)->create($this->rate($emA->find(User::class, $instrumentist->getId()), '50.00', '2026-01-01'));
        } catch (\Throwable $e) {
            $blocked = $this->isLockTimeoutError($e);
        }
        self::assertTrue($blocked, 'La tentative concurrente doit être réellement bloquée par le verrou, jamais réussir en silence.');

        $emB->getConnection()->commit();
        $this->created['rates'][] = $ruleB->getId();

        $emA2 = $this->freshEntityManager();
        $this->expectException(InstrumentistRatePeriodOverlapException::class);
        $this->writeServiceFor($emA2)->create($this->rate($emA2->find(User::class, $instrumentist->getId()), '50.00', '2026-01-01'));
    }

    public function test_different_instrumentists_do_not_block_each_other(): void
    {
        $instr1 = $this->makeInstrumentist();
        $instr2 = $this->makeInstrumentist();

        $emB = $this->freshEntityManager();
        $resolverB = new InstrumentistRateResolver($emB);
        $instr1B = $emB->find(User::class, $instr1->getId());
        $ruleB = $this->rate($instr1B, '45.00', '2026-01-01');
        $this->beginPendingCreate($emB, $resolverB, $ruleB);

        $emA = $this->freshEntityManager();
        $start = microtime(true);
        $created = $this->writeServiceFor($emA)->create($this->rate($emA->find(User::class, $instr2->getId()), '55.00', '2026-01-01'));
        $elapsed = microtime(true) - $start;

        self::assertNotNull($created->getId());
        $this->created['rates'][] = $created->getId();
        self::assertLessThan(1.0, $elapsed, 'Un instrumentiste différent ne doit jamais attendre le verrou d\'un autre.');

        $emB->getConnection()->rollBack();
    }
}
