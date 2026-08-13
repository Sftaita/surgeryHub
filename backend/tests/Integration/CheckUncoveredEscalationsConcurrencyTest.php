<?php

namespace App\Tests\Integration;

use App\Command\CheckUncoveredEscalationsCommand;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\AuditEventType;
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
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-110 (J-14) — same proof technique as MissionStartDueConcurrencyTest: two independent
 * DBAL connections, one holds an uncommitted transaction (lock taken, not released) while
 * the other attempts the same mutation under a short innodb_lock_wait_timeout — a
 * deterministic MySQL timeout proves real blocking, never a lucky race on scheduling.
 */
final class CheckUncoveredEscalationsConcurrencyTest extends KernelTestCase
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
        $h->setName('D110Conc-' . bin2hex(random_bytes(3)));
        $this->em->persist($h); $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('d110conc-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('D110Conc');
        $u->setLastname('Test');
        $this->em->persist($u); $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeOpenMission(User $surgeon, Hospital $site): Mission
    {
        $m = new Mission();
        $m->setStatus(MissionStatus::OPEN);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setSurgeon($surgeon);
        $m->setSite($site);
        $m->setStartAt($this->nowInAppTimezone()->modify('+10 days'));
        $m->setEndAt($this->nowInAppTimezone()->modify('+10 days')->modify('+4 hours'));
        $m->setCreatedBy($surgeon);
        $this->em->persist($m); $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

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

    public function test_two_concurrent_escalation_runs_cannot_double_send_for_the_same_mission(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeOpenMission($surgeon, $site);
        $missionId = $mission->getId();
        $systemActorId = $this->systemActorId();

        // Worker B: replicate markUncoveredEscalationSent()'s lock+check+mutate+audit
        // manually, WITHOUT committing — holds the pessimistic lock open.
        $emB = $this->freshEntityManager();
        $missionB = $emB->find(Mission::class, $missionId);
        $actorB = $this->findSystemActor($emB, $systemActorId);
        $emB->getConnection()->beginTransaction();
        $emB->lock($missionB, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
        self::assertSame(MissionStatus::OPEN, $missionB->getStatus());
        self::assertNull($missionB->getUncoveredEscalationSentAt());
        $missionB->setUncoveredEscalationSentAt(new \DateTimeImmutable());
        $auditB = new AuditEvent();
        $auditB->setMission($missionB)->setActor($actorB)
            ->setEventType(AuditEventType::MISSION_UNCOVERED_ESCALATION_SENT)
            ->setPayload(['actorId' => $actorB->getId()]);
        $emB->persist($auditB);
        $emB->flush();
        // Deliberately no commit() here — transaction left open, lock held.

        // Worker A: a second invocation for the SAME mission while B holds the lock,
        // under a short lock-wait timeout.
        $emA = $this->freshEntityManager();
        $this->setLockTimeout($emA, self::LOCK_TIMEOUT_SECONDS);
        $missionA = $emA->find(Mission::class, $missionId);
        $actorA = $this->findSystemActor($emA, $systemActorId);
        $serviceA = $this->postDeployServiceFor($emA);

        $blocked = false;
        try {
            $serviceA->markUncoveredEscalationSent($missionA, $actorA);
        } catch (\Throwable $e) {
            $blocked = $this->isLockTimeoutError($e);
        }
        self::assertTrue($blocked, 'A second concurrent markUncoveredEscalationSent() must be genuinely blocked by the lock B holds.');

        // Release B.
        $emB->getConnection()->commit();

        // A retries with a fresh EntityManager — the marker is now set, so this run
        // must find it already applied and return false (no double audit/notification).
        $emA2 = $this->freshEntityManager();
        $missionA2 = $emA2->find(Mission::class, $missionId);
        $actorA2 = $this->findSystemActor($emA2, $systemActorId);
        $serviceA2 = $this->postDeployServiceFor($emA2);

        $marked = $serviceA2->markUncoveredEscalationSent($missionA2, $actorA2);
        self::assertFalse($marked, 'The marker was already set by B — a second attempt must be a clean no-op, never a double escalation.');

        $this->em->clear();
        $events = $this->em->createQueryBuilder()->select('e')->from(AuditEvent::class, 'e')
            ->where('e.mission = :m')->setParameter('m', $missionId)
            ->getQuery()->getResult();
        self::assertCount(1, $events, 'Exactly one MISSION_UNCOVERED_ESCALATION_SENT audit event must exist despite the concurrent attempt.');
    }

    public function test_two_full_command_runs_launched_back_to_back_send_exactly_one_notification(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $this->makeOpenMission($surgeon, $site);

        $postDeploy = self::getContainer()->get(MissionPostDeployService::class);
        $bus        = self::getContainer()->get(MessageBusInterface::class);

        $tester1 = new CommandTester(new CheckUncoveredEscalationsCommand($this->em, $postDeploy, $bus));
        $tester2 = new CommandTester(new CheckUncoveredEscalationsCommand($this->em, $postDeploy, $bus));

        $tester1->execute([]);
        $tester2->execute([]);

        self::assertSame(Command::SUCCESS, $tester1->getStatusCode());
        self::assertSame(Command::SUCCESS, $tester2->getStatusCode());

        /** @var \Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $messages = array_values(array_filter(
            $transport->getSent(),
            fn ($envelope) => $envelope->getMessage() instanceof \App\Message\MissionUncoveredEscalationMessage,
        ));
        self::assertCount(1, $messages, 'Two sequential full-command runs must still produce exactly one escalation message.');
    }
}
