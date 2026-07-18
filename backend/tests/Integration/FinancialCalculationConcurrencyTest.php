<?php

namespace App\Tests\Integration;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\FinancialCalculation;
use App\Entity\FinancialCalculationLine;
use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\InstrumentistRateType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Service\AuditService;
use App\Service\FinancialCalculationService;
use App\Service\InstrumentistRateResolver;
use App\Service\MissionExecutionService;
use App\Service\PricingRuleResolver;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * EPIC Exécution & Valorisation, Lot 3 (D-073) — §22 du lot : verrou pessimiste sur la
 * Mission, seul mécanisme de sécurité concurrentielle. Même méthode que
 * PricingRuleConcurrencyTest (Lot 1) : connexions DBAL réellement distinctes, un worker
 * tient un verrou non committé pendant qu'un autre tente sa propre transaction avec un
 * innodb_lock_wait_timeout court — un blocage réel se traduit par un timeout
 * déterministe, jamais un pari sur l'ordonnancement.
 */
final class FinancialCalculationConcurrencyTest extends KernelTestCase
{
    private const LOCK_TIMEOUT_SECONDS = 2;

    private EntityManagerInterface $em;
    private array $created = ['calculations' => [], 'rates' => [], 'missions' => [], 'firms' => [], 'sites' => [], 'users' => []];

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
            $this->em->flush();
            foreach ($this->created['calculations'] as $id) {
                $calc = $this->em->find(FinancialCalculation::class, $id);
                if ($calc) {
                    foreach ($calc->getLines() as $l) { $this->em->remove($l); }
                    $this->em->remove($calc);
                }
            }
            $this->em->flush();
            foreach ($this->created['missions'] as $id) { $e = $this->em->find(Mission::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['rates'] as $id) { $e = $this->em->find(InstrumentistRate::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
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

    private function serviceFor(EntityManagerInterface $em): FinancialCalculationService
    {
        $audit = new AuditService($em);
        return new FinancialCalculationService(
            $em,
            new PricingRuleResolver($em),
            new InstrumentistRateResolver($em),
            new MissionExecutionService($em, $audit),
            $audit,
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
        $u->setEmail('fcc-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setPassword('x');
        $this->em->persist($u); $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    private function makeMission(User $instrumentist): Mission
    {
        $site = new Hospital();
        $site->setName('FCC-' . bin2hex(random_bytes(3)));
        $this->em->persist($site); $this->em->flush();
        $this->created['sites'][] = $site->getId();

        $surgeon = $this->makeUser('ROLE_SURGEON');

        $m = new Mission();
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($surgeon);
        $m->setStartAt(new \DateTimeImmutable('2026-06-01 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-06-01 09:00:00'));
        $m->setStatus(MissionStatus::VALIDATED);
        $m->setInstrumentist($instrumentist);
        $this->em->persist($m); $this->em->flush();
        $this->created['missions'][] = $m->getId();
        return $m;
    }

    /**
     * Le worker B "tient" le verrou pessimiste sur la Mission exactement comme le fait
     * FinancialCalculationService::calculate() en tout début de transaction, sans jamais
     * committer — reproduit fidèlement la fenêtre de contention réelle sans dépendre
     * d'un minutage fragile.
     */
    private function beginPendingLock(EntityManagerInterface $em, int $missionId): void
    {
        $em->getConnection()->beginTransaction();
        $mission = $em->find(Mission::class, $missionId);
        $em->lock($mission, LockMode::PESSIMISTIC_WRITE);
    }

    public function test_concurrent_calculate_attempts_are_serialized_by_the_mission_lock(): void
    {
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $rate = new InstrumentistRate();
        $rate->setInstrumentist($instrumentist);
        $rate->setRateType(InstrumentistRateType::HOURLY_RATE);
        $rate->setAmount('40.00');
        $rate->setCurrency('EUR');
        $rate->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($rate); $this->em->flush();
        $this->created['rates'][] = $rate->getId();

        $actor = $this->makeUser('ROLE_MANAGER');
        $mission = $this->makeMission($instrumentist);
        $missionId = $mission->getId();

        // Worker B : tient le verrou pessimiste sur la Mission, transaction non committée.
        $emB = $this->freshEntityManager();
        $this->beginPendingLock($emB, $missionId);

        // Worker A : tentative réelle de calculate() sur la MÊME mission, EntityManager
        // frais avec timeout court — doit être bloqué réellement par le verrou tenu par B.
        $emA = $this->freshEntityManager();
        $this->setLockTimeout($emA, self::LOCK_TIMEOUT_SECONDS);
        $missionA = $emA->find(Mission::class, $missionId);

        $blocked = false;
        try {
            $this->serviceFor($emA)->calculate($missionA, $actor);
        } catch (\Throwable $e) {
            $blocked = $this->isLockTimeoutError($e);
        }
        self::assertTrue($blocked, 'calculate() doit être réellement bloqué par le verrou pessimiste tenu sur la même Mission, jamais réussir en silence pendant la contention.');

        // B libère le verrou (il ne représentait qu'un concurrent en cours, jamais commité).
        $emB->getConnection()->rollBack();

        // A retente avec un EntityManager frais (celui d'avant a été fermé par
        // wrapInTransaction() suite à l'exception de timeout, voir EntityManager::wrapInTransaction()) :
        // doit réussir proprement, une seule fois.
        $emA2 = $this->freshEntityManager();
        $missionA2 = $emA2->find(Mission::class, $missionId);
        $actorA2 = $emA2->find(User::class, $actor->getId());
        $calculation = $this->serviceFor($emA2)->calculate($missionA2, $actorA2);
        $this->created['calculations'][] = $calculation->getId();

        self::assertSame(1, $calculation->getVersion());

        $all = $this->em->getRepository(FinancialCalculation::class)->findBy(['mission' => $missionId]);
        self::assertCount(1, $all, 'aucun doublon, aucune version fantôme produite par la contention.');
    }

    /**
     * Deux calculate() réels et complets (l'un après l'autre, aucun verrou tenu
     * artificiellement) sur la même Mission : le second doit être refusé proprement, sans
     * jamais produire deux calculs actifs simultanés (§21/§22).
     */
    public function test_two_sequential_real_calculates_never_produce_two_active_calculations(): void
    {
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $rate = new InstrumentistRate();
        $rate->setInstrumentist($instrumentist);
        $rate->setRateType(InstrumentistRateType::HOURLY_RATE);
        $rate->setAmount('40.00');
        $rate->setCurrency('EUR');
        $rate->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($rate); $this->em->flush();
        $this->created['rates'][] = $rate->getId();

        $actor = $this->makeUser('ROLE_MANAGER');
        $mission = $this->makeMission($instrumentist);
        $missionId = $mission->getId();

        $emA = $this->freshEntityManager();
        $missionA = $emA->find(Mission::class, $missionId);
        $actorA = $emA->find(User::class, $actor->getId());
        $first = $this->serviceFor($emA)->calculate($missionA, $actorA);
        $this->created['calculations'][] = $first->getId();

        $emB = $this->freshEntityManager();
        $missionB = $emB->find(Mission::class, $missionId);
        $actorB = $emB->find(User::class, $actor->getId());
        $this->expectException(\App\Exception\FinancialCalculationIneligibleException::class);
        $this->serviceFor($emB)->calculate($missionB, $actorB);
    }
}
