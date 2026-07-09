<?php

namespace App\Tests\Unit\Service;

use App\Dto\EligibilityResult;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\EligibilityReason;
use App\Enum\MissionChangeType;
use App\Enum\MissionStatus;
use App\Message\MissionLifecycleChangedMessage;
use App\Service\AuditService;
use App\Service\MissionEligibilityService;
use App\Service\MissionPostDeployService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class MissionPostDeployServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject    $em;
    private MessageBusInterface&MockObject       $bus;
    private AuditService&MockObject              $audit;
    private MissionEligibilityService&MockObject $eligibilityService;
    private MissionPostDeployService             $service;

    protected function setUp(): void
    {
        $this->em                 = $this->createMock(EntityManagerInterface::class);
        $this->bus                = $this->createMock(MessageBusInterface::class);
        $this->audit              = $this->createMock(AuditService::class);
        $this->eligibilityService = $this->createMock(MissionEligibilityService::class);

        $this->bus->method('dispatch')->willReturnCallback(
            fn (object $msg) => new Envelope($msg),
        );

        // Default: any evaluate() call returns eligible
        $this->eligibilityService->method('evaluate')
            ->willReturnCallback(fn (Mission $m, User $u) => new EligibilityResult($u, []));

        $this->service = new MissionPostDeployService(
            $this->em, $this->bus, $this->audit, $this->eligibilityService,
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

    private function makeActor(string $firstname = 'Manager', string $lastname = 'Test'): User
    {
        $u = new User();
        $u->setEmail('manager@test.com');
        $u->setFirstname($firstname);
        $u->setLastname($lastname);
        $this->setId($u, self::$nextId++);
        return $u;
    }

    private function makeInstrumentist(string $firstname = 'Ole', string $lastname = 'Salve'): User
    {
        $u = new User();
        $u->setEmail('instrumentist@test.com');
        $u->setFirstname($firstname);
        $u->setLastname($lastname);
        $this->setId($u, self::$nextId++);
        return $u;
    }

    private function makeMission(MissionStatus $status, ?User $instrumentist = null): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        $this->setId($m, self::$nextId++);
        return $m;
    }

    // ── release() ─────────────────────────────────────────────────────────────

    public function test_release_transitions_status_to_open(): void
    {
        $instrumentist = $this->makeInstrumentist();
        $mission       = $this->makeMission(MissionStatus::ASSIGNED, $instrumentist);
        $actor         = $this->makeActor();

        $this->em->expects($this->once())->method('flush');

        $this->service->release($mission, $actor);

        $this->assertSame(MissionStatus::OPEN, $mission->getStatus());
    }

    public function test_release_clears_instrumentist_reference(): void
    {
        $instrumentist = $this->makeInstrumentist();
        $mission       = $this->makeMission(MissionStatus::ASSIGNED, $instrumentist);
        $actor         = $this->makeActor();

        $this->em->method('flush');

        $this->service->release($mission, $actor);

        $this->assertNull($mission->getInstrumentist());
    }

    public function test_release_creates_audit_event_with_instrumentist_snapshot(): void
    {
        $instrumentist = $this->makeInstrumentist('Ole', 'Salve');
        $mission       = $this->makeMission(MissionStatus::ASSIGNED, $instrumentist);
        $actor         = $this->makeActor('Jean', 'Manager');

        $this->em->method('flush');

        $this->audit
            ->expects($this->once())
            ->method('record')
            ->with(
                $this->identicalTo($mission),
                $this->identicalTo($actor),
                AuditEventType::MISSION_RELEASED_TO_POOL,
                $this->callback(function (array $payload): bool {
                    return $payload['fromInstrumentistName'] === 'Ole Salve'
                        && $payload['actorName'] === 'Jean Manager';
                }),
            );

        $this->service->release($mission, $actor);
    }

    public function test_release_flushes_before_dispatch(): void
    {
        $callOrder     = [];
        $instrumentist = $this->makeInstrumentist();
        $mission       = $this->makeMission(MissionStatus::ASSIGNED, $instrumentist);
        $actor         = $this->makeActor();

        $this->em->expects($this->once())->method('flush')
            ->willReturnCallback(static function () use (&$callOrder): void {
                $callOrder[] = 'flush';
            });

        $this->bus->expects($this->once())->method('dispatch')
            ->willReturnCallback(static function (object $msg) use (&$callOrder): Envelope {
                $callOrder[] = 'dispatch';
                return new Envelope($msg);
            });

        $this->service->release($mission, $actor);

        $this->assertSame(['flush', 'dispatch'], $callOrder, 'R-05: flush must happen before dispatch');
    }

    public function test_release_dispatches_lifecycle_message_with_released_change_type(): void
    {
        $instrumentist = $this->makeInstrumentist();
        $mission       = $this->makeMission(MissionStatus::ASSIGNED, $instrumentist);
        $actor         = $this->makeActor();

        $this->em->method('flush');

        $dispatched = null;
        $this->bus->expects($this->once())->method('dispatch')
            ->willReturnCallback(static function (object $msg) use (&$dispatched): Envelope {
                $dispatched = $msg;
                return new Envelope($msg);
            });

        $this->service->release($mission, $actor);

        $this->assertInstanceOf(MissionLifecycleChangedMessage::class, $dispatched);
        $this->assertSame(MissionChangeType::RELEASED, $dispatched->changeType);
    }

    public function test_release_on_non_assigned_mission_throws_conflict(): void
    {
        $mission = $this->makeMission(MissionStatus::OPEN);
        $actor   = $this->makeActor();

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Mission must be ASSIGNED to release');

        $this->service->release($mission, $actor);
    }

    // ── cancel() ──────────────────────────────────────────────────────────────

    public function test_cancel_transitions_status_to_cancelled(): void
    {
        $mission = $this->makeMission(MissionStatus::OPEN);
        $actor   = $this->makeActor();

        $this->em->expects($this->once())->method('flush');

        $this->service->cancel($mission, $actor);

        $this->assertSame(MissionStatus::CANCELLED, $mission->getStatus());
    }

    public function test_cancel_creates_audit_event_with_reason(): void
    {
        $mission = $this->makeMission(MissionStatus::OPEN);
        $actor   = $this->makeActor();

        $this->em->method('flush');

        $this->audit
            ->expects($this->once())
            ->method('record')
            ->with(
                $this->identicalTo($mission),
                $this->identicalTo($actor),
                AuditEventType::MISSION_CANCELLED_POST_DEPLOY,
                $this->callback(fn (array $p): bool => $p['reason'] === 'Chirurgien absent'),
            );

        $this->service->cancel($mission, $actor, 'Chirurgien absent');
    }

    public function test_cancel_creates_audit_event_with_null_reason_when_omitted(): void
    {
        $mission = $this->makeMission(MissionStatus::OPEN);
        $actor   = $this->makeActor();

        $this->em->method('flush');

        $this->audit
            ->expects($this->once())
            ->method('record')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(fn (array $p): bool => array_key_exists('reason', $p) && $p['reason'] === null),
            );

        $this->service->cancel($mission, $actor, null);
    }

    public function test_cancel_dispatches_lifecycle_message_with_cancelled_change_type(): void
    {
        $mission = $this->makeMission(MissionStatus::OPEN);
        $actor   = $this->makeActor();

        $this->em->method('flush');

        $dispatched = null;
        $this->bus->expects($this->once())->method('dispatch')
            ->willReturnCallback(static function (object $msg) use (&$dispatched): Envelope {
                $dispatched = $msg;
                return new Envelope($msg);
            });

        $this->service->cancel($mission, $actor);

        $this->assertInstanceOf(MissionLifecycleChangedMessage::class, $dispatched);
        $this->assertSame(MissionChangeType::CANCELLED, $dispatched->changeType);
    }

    public function test_cancel_on_non_open_mission_throws_conflict(): void
    {
        $mission = $this->makeMission(MissionStatus::ASSIGNED);
        $actor   = $this->makeActor();

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Mission must be OPEN to cancel');

        $this->service->cancel($mission, $actor);
    }

    public function test_cancel_flushes_before_dispatch(): void
    {
        $callOrder = [];
        $mission   = $this->makeMission(MissionStatus::OPEN);
        $actor     = $this->makeActor();

        $this->em->expects($this->once())->method('flush')
            ->willReturnCallback(static function () use (&$callOrder): void {
                $callOrder[] = 'flush';
            });

        $this->bus->expects($this->once())->method('dispatch')
            ->willReturnCallback(static function (object $msg) use (&$callOrder): Envelope {
                $callOrder[] = 'dispatch';
                return new Envelope($msg);
            });

        $this->service->cancel($mission, $actor);

        $this->assertSame(['flush', 'dispatch'], $callOrder, 'R-05: flush must happen before dispatch');
    }

    // ── reassign() ────────────────────────────────────────────────────────────

    public function test_reassign_updates_instrumentist(): void
    {
        $fromInstrumentist = $this->makeInstrumentist('Ole', 'Salve');
        $toInstrumentist   = $this->makeInstrumentist('Jean', 'Martin');
        $mission           = $this->makeMission(MissionStatus::ASSIGNED, $fromInstrumentist);
        $actor             = $this->makeActor();

        $this->em->method('flush');
        $this->em->method('find')->willReturn($toInstrumentist);

        $this->service->reassign($mission, $actor, 99);

        $this->assertSame($toInstrumentist, $mission->getInstrumentist());
    }

    public function test_reassign_status_stays_assigned(): void
    {
        $fromInstrumentist = $this->makeInstrumentist();
        $toInstrumentist   = $this->makeInstrumentist('Jean', 'Martin');
        $mission           = $this->makeMission(MissionStatus::ASSIGNED, $fromInstrumentist);
        $actor             = $this->makeActor();

        $this->em->method('flush');
        $this->em->method('find')->willReturn($toInstrumentist);

        $this->service->reassign($mission, $actor, 99);

        $this->assertSame(MissionStatus::ASSIGNED, $mission->getStatus());
    }

    public function test_reassign_creates_audit_event_with_from_and_to_snapshots(): void
    {
        $fromInstrumentist = $this->makeInstrumentist('Ole', 'Salve');
        $toInstrumentist   = $this->makeInstrumentist('Jean', 'Martin');
        $mission           = $this->makeMission(MissionStatus::ASSIGNED, $fromInstrumentist);
        $actor             = $this->makeActor();

        $this->em->method('flush');
        $this->em->method('find')->willReturn($toInstrumentist);

        $this->audit
            ->expects($this->once())
            ->method('record')
            ->with(
                $this->anything(),
                $this->anything(),
                AuditEventType::MISSION_REASSIGNED_POST_DEPLOY,
                $this->callback(function (array $p): bool {
                    return $p['fromInstrumentistName'] === 'Ole Salve'
                        && $p['toInstrumentistName'] === 'Jean Martin';
                }),
            );

        $this->service->reassign($mission, $actor, 99);
    }

    public function test_reassign_on_non_assigned_mission_throws_conflict(): void
    {
        $mission = $this->makeMission(MissionStatus::OPEN);
        $actor   = $this->makeActor();

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Mission must be ASSIGNED to reassign');

        $this->service->reassign($mission, $actor, 99);
    }

    public function test_reassign_dispatches_lifecycle_message_with_reassigned_change_type(): void
    {
        $fromInstrumentist = $this->makeInstrumentist();
        $toInstrumentist   = $this->makeInstrumentist('Jean', 'Martin');
        $mission           = $this->makeMission(MissionStatus::ASSIGNED, $fromInstrumentist);
        $actor             = $this->makeActor();

        $this->em->method('flush');
        $this->em->method('find')->willReturn($toInstrumentist);

        $dispatched = null;
        $this->bus->expects($this->once())->method('dispatch')
            ->willReturnCallback(static function (object $msg) use (&$dispatched): Envelope {
                $dispatched = $msg;
                return new Envelope($msg);
            });

        $this->service->reassign($mission, $actor, 99);

        $this->assertInstanceOf(MissionLifecycleChangedMessage::class, $dispatched);
        $this->assertSame(MissionChangeType::REASSIGNED, $dispatched->changeType);
    }

    // ── assign() — alert-triggered manager assignment ─────────────────────────

    public function test_assign_on_open_mission_transitions_to_assigned(): void
    {
        $toInstrumentist = $this->makeInstrumentist('Jean', 'Martin');
        $mission         = $this->makeMission(MissionStatus::OPEN);
        $actor           = $this->makeActor();

        $this->em->method('flush');
        $this->em->method('find')->willReturn($toInstrumentist);

        $this->service->assign($mission, $actor, 99);

        $this->assertSame(MissionStatus::ASSIGNED, $mission->getStatus());
        $this->assertSame($toInstrumentist, $mission->getInstrumentist());
    }

    public function test_assign_on_assigned_mission_keeps_assigned_status(): void
    {
        $fromInstrumentist = $this->makeInstrumentist('Ole', 'Salve');
        $toInstrumentist   = $this->makeInstrumentist('Jean', 'Martin');
        $mission           = $this->makeMission(MissionStatus::ASSIGNED, $fromInstrumentist);
        $actor             = $this->makeActor();

        $this->em->method('flush');
        $this->em->method('find')->willReturn($toInstrumentist);

        $this->service->assign($mission, $actor, 99);

        $this->assertSame(MissionStatus::ASSIGNED, $mission->getStatus());
        $this->assertSame($toInstrumentist, $mission->getInstrumentist());
    }

    public function test_assign_creates_audit_event_with_reassigned_type(): void
    {
        $toInstrumentist = $this->makeInstrumentist('Jean', 'Martin');
        $mission         = $this->makeMission(MissionStatus::OPEN);
        $actor           = $this->makeActor('Alice', 'Manager');

        $this->em->method('flush');
        $this->em->method('find')->willReturn($toInstrumentist);

        $this->audit->expects($this->once())->method('record')
            ->with(
                $this->identicalTo($mission),
                $this->identicalTo($actor),
                AuditEventType::MISSION_REASSIGNED_POST_DEPLOY,
                $this->callback(fn (array $p): bool =>
                    $p['toInstrumentistName'] === 'Jean Martin'
                    && $p['actorName'] === 'Alice Manager'
                ),
            );

        $this->service->assign($mission, $actor, 99);
    }

    public function test_assign_flushes_before_dispatch(): void
    {
        $callOrder       = [];
        $toInstrumentist = $this->makeInstrumentist();
        $mission         = $this->makeMission(MissionStatus::OPEN);
        $actor           = $this->makeActor();

        $this->em->expects($this->once())->method('flush')
            ->willReturnCallback(static function () use (&$callOrder): void {
                $callOrder[] = 'flush';
            });

        $this->em->method('find')->willReturn($toInstrumentist);

        $this->bus->expects($this->once())->method('dispatch')
            ->willReturnCallback(static function (object $msg) use (&$callOrder): Envelope {
                $callOrder[] = 'dispatch';
                return new Envelope($msg);
            });

        $this->service->assign($mission, $actor, 99);

        $this->assertSame(['flush', 'dispatch'], $callOrder, 'R-05: flush must happen before dispatch');
    }

    public function test_assign_dispatches_lifecycle_message_with_reassigned_change_type(): void
    {
        $toInstrumentist = $this->makeInstrumentist();
        $mission         = $this->makeMission(MissionStatus::OPEN);
        $actor           = $this->makeActor();

        $this->em->method('flush');
        $this->em->method('find')->willReturn($toInstrumentist);

        $dispatched = null;
        $this->bus->expects($this->once())->method('dispatch')
            ->willReturnCallback(static function (object $msg) use (&$dispatched): Envelope {
                $dispatched = $msg;
                return new Envelope($msg);
            });

        $this->service->assign($mission, $actor, 99);

        $this->assertInstanceOf(MissionLifecycleChangedMessage::class, $dispatched);
        $this->assertSame(MissionChangeType::REASSIGNED, $dispatched->changeType);
    }

    public function test_assign_on_non_mutable_mission_throws_conflict(): void
    {
        $mission = $this->makeMission(MissionStatus::CANCELLED);
        $actor   = $this->makeActor();

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Mission must be OPEN or ASSIGNED to assign');

        $this->service->assign($mission, $actor, 99);
    }

    // ── claim() ───────────────────────────────────────────────────────────────

    public function test_claim_succeeds_when_eligible(): void
    {
        $actor   = $this->makeInstrumentist();
        $mission = $this->makeMission(MissionStatus::OPEN);

        $this->eligibilityService->method('evaluate')
            ->willReturn(new EligibilityResult($actor, []));

        $this->em->method('wrapInTransaction')
            ->willReturnCallback(function (callable $fn): void {
                $fn();
            });
        $this->em->method('lock');
        $this->em->method('getRepository')
            ->willReturn($this->createMock(\Doctrine\ORM\EntityRepository::class));
        $this->em->method('persist');
        $this->em->method('flush');

        // Must not throw
        $this->service->claim($mission, $actor);

        $this->assertSame(MissionStatus::ASSIGNED, $mission->getStatus());
        $this->assertSame($actor, $mission->getInstrumentist());
    }

    public function test_claim_throws_conflict_when_ineligible_absent(): void
    {
        $actor   = $this->makeInstrumentist();
        $mission = $this->makeMission(MissionStatus::OPEN);

        // Re-create mock to override setUp() default (which returns eligible)
        $this->eligibilityService = $this->createMock(MissionEligibilityService::class);
        $this->eligibilityService->method('evaluate')
            ->willReturn(new EligibilityResult($actor, [EligibilityReason::ABSENT]));
        $this->service = new MissionPostDeployService(
            $this->em, $this->bus, $this->audit, $this->eligibilityService,
        );

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Not eligible to claim this mission');

        $this->service->claim($mission, $actor);
    }

    public function test_claim_throws_conflict_when_no_site_membership(): void
    {
        $actor   = $this->makeInstrumentist();
        $mission = $this->makeMission(MissionStatus::OPEN);

        // Re-create mock to override setUp() default (which returns eligible)
        $this->eligibilityService = $this->createMock(MissionEligibilityService::class);
        $this->eligibilityService->method('evaluate')
            ->willReturn(new EligibilityResult($actor, [EligibilityReason::NO_SITE_MEMBERSHIP]));
        $this->service = new MissionPostDeployService(
            $this->em, $this->bus, $this->audit, $this->eligibilityService,
        );

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Not eligible to claim this mission');

        $this->service->claim($mission, $actor);
    }

    public function test_claim_checks_eligibility_before_acquiring_lock(): void
    {
        $actor   = $this->makeInstrumentist();
        $mission = $this->makeMission(MissionStatus::OPEN);

        $callOrder = [];

        $this->eligibilityService->method('evaluate')
            ->willReturnCallback(function () use (&$callOrder, $actor): EligibilityResult {
                $callOrder[] = 'evaluate';
                return new EligibilityResult($actor, []);
            });

        $this->em->method('wrapInTransaction')
            ->willReturnCallback(function (callable $fn) use (&$callOrder): void {
                $callOrder[] = 'lock';
                $fn();
            });
        $this->em->method('lock');
        $this->em->method('getRepository')
            ->willReturn($this->createMock(\Doctrine\ORM\EntityRepository::class));
        $this->em->method('persist');
        $this->em->method('flush');

        $this->service->claim($mission, $actor);

        $this->assertSame(['evaluate', 'lock'], $callOrder,
            'Eligibility must be checked before acquiring the pessimistic lock.'
        );
    }
}
