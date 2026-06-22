<?php

namespace App\Tests\Unit\Command;

use App\Command\PlanningDeploymentReconcileStuckCommand;
use App\Entity\PlanningDeployment;
use App\Enum\PlanningDeploymentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Batch 11 Fix 3 (D): watchdog/recovery command for PlanningDeployment rows stuck on
 * PROCESSING after a worker crash that never reached its own try/catch (e.g. a
 * non-catchable PHP OOM fatal during DomPDF generation).
 */
class PlanningDeploymentReconcileStuckCommandTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private array $queryResult = [];

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->queryResult = [];

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

    private function makeDeployment(\DateTimeImmutable $startedAt): PlanningDeployment
    {
        $d = new PlanningDeployment();
        $d->setStatus(PlanningDeploymentStatus::PROCESSING);
        $d->setStartedAt($startedAt);
        return $d;
    }

    public function test_marks_stuck_deployment_as_failed_with_useful_error_log(): void
    {
        $stuck = $this->makeDeployment(new \DateTimeImmutable('-30 minutes'));
        $this->queryResult = [$stuck];

        $this->em->expects($this->once())->method('flush');

        $tester = new CommandTester(new PlanningDeploymentReconcileStuckCommand($this->em));
        $exitCode = $tester->execute(['--threshold-minutes' => '10']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(PlanningDeploymentStatus::FAILED, $stuck->getStatus());
        $this->assertNotNull($stuck->getErrorLog());
        $this->assertStringContainsString('watchdog', $stuck->getErrorLog());
        $this->assertNull($stuck->getCompletedAt(), 'Watchdog recovery is not a successful completion');
    }

    public function test_reports_success_with_no_changes_when_nothing_is_stuck(): void
    {
        $this->queryResult = [];

        $this->em->expects($this->never())->method('flush');

        $tester = new CommandTester(new PlanningDeploymentReconcileStuckCommand($this->em));
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('No stuck deployments', $tester->getDisplay());
    }

    public function test_rejects_invalid_threshold(): void
    {
        $tester = new CommandTester(new PlanningDeploymentReconcileStuckCommand($this->em));
        $exitCode = $tester->execute(['--threshold-minutes' => '0']);

        $this->assertSame(Command::FAILURE, $exitCode);
    }

    public function test_marks_multiple_stuck_deployments(): void
    {
        $a = $this->makeDeployment(new \DateTimeImmutable('-1 hour'));
        $b = $this->makeDeployment(new \DateTimeImmutable('-2 hours'));
        $this->queryResult = [$a, $b];

        $tester = new CommandTester(new PlanningDeploymentReconcileStuckCommand($this->em));
        $tester->execute([]);

        $this->assertSame(PlanningDeploymentStatus::FAILED, $a->getStatus());
        $this->assertSame(PlanningDeploymentStatus::FAILED, $b->getStatus());
    }
}
