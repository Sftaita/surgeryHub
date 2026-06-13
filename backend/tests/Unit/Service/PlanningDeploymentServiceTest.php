<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningDeployment;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningDeploymentStatus;
use App\Enum\PlanningVersionStatus;
use App\Enum\SchedulePrecision;
use App\Message\PlanningDeployPdfsMessage;
use App\Message\SendBillingEmailMessage;
use App\Service\PlanningDeploymentService;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Tests PlanningDeploymentService::deploy():
 *
 * Status rules (Lot 1):
 *   DRAFT + instrumentist IS NOT NULL  → ASSIGNED
 *   DRAFT + instrumentist IS NULL + selected → OPEN
 *   DRAFT + instrumentist IS NULL + unselected → stays DRAFT
 *
 * Async: PlanningDeployPdfsMessage dispatched with deploymentId + openUncoveredIds + sendChangeSummary.
 * Idempotence guard: PlanningDeployment.status starts at PENDING; handler sets DONE on completion.
 */
class PlanningDeploymentServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private MessageBusInterface&MockObject    $bus;

    private array $persisted  = [];
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->em  = $this->createMock(EntityManagerInterface::class);
        $this->bus = $this->createMock(MessageBusInterface::class);

        $this->persisted  = [];
        $this->dispatched = [];

        $this->em->method('persist')->willReturnCallback(
            function (object $e): void { $this->persisted[] = $e; }
        );
        $this->em->method('flush');

        $this->bus->method('dispatch')->willReturnCallback(function (object $msg): Envelope {
            $this->dispatched[] = $msg;
            return new Envelope($msg);
        });
    }

    // ── Factories ─────────────────────────────────────────────────────────────

    private function makeUser(string $email, array $roles = ['ROLE_MANAGER']): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setRoles($roles);
        $u->setActive(true);
        $rp = new \ReflectionProperty(User::class, 'id');
        $rp->setValue($u, 1);
        return $u;
    }

    private function makeVersion(PlanningVersionStatus $status = PlanningVersionStatus::DRAFT): PlanningVersion
    {
        $v = new PlanningVersion();
        $v->setPeriodStart(new \DateTimeImmutable('2026-03-23'));
        $v->setPeriodEnd(new \DateTimeImmutable('2026-03-27'));
        $v->setGeneratedBy($this->makeUser('manager@test.com'));
        $v->setStatus($status);
        $rp = new \ReflectionProperty(PlanningVersion::class, 'id');
        $rp->setValue($v, 42);
        return $v;
    }

    private function makeService(): PlanningDeploymentService
    {
        return new PlanningDeploymentService($this->em, $this->bus);
    }

    /**
     * Sets up the EntityManager mock for a deploy with a known version.
     *
     * DQL routing:
     *   UPDATE … IS NOT NULL  → execute() returns $assignedCount  (ASSIGNED bulk)
     *   UPDATE … IN (:ids)    → execute() returns $poolCount      (OPEN bulk)
     *   anything else         → execute()/getResult() return 0/[]
     */
    private function setupEmForVersion(
        PlanningVersion $version,
        int $assignedCount = 0,
        int $poolCount = 0,
    ): void {
        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match ($class) {
                PlanningVersion::class => $id === 42 ? $version : null,
                default                => null,
            });

        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql) use ($assignedCount, $poolCount): AbstractQuery {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                $q->method('getOneOrNullResult')->willReturn(null);

                if (str_contains($dql, 'UPDATE') && str_contains($dql, 'IS NOT NULL')) {
                    // Bulk ASSIGNED update (pre-assigned missions)
                    $q->method('execute')->willReturn($assignedCount);
                } elseif (str_contains($dql, 'UPDATE') && str_contains($dql, 'IN (:ids)')) {
                    // Bulk OPEN update (selected uncovered missions)
                    $q->method('execute')->willReturn($poolCount);
                } else {
                    $q->method('execute')->willReturn(0);
                    $q->method('getResult')->willReturn([]);
                }

                return $q;
            });

        $this->em->method('clear');

        // getReference() is used for deployedBy after em->clear() detaches the User.
        // Return a simple User so setDeployedBy() doesn't throw.
        $this->em->method('getReference')
            ->willReturnCallback(fn ($class, $id) => match ($class) {
                User::class => $this->makeUser('ref@test.com'),
                default     => null,
            });

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $q = $this->createMock(Query::class);
        $q->method('getOneOrNullResult')->willReturn(null);
        $q->method('getResult')->willReturn([]);
        $qb->method('getQuery')->willReturn($q);
        $this->em->method('createQueryBuilder')->willReturn($qb);
    }

    // ── Version lifecycle ─────────────────────────────────────────────────────

    public function test_deploy_activates_draft_version(): void
    {
        $version = $this->makeVersion(PlanningVersionStatus::DRAFT);
        $this->setupEmForVersion($version);

        $this->makeService()->deploy('2026-03-23', '2026-03-27', null, $this->makeUser('mgr@test.com'), 42);

        $this->assertSame(PlanningVersionStatus::ACTIVE, $version->getStatus());
        $this->assertNotNull($version->getDeployedAt());
    }

    public function test_deploy_records_planning_deployment_entity(): void
    {
        $version = $this->makeVersion();
        $this->setupEmForVersion($version);

        $this->makeService()->deploy('2026-03-23', '2026-03-27', null, $this->makeUser('mgr@test.com'), 42);

        $deployments = array_filter($this->persisted, fn ($e) => $e instanceof PlanningDeployment);
        $this->assertCount(1, $deployments);
    }

    public function test_deploy_creates_planning_deployment_with_pending_status(): void
    {
        $version = $this->makeVersion();
        $this->setupEmForVersion($version);

        $this->makeService()->deploy('2026-03-23', '2026-03-27', null, $this->makeUser('mgr@test.com'), 42);

        /** @var PlanningDeployment[] $deployments */
        $deployments = array_values(array_filter($this->persisted, fn ($e) => $e instanceof PlanningDeployment));
        $this->assertCount(1, $deployments);
        $this->assertSame(PlanningDeploymentStatus::PENDING, $deployments[0]->getStatus(),
            'PlanningDeployment must be created with PENDING status — the worker sets DONE/FAILED.'
        );
    }

    // ── ASSIGNED bulk UPDATE (missions with instrumentist) ────────────────────

    public function test_deploy_assigned_missions_use_bulk_dql_assigned_status(): void
    {
        // The bulk UPDATE must target missions WHERE instrumentist IS NOT NULL → ASSIGNED.
        // This test verifies the returned assignedCount comes from that UPDATE.
        $version = $this->makeVersion();
        $this->setupEmForVersion($version, assignedCount: 5);

        $result = $this->makeService()->deploy('2026-03-23', '2026-03-27', null, $this->makeUser('mgr@test.com'), 42);

        $this->assertSame(5, $result['missionCount'],
            'missionCount must equal the number of pre-assigned missions updated to ASSIGNED.'
        );
    }

    // ── OPEN bulk UPDATE (selected uncovered missions) ─────────────────────────

    public function test_deploy_selected_uncovered_missions_become_open(): void
    {
        $version = $this->makeVersion();
        $this->setupEmForVersion($version, assignedCount: 3, poolCount: 2);

        $result = $this->makeService()->deploy(
            '2026-03-23', '2026-03-27', null,
            $this->makeUser('mgr@test.com'), 42,
            selectedUncoveredMissionIds: [101, 102], // 2 missions published as pool
        );

        $this->assertSame(5, $result['missionCount'],   '3 ASSIGNED + 2 OPEN');
        $this->assertSame(2, $result['openPoolCount'],  '2 selected uncovered → OPEN');
    }

    public function test_deploy_with_no_selected_uncovered_missions_skips_pool_update(): void
    {
        // When selectedUncoveredMissionIds is empty, no IN(:ids) UPDATE must be executed.
        // The mock would return poolCount=99 if the UPDATE were called — but it won't be.
        $version = $this->makeVersion();
        $this->setupEmForVersion($version, assignedCount: 4, poolCount: 99);

        $result = $this->makeService()->deploy(
            '2026-03-23', '2026-03-27', null,
            $this->makeUser('mgr@test.com'), 42,
            selectedUncoveredMissionIds: [],
        );

        $this->assertSame(4, $result['missionCount'],  'No pool update → only assigned count');
        $this->assertSame(0, $result['openPoolCount'], 'No IDs selected → pool count is 0');
    }

    public function test_deploy_returns_assigned_plus_pool_in_mission_count(): void
    {
        $version = $this->makeVersion();
        $this->setupEmForVersion($version, assignedCount: 10, poolCount: 4);

        $result = $this->makeService()->deploy(
            '2026-03-23', '2026-03-27', null,
            $this->makeUser('mgr@test.com'), 42,
            selectedUncoveredMissionIds: [201, 202, 203, 204],
        );

        $this->assertArrayHasKey('missionCount',  $result);
        $this->assertArrayHasKey('openPoolCount', $result);
        $this->assertSame(14, $result['missionCount'],  '10 ASSIGNED + 4 OPEN');
        $this->assertSame(4,  $result['openPoolCount']);
    }

    // ── Async message ─────────────────────────────────────────────────────────

    public function test_deploy_dispatches_async_pdf_message(): void
    {
        $version = $this->makeVersion();
        $this->setupEmForVersion($version);

        $manager = $this->makeUser('mgr@test.com');
        $this->makeService()->deploy('2026-03-23', '2026-03-27', null, $manager, 42);

        $pdfMessages = array_filter($this->dispatched, fn ($m) => $m instanceof PlanningDeployPdfsMessage);
        $this->assertCount(1, $pdfMessages,
            'A PlanningDeployPdfsMessage must be dispatched for async work.'
        );

        /** @var PlanningDeployPdfsMessage $msg */
        $msg = array_values($pdfMessages)[0];
        $this->assertSame('2026-03-23', $msg->from);
        $this->assertSame('2026-03-27', $msg->to);
        $this->assertSame($manager->getId(), $msg->deployedById);
    }

    public function test_deploy_message_carries_open_uncovered_ids(): void
    {
        $version = $this->makeVersion();
        $this->setupEmForVersion($version, assignedCount: 2, poolCount: 2);

        $this->makeService()->deploy(
            '2026-03-23', '2026-03-27', null,
            $this->makeUser('mgr@test.com'), 42,
            selectedUncoveredMissionIds: [55, 66],
        );

        /** @var PlanningDeployPdfsMessage $msg */
        $msg = array_values(array_filter($this->dispatched, fn ($m) => $m instanceof PlanningDeployPdfsMessage))[0];
        $this->assertSame([55, 66], $msg->openUncoveredIds,
            'The message must carry exactly the selected uncovered IDs for pool notification.'
        );
    }

    public function test_deploy_message_carries_send_change_summary_flag(): void
    {
        $version = $this->makeVersion();
        $this->setupEmForVersion($version);

        $this->makeService()->deploy(
            '2026-03-23', '2026-03-27', null,
            $this->makeUser('mgr@test.com'), 42,
            sendChangeSummary: true,
        );

        /** @var PlanningDeployPdfsMessage $msg */
        $msg = array_values(array_filter($this->dispatched, fn ($m) => $m instanceof PlanningDeployPdfsMessage))[0];
        $this->assertTrue($msg->sendChangeSummary);
    }

    public function test_deploy_does_not_generate_pdfs_synchronously(): void
    {
        // PDFs run in the Messenger worker — no email must be dispatched synchronously.
        $version = $this->makeVersion();
        $this->setupEmForVersion($version);

        $this->makeService()->deploy('2026-03-23', '2026-03-27', null, $this->makeUser('mgr@test.com'), 42);

        $emailMessages = array_filter($this->dispatched, fn ($m) => $m instanceof SendBillingEmailMessage);
        $this->assertCount(0, $emailMessages,
            'No email must be dispatched synchronously — emails are sent by the Messenger worker.'
        );
    }

    // ── Legacy path (no versionId) ────────────────────────────────────────────

    public function test_deploy_without_version_works_legacy(): void
    {
        $this->em->method('find')->willReturn(null);
        $this->em->method('getReference')
            ->willReturnCallback(fn ($class, $id) => match ($class) {
                User::class => $this->makeUser('ref@test.com'),
                default     => null,
            });

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $q = $this->createMock(Query::class);
        $q->method('getOneOrNullResult')->willReturn(null);
        $q->method('getResult')->willReturn([]);
        $qb->method('getQuery')->willReturn($q);
        $this->em->method('createQueryBuilder')->willReturn($qb);
        $this->em->method('createQuery')->willReturnCallback(function (): AbstractQuery {
            $q = $this->createMock(Query::class);
            $q->method('setParameter')->willReturnSelf();
            $q->method('execute')->willReturn(7);
            $q->method('getResult')->willReturn([]);
            return $q;
        });

        $result = $this->makeService()->deploy('2026-03-23', '2026-03-27', null, $this->makeUser('mgr@test.com'));

        $this->assertArrayHasKey('missionCount', $result);

        // Async PDF message must still be dispatched even in legacy mode
        $pdfMessages = array_filter($this->dispatched, fn ($m) => $m instanceof PlanningDeployPdfsMessage);
        $this->assertCount(1, $pdfMessages);

        /** @var PlanningDeployPdfsMessage $msg */
        $msg = array_values($pdfMessages)[0];
        $this->assertSame([], $msg->openUncoveredIds,
            'Legacy path: openUncoveredIds is empty (no selection possible without versionId).'
        );
        $this->assertFalse($msg->sendChangeSummary);
    }

    // ── REGRESSION — cascade persist after em->clear() ───────────────────────

    /**
     * REGRESSION D-cascade-persist:
     * After the bulk DQL UPDATE, em->clear() detaches ALL entities from the identity map
     * (Doctrine ORM 3.x: the class-name argument is silently ignored).
     * The $deployedBy User object passed in becomes "detached".
     * If the service calls setDeployedBy($deployedBy) directly, flush() throws:
     *   "A new entity was found through PlanningDeployment#deployedBy that was not
     *    configured to cascade persist — App\Entity\User@XXX"
     *
     * Fix: use em->getReference(User::class, $id) which returns a managed proxy
     * without hitting the DB.
     *
     * This test verifies that getReference() is called with the correct class and ID,
     * and that the resulting PlanningDeployment has a non-null deployedBy.
     */
    public function test_deploy_calls_getReference_for_deployedBy_to_survive_em_clear(): void
    {
        $version = $this->makeVersion();
        $manager = $this->makeUser('mgr@test.com'); // id = 1 (set via reflection)

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match ($class) {
                PlanningVersion::class => $id === 42 ? $version : null,
                default => null,
            });

        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql): AbstractQuery {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                $q->method('getOneOrNullResult')->willReturn(null);
                $q->method('execute')->willReturn(0);
                $q->method('getResult')->willReturn([]);
                return $q;
            });

        $this->em->method('clear');

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $q = $this->createMock(Query::class);
        $q->method('getOneOrNullResult')->willReturn(null);
        $q->method('getResult')->willReturn([]);
        $qb->method('getQuery')->willReturn($q);
        $this->em->method('createQueryBuilder')->willReturn($qb);

        // KEY: verify getReference is called with User::class + the manager's ID.
        // Without this call, flush() would throw a cascade persist error after em->clear().
        $this->em->expects($this->once())
            ->method('getReference')
            ->with(User::class, $manager->getId())
            ->willReturn($manager);

        $this->makeService()->deploy('2026-03-23', '2026-03-27', null, $manager, 42);

        // The persisted PlanningDeployment must have deployedBy set (not null)
        $deployments = array_values(array_filter(
            $this->persisted,
            fn ($e) => $e instanceof PlanningDeployment,
        ));
        $this->assertCount(1, $deployments);
        $this->assertNotNull(
            $deployments[0]->getDeployedBy(),
            'REGRESSION: PlanningDeployment.deployedBy must be set via getReference() '
            . 'after em->clear() — using the raw $deployedBy object would throw a cascade persist error.'
        );
    }

    /**
     * Same regression on the legacy path (no versionId).
     * em->clear() is also called in the legacy bulk UPDATE path.
     */
    public function test_deploy_legacy_calls_getReference_for_deployedBy(): void
    {
        $manager = $this->makeUser('mgr@test.com'); // id = 1

        $this->em->method('find')->willReturn(null);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $q = $this->createMock(Query::class);
        $q->method('getOneOrNullResult')->willReturn(null);
        $q->method('getResult')->willReturn([]);
        $qb->method('getQuery')->willReturn($q);
        $this->em->method('createQueryBuilder')->willReturn($qb);

        $this->em->method('createQuery')->willReturnCallback(function (): AbstractQuery {
            $q = $this->createMock(Query::class);
            $q->method('setParameter')->willReturnSelf();
            $q->method('execute')->willReturn(0);
            $q->method('getResult')->willReturn([]);
            return $q;
        });

        $this->em->method('clear');

        $this->em->expects($this->once())
            ->method('getReference')
            ->with(User::class, $manager->getId())
            ->willReturn($manager);

        $this->makeService()->deploy('2026-03-23', '2026-03-27', null, $manager);

        $deployments = array_values(array_filter(
            $this->persisted,
            fn ($e) => $e instanceof PlanningDeployment,
        ));
        $this->assertCount(1, $deployments);
        $this->assertNotNull($deployments[0]->getDeployedBy(),
            'REGRESSION: legacy path must also use getReference() after em->clear().'
        );
    }

    // ── SendBillingEmailMessage shape test (regression) ──────────────────────

    public function test_extra_attachments_field_on_send_billing_email_message(): void
    {
        $msg = new SendBillingEmailMessage(
            to: 'test@test.com',
            cc: [],
            subject: 'Test',
            fromAddress: 'from@test.com',
            fromName: 'Test',
            htmlTemplate: 'template.html.twig',
            extraAttachments: [['base64' => 'abc', 'filename' => 'file.pdf']],
        );

        $this->assertCount(1, $msg->extraAttachments);
        $this->assertSame('file.pdf', $msg->extraAttachments[0]['filename']);
    }
}
