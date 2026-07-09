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
 * Async: PlanningDeployPdfsMessage dispatched with deploymentId + openUncoveredIds.
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
     *   UPDATE … IS NULL      → execute() returns $poolCount      (OPEN bulk, all uncovered)
     *   SELECT m.id …         → getResult() returns $openMissionIdRows (RC1-A: pool IDs)
     *   anything else         → execute()/getResult() return 0/[]
     *
     * @param array<int> $openMissionIds IDs the SELECT query should return (simulates DB rows).
     */
    private function setupEmForVersion(
        PlanningVersion $version,
        int $assignedCount = 0,
        int $poolCount = 0,
        array $openMissionIds = [],
    ): void {
        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match ($class) {
                PlanningVersion::class => $id === 42 ? $version : null,
                default                => null,
            });

        $openIdRows = array_map(fn (int $id) => ['id' => $id], $openMissionIds);

        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql) use ($assignedCount, $poolCount, $openIdRows): AbstractQuery {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                $q->method('getOneOrNullResult')->willReturn(null);

                if (str_contains($dql, 'UPDATE') && str_contains($dql, 'IS NOT NULL')) {
                    // Bulk ASSIGNED update (pre-assigned missions)
                    $q->method('execute')->willReturn($assignedCount);
                } elseif (str_contains($dql, 'UPDATE') && str_contains($dql, 'IS NULL')) {
                    // Bulk OPEN update — all uncovered DRAFT missions
                    $q->method('execute')->willReturn($poolCount);
                } elseif (str_contains($dql, 'SELECT') && str_contains($dql, 'm.id')) {
                    // RC1-A step 4c: fetch IDs of newly-opened missions for pool notifications
                    $q->method('getResult')->willReturn($openIdRows);
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

    // ── OPEN bulk UPDATE — V2: all uncovered DRAFT → OPEN (Batch 15A) ──────────

    public function test_v2_deploy_all_uncovered_draft_become_open_without_selection(): void
    {
        // Batch 15A: ALL DRAFT missions without an instrumentist become OPEN automatically.
        // selectedUncoveredMissionIds is ignored for the V2 path.
        $version = $this->makeVersion();
        $this->setupEmForVersion($version, assignedCount: 3, poolCount: 4);

        $result = $this->makeService()->deploy(
            '2026-03-23', '2026-03-27', null,
            $this->makeUser('mgr@test.com'), 42,
            selectedUncoveredMissionIds: [], // ignored in V2 — all uncovered auto-published
        );

        $this->assertSame(7, $result['missionCount'],  '3 ASSIGNED + 4 auto-OPEN');
        $this->assertSame(4, $result['openPoolCount'], 'all 4 uncovered DRAFT become OPEN');
    }

    public function test_v2_deploy_draft_with_instrumentist_become_assigned(): void
    {
        // Regression: pre-assigned missions (instrumentist set) must still become ASSIGNED.
        $version = $this->makeVersion();
        $this->setupEmForVersion($version, assignedCount: 5, poolCount: 0);

        $result = $this->makeService()->deploy(
            '2026-03-23', '2026-03-27', null,
            $this->makeUser('mgr@test.com'), 42,
        );

        $this->assertSame(5, $result['missionCount'],  '5 pre-assigned missions → ASSIGNED');
        $this->assertSame(0, $result['openPoolCount'], 'no uncovered missions');
    }

    public function test_v2_deploy_returns_assigned_plus_pool_in_mission_count(): void
    {
        $version = $this->makeVersion();
        $this->setupEmForVersion($version, assignedCount: 10, poolCount: 4);

        $result = $this->makeService()->deploy(
            '2026-03-23', '2026-03-27', null,
            $this->makeUser('mgr@test.com'), 42,
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

    public function test_v2_deploy_message_carries_open_mission_ids_from_pool(): void
    {
        // RC1-A P0-1: V2 deploy must populate openUncoveredIds with the IDs of newly-opened
        // missions so the handler can fan out OPEN_MISSION_AVAILABLE notifications.
        $version = $this->makeVersion();
        $this->setupEmForVersion($version, assignedCount: 2, poolCount: 2, openMissionIds: [101, 102]);

        $this->makeService()->deploy(
            '2026-03-23', '2026-03-27', null,
            $this->makeUser('mgr@test.com'), 42,
            selectedUncoveredMissionIds: [55, 66], // V2 ignores this — uses queried IDs instead
        );

        /** @var PlanningDeployPdfsMessage $msg */
        $msg = array_values(array_filter($this->dispatched, fn ($m) => $m instanceof PlanningDeployPdfsMessage))[0];
        $this->assertSame([101, 102], $msg->openUncoveredIds,
            'V2: openUncoveredIds must contain the IDs from the post-deploy SELECT query, not the manager selection.'
        );
    }

    public function test_v2_deploy_message_carries_empty_ids_when_no_pool_missions(): void
    {
        // When poolCount=0 (all missions pre-assigned), the SELECT query does not run
        // and openUncoveredIds must be empty.
        $version = $this->makeVersion();
        $this->setupEmForVersion($version, assignedCount: 5, poolCount: 0);

        $this->makeService()->deploy(
            '2026-03-23', '2026-03-27', null,
            $this->makeUser('mgr@test.com'), 42,
        );

        /** @var PlanningDeployPdfsMessage $msg */
        $msg = array_values(array_filter($this->dispatched, fn ($m) => $m instanceof PlanningDeployPdfsMessage))[0];
        $this->assertSame([], $msg->openUncoveredIds,
            'No pool missions → openUncoveredIds must be empty (no SELECT query runs).'
        );
    }

    public function test_v1_deploy_legacy_uses_selected_ids(): void
    {
        // V1 legacy path (no versionId): selectedUncoveredMissionIds still carried on the message.
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
            $q->method('execute')->willReturn(3);
            $q->method('getResult')->willReturn([]);
            return $q;
        });

        $this->makeService()->deploy(
            '2026-03-23', '2026-03-27', null,
            $this->makeUser('mgr@test.com'),
            null, // no versionId → V1 legacy path
            selectedUncoveredMissionIds: [10, 20, 30],
        );

        /** @var PlanningDeployPdfsMessage $msg */
        $msg = array_values(array_filter($this->dispatched, fn ($m) => $m instanceof PlanningDeployPdfsMessage))[0];
        $this->assertSame([10, 20, 30], $msg->openUncoveredIds,
            'V1 legacy path must still pass selectedUncoveredMissionIds to the message.'
        );
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
