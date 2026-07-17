<?php

namespace App\Tests\Unit\Command;

use App\Command\MissionStartDueCommand;
use App\Entity\Mission;
use App\Entity\User;
use App\Service\MissionPostDeployService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * D-064: cron-style command that transitions due ASSIGNED missions to IN_PROGRESS.
 * See MissionPostDeployServiceTest for start()'s own transition/audit/dispatch coverage —
 * this file covers the command's own responsibilities: finding the system actor, running
 * the due-missions query, and calling start() once per result.
 */
class MissionStartDueCommandTest extends TestCase
{
    private EntityManagerInterface&MockObject    $em;
    private MissionPostDeployService&MockObject  $missionPostDeployService;
    private ?User $systemActorLookupResult;
    private array $queryResult = [];

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->missionPostDeployService = $this->createMock(MissionPostDeployService::class);
        $this->systemActorLookupResult = $this->makeSystemActor();
        $this->queryResult = [];

        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('findOneBy')
            ->with(['email' => 'system@surgicalhub.internal'])
            ->willReturnCallback(fn () => $this->systemActorLookupResult);

        $this->em->method('getRepository')
            ->with(User::class)
            ->willReturn($userRepo);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $q = $this->createMock(Query::class);
        $q->method('getResult')->willReturnCallback(fn () => $this->queryResult);
        $qb->method('getQuery')->willReturn($q);
        $this->em->method('createQueryBuilder')->willReturn($qb);
    }

    private static int $nextId = 1;

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    private function makeSystemActor(): User
    {
        $u = new User();
        $u->setEmail('system@surgicalhub.internal');
        $u->setFirstname('Système');
        $this->setId($u, self::$nextId++);
        return $u;
    }

    private function makeMission(): Mission
    {
        $m = new Mission();
        $this->setId($m, self::$nextId++);
        return $m;
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new MissionStartDueCommand($this->em, $this->missionPostDeployService));
    }

    public function test_starts_each_due_mission_via_the_service(): void
    {
        $a = $this->makeMission();
        $b = $this->makeMission();
        $this->queryResult = [$a, $b];

        $calls = [];
        $this->missionPostDeployService->expects($this->exactly(2))->method('start')
            ->willReturnCallback(function (Mission $m, User $actor) use (&$calls): void {
                $calls[] = [$m, $actor];
            });

        $exitCode = $this->tester()->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertCount(2, $calls);
        $this->assertSame($a, $calls[0][0]);
        $this->assertSame($b, $calls[1][0]);
        $this->assertSame($this->systemActorLookupResult, $calls[0][1], 'Actor passed to start() must be the system user');
    }

    public function test_reports_success_with_no_changes_when_nothing_is_due(): void
    {
        $this->queryResult = [];

        $this->missionPostDeployService->expects($this->never())->method('start');

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('No mission to start', $tester->getDisplay());
    }

    public function test_fails_with_clear_error_when_system_actor_is_missing(): void
    {
        $this->systemActorLookupResult = null;
        $this->queryResult = [$this->makeMission()];

        $this->missionPostDeployService->expects($this->never())->method('start');

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('system@surgicalhub.internal', $tester->getDisplay());
    }
}
