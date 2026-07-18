<?php

namespace App\Tests\Unit\Service;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionChangeType;
use App\Enum\MissionStatus;
use App\Message\MissionLifecycleChangedMessage;
use App\Service\AuditService;
use App\Service\MissionEncodingGuard;
use App\Service\MissionEncodingWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Lot 7 (D-070) — MissionEncodingWorkflowService covers the whole encoding lifecycle.
 * MissionEncodingGuard has no constructor dependencies, so a real instance is used rather
 * than a mock (it is declared `final`, which PHPUnit cannot double anyway).
 */
final class MissionEncodingWorkflowServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private AuditService&MockObject           $audit;
    private MessageBusInterface&MockObject    $bus;
    private MissionEncodingWorkflowService    $service;

    /** @var MissionLifecycleChangedMessage[] captured via the bus stub below — Envelope is final and cannot be mocked/asserted-on directly. */
    private array $dispatchedMessages = [];

    protected function setUp(): void
    {
        $this->em    = $this->createMock(EntityManagerInterface::class);
        $this->audit = $this->createMock(AuditService::class);
        $this->bus   = $this->createMock(MessageBusInterface::class);
        $this->dispatchedMessages = [];

        $this->bus->method('dispatch')->willReturnCallback(
            function (object $msg): Envelope {
                $this->dispatchedMessages[] = $msg;
                return new Envelope($msg);
            },
        );

        $this->service = new MissionEncodingWorkflowService(
            $this->em,
            new MissionEncodingGuard(),
            $this->audit,
            $this->bus,
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static int $nextId = 1;

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    private function makeActor(array $roles = ['ROLE_INSTRUMENTIST']): User
    {
        $u = new User();
        $u->setEmail('actor@test.com');
        $u->setFirstname('Ada');
        $u->setLastname('Lovelace');
        $u->setRoles($roles);
        $this->setId($u, self::$nextId++);
        return $u;
    }

    private function makeManager(): User
    {
        return $this->makeActor(['ROLE_MANAGER']);
    }

    /** startAt defaults to one hour in the past so the instrumentist guard check passes. */
    private function makeMission(MissionStatus $status, ?\DateTimeImmutable $startAt = null): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setStartAt($startAt ?? new \DateTimeImmutable('-1 hour'));
        $this->setId($m, self::$nextId++);
        return $m;
    }

    // ── start() ──────────────────────────────────────────────────────────────

    public function test_start_transitions_assigned_to_encoding_in_progress(): void
    {
        $mission = $this->makeMission(MissionStatus::ASSIGNED);
        $actor   = $this->makeActor();

        $this->audit->expects($this->once())->method('record')
            ->with($mission, $actor, AuditEventType::MISSION_ENCODING_STARTED, $this->anything());
        $this->em->expects($this->once())->method('flush');

        $this->service->start($mission, $actor);

        self::assertSame(MissionStatus::ENCODING_IN_PROGRESS, $mission->getStatus());
        self::assertNotNull($mission->getEncodingStartedAt());
        self::assertCount(1, $this->dispatchedMessages);
        self::assertSame(MissionChangeType::ENCODING_STARTED, $this->dispatchedMessages[0]->changeType);
    }

    public function test_start_transitions_in_progress_to_encoding_in_progress(): void
    {
        $mission = $this->makeMission(MissionStatus::IN_PROGRESS);
        $actor   = $this->makeActor();

        $this->service->start($mission, $actor);

        self::assertSame(MissionStatus::ENCODING_IN_PROGRESS, $mission->getStatus());
    }

    public function test_start_rejects_mission_not_assigned_or_in_progress(): void
    {
        $mission = $this->makeMission(MissionStatus::OPEN);
        $actor   = $this->makeActor();

        $this->em->expects($this->never())->method('flush');
        $this->expectException(ConflictHttpException::class);

        $this->service->start($mission, $actor);
    }

    public function test_start_rejects_before_mission_start_time_for_instrumentist(): void
    {
        $mission = $this->makeMission(MissionStatus::ASSIGNED, new \DateTimeImmutable('+1 hour'));
        $actor   = $this->makeActor(['ROLE_INSTRUMENTIST']);

        $this->em->expects($this->never())->method('flush');
        $this->expectException(ConflictHttpException::class);

        $this->service->start($mission, $actor);
    }

    public function test_start_allowed_for_manager_before_mission_start_time(): void
    {
        $mission = $this->makeMission(MissionStatus::ASSIGNED, new \DateTimeImmutable('+1 hour'));
        $actor   = $this->makeManager();

        $this->service->start($mission, $actor);

        self::assertSame(MissionStatus::ENCODING_IN_PROGRESS, $mission->getStatus());
    }

    public function test_start_rejected_when_encoding_already_locked(): void
    {
        $mission = $this->makeMission(MissionStatus::ASSIGNED);
        $mission->setEncodingLockedAt(new \DateTimeImmutable());
        $actor = $this->makeActor();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);

        $this->service->start($mission, $actor);
    }

    // ── complete() ───────────────────────────────────────────────────────────

    /** @return list<array{0: MissionStatus}> */
    public static function completableStatusesProvider(): array
    {
        return [
            [MissionStatus::DECLARED],
            [MissionStatus::ASSIGNED],
            [MissionStatus::IN_PROGRESS],
            [MissionStatus::ENCODING_IN_PROGRESS],
            [MissionStatus::SUBMITTED],
        ];
    }

    /** @dataProvider completableStatusesProvider */
    public function test_complete_transitions_to_submitted(MissionStatus $from): void
    {
        $mission = $this->makeMission($from);
        $actor   = $this->makeActor();

        $this->audit->expects($this->once())->method('record')
            ->with($mission, $actor, AuditEventType::MISSION_ENCODING_COMPLETED, $this->anything());

        $this->service->complete($mission, $actor);

        self::assertSame(MissionStatus::SUBMITTED, $mission->getStatus());
        self::assertNotNull($mission->getSubmittedAt());
    }

    /**
     * REJECTED is deliberately excluded: MissionEncodingGuard blocks it earlier with its
     * own AccessDeniedHttpException (a terminal, frozen status) — see the dedicated guard
     * test below. This list only covers statuses that reach complete()'s own status check.
     *
     * @return list<array{0: MissionStatus}>
     */
    public static function nonCompletableStatusesProvider(): array
    {
        return [
            [MissionStatus::DRAFT],
            [MissionStatus::OPEN],
            [MissionStatus::VALIDATED],
            [MissionStatus::CLOSED],
            [MissionStatus::CANCELLED],
        ];
    }

    /** @dataProvider nonCompletableStatusesProvider */
    public function test_complete_rejects_other_statuses(MissionStatus $from): void
    {
        $mission = $this->makeMission($from);
        $actor   = $this->makeActor(['ROLE_MANAGER']);

        $this->expectException(ConflictHttpException::class);

        $this->service->complete($mission, $actor);
    }

    public function test_complete_rejects_rejected_mission_via_guard(): void
    {
        $mission = $this->makeMission(MissionStatus::REJECTED);
        $actor   = $this->makeActor(['ROLE_MANAGER']);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);

        $this->service->complete($mission, $actor);
    }

    // ── validate() ───────────────────────────────────────────────────────────

    public function test_validate_transitions_submitted_to_validated_and_locks_encoding(): void
    {
        $mission = $this->makeMission(MissionStatus::SUBMITTED);
        $actor   = $this->makeManager();

        $this->audit->expects($this->once())->method('record')
            ->with($mission, $actor, AuditEventType::MISSION_ENCODING_VALIDATED, $this->anything());

        $this->service->validate($mission, $actor);

        self::assertSame(MissionStatus::VALIDATED, $mission->getStatus());
        self::assertNotNull($mission->getEncodingLockedAt());
        self::assertCount(1, $this->dispatchedMessages);
        self::assertSame(MissionChangeType::ENCODING_VALIDATED, $this->dispatchedMessages[0]->changeType);
    }

    /** @dataProvider nonCompletableStatusesProvider */
    public function test_validate_rejects_mission_not_submitted(MissionStatus $from): void
    {
        if ($from === MissionStatus::VALIDATED) {
            self::markTestSkipped('VALIDATED is covered by its own reopen tests, not relevant here.');
        }

        $mission = $this->makeMission($from);
        $actor   = $this->makeManager();

        $this->expectException(ConflictHttpException::class);

        $this->service->validate($mission, $actor);
    }

    // ── reject() ─────────────────────────────────────────────────────────────

    public function test_reject_transitions_submitted_to_encoding_in_progress_with_comment(): void
    {
        $mission = $this->makeMission(MissionStatus::SUBMITTED);
        $actor   = $this->makeManager();

        $this->em->expects($this->once())->method('persist')
            ->with($this->isInstanceOf(\App\Entity\MissionEncodingComment::class));
        $this->audit->expects($this->once())->method('record')
            ->with($mission, $actor, AuditEventType::MISSION_ENCODING_REJECTED, $this->callback(
                fn (array $payload) => ($payload['comment'] ?? null) === 'Matériel manquant',
            ));

        $this->service->reject($mission, $actor, 'Matériel manquant');

        self::assertSame(MissionStatus::ENCODING_IN_PROGRESS, $mission->getStatus());
        self::assertCount(1, $mission->getEncodingComments());
        self::assertSame('Matériel manquant', $mission->getEncodingComments()->first()->getComment());
    }

    public function test_reject_rejects_mission_not_submitted(): void
    {
        $mission = $this->makeMission(MissionStatus::ASSIGNED);
        $actor   = $this->makeManager();

        $this->em->expects($this->never())->method('persist');
        $this->expectException(ConflictHttpException::class);

        $this->service->reject($mission, $actor, 'Peu importe');
    }

    // ── reopen() ─────────────────────────────────────────────────────────────

    public function test_reopen_transitions_validated_to_encoding_in_progress_and_unlocks(): void
    {
        $mission = $this->makeMission(MissionStatus::VALIDATED);
        $mission->setEncodingLockedAt(new \DateTimeImmutable());
        $actor = $this->makeManager();

        $this->audit->expects($this->once())->method('record')
            ->with($mission, $actor, AuditEventType::MISSION_ENCODING_REOPENED, $this->anything());

        $this->service->reopen($mission, $actor, 'Quantité incorrecte');

        self::assertSame(MissionStatus::ENCODING_IN_PROGRESS, $mission->getStatus());
        self::assertNull($mission->getEncodingLockedAt());
        self::assertCount(1, $mission->getEncodingComments());
        self::assertCount(1, $this->dispatchedMessages);
        self::assertSame(MissionChangeType::ENCODING_REOPENED, $this->dispatchedMessages[0]->changeType);
    }

    public function test_reopen_without_comment_does_not_persist_a_comment(): void
    {
        $mission = $this->makeMission(MissionStatus::VALIDATED);
        $actor   = $this->makeManager();

        $this->em->expects($this->never())->method('persist');

        $this->service->reopen($mission, $actor, null);

        self::assertCount(0, $mission->getEncodingComments());
    }

    public function test_reopen_rejects_closed_mission_with_dedicated_message(): void
    {
        $mission = $this->makeMission(MissionStatus::CLOSED);
        $actor   = $this->makeManager();

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('A CLOSED mission can never be reopened');

        $this->service->reopen($mission, $actor);
    }

    public function test_reopen_rejects_mission_not_validated(): void
    {
        $mission = $this->makeMission(MissionStatus::SUBMITTED);
        $actor   = $this->makeManager();

        $this->expectException(ConflictHttpException::class);

        $this->service->reopen($mission, $actor);
    }

    // ── isBillable() / findMissionsReadyForBilling() ────────────────────────

    public function test_is_billable_true_only_for_validated(): void
    {
        self::assertTrue($this->service->isBillable($this->makeMission(MissionStatus::VALIDATED)));
        self::assertFalse($this->service->isBillable($this->makeMission(MissionStatus::SUBMITTED)));
        self::assertFalse($this->service->isBillable($this->makeMission(MissionStatus::CLOSED)));
        self::assertFalse($this->service->isBillable($this->makeMission(MissionStatus::ENCODING_IN_PROGRESS)));
    }

    public function test_find_missions_ready_for_billing_queries_validated_only(): void
    {
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->expects($this->once())->method('findBy')
            ->with(['status' => MissionStatus::VALIDATED])
            ->willReturn([]);

        $this->em->expects($this->once())->method('getRepository')
            ->with(Mission::class)
            ->willReturn($repository);

        self::assertSame([], $this->service->findMissionsReadyForBilling());
    }
}
