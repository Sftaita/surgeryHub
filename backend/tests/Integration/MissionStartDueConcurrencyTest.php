<?php

namespace App\Tests\Integration;

use App\Command\MissionStartDueCommand;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use App\Service\AuditService;
use App\Service\MissionEligibilityService;
use App\Service\MissionPostDeployService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Now that app:missions:start-due runs on an automated ~5min schedule (D-064) instead of
 * only manually, two overlapping invocations (a slow previous run still in flight when
 * the next tick fires) become a real possibility — MissionPostDeployService::start()
 * previously had no protection against this: two processes could both read the same
 * mission as ASSIGNED before either committed, producing two MISSION_STARTED audit
 * events for the same mission. Fixed by wrapping start() in the same pessimistic-lock
 * pattern already used by claim() for the analogous double-claim race.
 *
 * Same proof technique as PricingRuleConcurrencyTest: two independent DBAL connections,
 * one holds an uncommitted transaction (lock taken, not released) while the other
 * attempts the same mutation under a short innodb_lock_wait_timeout — a deterministic
 * MySQL timeout proves real blocking, never a race on scheduling.
 */
final class MissionStartDueConcurrencyTest extends KernelTestCase
{
    private const LOCK_TIMEOUT_SECONDS = 2;
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private array $createdIds = ['missions' => [], 'users' => [], 'sites' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds['missions'] as $id) {
            $mission = $this->em->find(Mission::class, $id);
            if ($mission !== null) {
                foreach ($this->em->createQueryBuilder()->select('e')->from(AuditEvent::class, 'e')
                    ->where('e.mission = :m')->setParameter('m', $mission)->getQuery()->getResult() as $evt) {
                    $this->em->remove($evt);
                }
            }
        }
        $this->em->flush();
        foreach ($this->createdIds['missions'] as $id) {
            $e = $this->em->find(Mission::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        foreach ($this->createdIds['users'] as $id) {
            $e = $this->em->find(User::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        foreach ($this->createdIds['sites'] as $id) {
            $e = $this->em->find(Hospital::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        parent::tearDown();
    }

    private function freshEntityManager(): EntityManagerInterface
    {
        return new \Doctrine\ORM\EntityManager(
            \Doctrine\DBAL\DriverManager::getConnection($this->em->getConnection()->getParams()),
            $this->em->getConfiguration(),
        );
    }

    private function setLockTimeout(EntityManagerInterface $em, int $seconds): void
    {
        $em->getConnection()->executeStatement("SET SESSION innodb_lock_wait_timeout = {$seconds}");
    }

    private function isLockTimeoutError(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'Lock wait timeout') || str_contains($e->getMessage(), '1205');
    }

    private function postDeployServiceFor(EntityManagerInterface $em): MissionPostDeployService
    {
        $container = self::getContainer();
        return new MissionPostDeployService(
            $em,
            $container->get(MessageBusInterface::class),
            new AuditService($em),
            $container->get(MissionEligibilityService::class),
        );
    }

    private function nowInAppTimezone(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('D064Conc-' . bin2hex(random_bytes(3)));
        $this->em->persist($h); $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('d064conc-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('D064Conc');
        $u->setLastname('Test');
        $this->em->persist($u); $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeAssignedMission(User $surgeon, User $instrumentist, Hospital $site): Mission
    {
        $m = new Mission();
        $m->setStatus(MissionStatus::ASSIGNED);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setSurgeon($surgeon);
        $m->setInstrumentist($instrumentist);
        $m->setSite($site);
        $m->setStartAt($this->nowInAppTimezone()->modify('-10 minutes'));
        $m->setEndAt($this->nowInAppTimezone()->modify('+50 minutes'));
        $m->setCreatedBy($surgeon);
        $this->em->persist($m); $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    /**
     * find() by primary key, never findOneBy() — on a manually-constructed
     * EntityManager (new EntityManager($conn, $config), used to simulate a second
     * connection), findOneBy()'s DQL-hydrated result was empirically observed NOT to be
     * registered in that EntityManager's own UnitOfWork (contains()=false,
     * state=DETACHED immediately after the call returns) even though find() by id on
     * the exact same EntityManager works correctly (contains()=true, state=MANAGED) —
     * a difference specific to this manual-EntityManager test setup, not a real
     * application bug. Resolving the id once via the fixture EM and always fetching by
     * id sidesteps it entirely.
     */
    private function findSystemActor(EntityManagerInterface $em, int $systemActorId): User
    {
        $actor = $em->find(User::class, $systemActorId);
        self::assertNotNull($actor, 'system@surgicalhub.internal must exist (Version20260715064809) for this test to run.');
        return $actor;
    }

    private function systemActorId(): int
    {
        $actor = $this->em->getRepository(User::class)->findOneBy(['email' => 'system@surgicalhub.internal']);
        self::assertNotNull($actor, 'system@surgicalhub.internal must exist (Version20260715064809) for this test to run.');
        return $actor->getId();
    }

    public function test_two_concurrent_start_due_runs_cannot_double_process_the_same_mission(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $mission = $this->makeAssignedMission($surgeon, $instr, $site);
        $missionId = $mission->getId();
        $systemActorId = $this->systemActorId();

        // Worker B: replicate start()'s lock+check+mutate+audit manually, WITHOUT
        // committing — holds the pessimistic lock open, exactly what a slow first
        // invocation of the real command would do mid-transaction.
        $emB = $this->freshEntityManager();
        $missionB = $emB->find(Mission::class, $missionId);
        $actorB = $this->findSystemActor($emB, $systemActorId);
        $emB->getConnection()->beginTransaction();
        $emB->lock($missionB, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
        self::assertSame(MissionStatus::ASSIGNED, $missionB->getStatus());
        $missionB->setStatus(MissionStatus::IN_PROGRESS);
        $auditB = new AuditEvent();
        $auditB->setMission($missionB)->setActor($actorB)
            ->setEventType(\App\Enum\AuditEventType::MISSION_STARTED)
            ->setPayload(['actorId' => $actorB->getId(), 'actorName' => 'system']);
        $emB->persist($auditB);
        $emB->flush();
        // Volontairement pas de commit() ici — transaction laissée ouverte, verrou tenu.

        // Worker A: a second invocation of start() for the SAME mission while B holds
        // the lock, under a short lock-wait timeout — a deterministic MySQL timeout
        // proves the lock genuinely blocks, never a lucky race on scheduling.
        $emA = $this->freshEntityManager();
        $this->setLockTimeout($emA, self::LOCK_TIMEOUT_SECONDS);
        $missionA = $emA->find(Mission::class, $missionId);
        $actorA = $this->findSystemActor($emA, $systemActorId);
        $serviceA = $this->postDeployServiceFor($emA);

        $blocked = false;
        try {
            $serviceA->start($missionA, $actorA);
        } catch (\Throwable $e) {
            $blocked = $this->isLockTimeoutError($e);
        }
        self::assertTrue($blocked, 'A second concurrent start() must be genuinely blocked by the lock B holds, not succeed silently.');

        // Release B.
        $emB->getConnection()->commit();

        // A retries with a fresh EntityManager (wrapInTransaction closed the previous
        // one on exception) — the mission is now IN_PROGRESS, so start() must refuse
        // cleanly (ConflictHttpException) rather than silently re-processing it.
        $emA2 = $this->freshEntityManager();
        $missionA2 = $emA2->find(Mission::class, $missionId);
        $actorA2 = $this->findSystemActor($emA2, $systemActorId);
        $serviceA2 = $this->postDeployServiceFor($emA2);

        $this->expectException(ConflictHttpException::class);
        try {
            $serviceA2->start($missionA2, $actorA2);
        } finally {
            // Regardless of the exception, confirm only ONE audit event exists for
            // this mission — the real invariant this test protects.
            $this->em->clear();
            $events = $this->em->createQueryBuilder()->select('e')->from(AuditEvent::class, 'e')
                ->where('e.mission = :m')->setParameter('m', $missionId)
                ->getQuery()->getResult();
            self::assertCount(1, $events, 'Exactly one MISSION_STARTED audit event must exist despite the concurrent attempt.');
        }
    }

    public function test_command_skips_a_mission_already_started_by_a_concurrent_run_instead_of_aborting(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr1 = $this->makeUser('ROLE_INSTRUMENTIST');
        $instr2 = $this->makeUser('ROLE_INSTRUMENTIST');
        $missionAlreadyStarted = $this->makeAssignedMission($surgeon, $instr1, $site);
        $missionStillDue = $this->makeAssignedMission($surgeon, $instr2, $site);

        // Simulate "already started by a concurrent run": flip it to IN_PROGRESS
        // directly, bypassing the command, before running the real command.
        $missionAlreadyStarted->setStatus(MissionStatus::IN_PROGRESS);
        $this->em->flush();

        $tester = new CommandTester(new MissionStartDueCommand(
            $this->em,
            self::getContainer()->get(MissionPostDeployService::class),
        ));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), 'A ConflictHttpException on one mission must not abort the whole batch.');
        $this->em->refresh($missionStillDue);
        self::assertSame(
            MissionStatus::IN_PROGRESS,
            $missionStillDue->getStatus(),
            'Other due missions in the same run must still be processed even if one was already started concurrently.',
        );
    }
}
