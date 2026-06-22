<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningAlert;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningAlertStatus;
use App\Enum\PlanningAlertType;
use App\Enum\SchedulePrecision;
use App\Service\NotificationService;
use App\Service\PlanningAlertActionService;
use App\Service\PlanningAlertService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PlanningAlertActionServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PlanningAlertService&MockObject   $alertService;
    private NotificationService&MockObject    $notificationService;

    /** Toggle which eligibility check fails for the "instrumentist" query results. */
    private bool $affiliated   = true;
    private bool $absent       = false;
    private bool $conflicting  = false;
    private array $foundUsers  = [];
    private array $candidateRows = [];

    private static int $idSeq = 0;

    protected function setUp(): void
    {
        $this->em                  = $this->createMock(EntityManagerInterface::class);
        $this->alertService        = $this->createMock(PlanningAlertService::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->affiliated    = true;
        $this->absent        = false;
        $this->conflicting   = false;
        $this->foundUsers    = [];
        $this->candidateRows = [];

        $this->alertService->method('resolve')->willReturn(true);

        $this->em->method('find')->willReturnCallback(function (string $class, $id) {
            return $this->foundUsers[$id] ?? null;
        });

        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql): Query {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();

                if (str_contains($dql, 'SiteMembership sm')) {
                    $q->method('getSingleScalarResult')->willReturnCallback(fn () => $this->affiliated ? 1 : 0);
                } elseif (str_contains($dql, 'Absence a')) {
                    $q->method('getSingleScalarResult')->willReturnCallback(fn () => $this->absent ? 1 : 0);
                } elseif (str_contains($dql, 'm.instrumentist = :user')) {
                    $q->method('getSingleScalarResult')->willReturnCallback(fn () => $this->conflicting ? 1 : 0);
                } elseif (str_contains($dql, 'JOIN u.siteMemberships sm')) {
                    $q->method('getResult')->willReturnCallback(fn () => $this->candidateRows);
                }

                return $q;
            });
    }

    private function makeService(): PlanningAlertActionService
    {
        return new PlanningAlertActionService($this->em, $this->alertService, $this->notificationService);
    }

    private function makeUser(string $email, bool $active = true, array $roles = ['ROLE_INSTRUMENTIST']): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setRoles($roles);
        $u->setActive($active);
        $rp = new \ReflectionProperty(User::class, 'id');
        $rp->setValue($u, ++self::$idSeq);
        return $u;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Alpha');
        $rp = new \ReflectionProperty(Hospital::class, 'id');
        $rp->setValue($h, ++self::$idSeq);
        return $h;
    }

    private function makeMission(Hospital $site, MissionStatus $status, ?User $instrumentist = null): Mission
    {
        $surgeon = $this->makeUser('surgeon@test.com', true, ['ROLE_SURGEON']);
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSurgeon($surgeon);
        $m->setInstrumentist($instrumentist);
        $m->setSite($site);
        $m->setStartAt(new \DateTimeImmutable('2026-02-02 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-02-02 13:00:00'));
        $m->setCreatedBy($surgeon);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $rp = new \ReflectionProperty(Mission::class, 'id');
        $rp->setValue($m, ++self::$idSeq);
        return $m;
    }

    private function makeAlert(Mission $mission, PlanningAlertStatus $status = PlanningAlertStatus::OPEN): PlanningAlert
    {
        $alert = new PlanningAlert();
        $alert->setType(PlanningAlertType::REASSIGNMENT_REQUIRED);
        $alert->setMission($mission);
        $rp = new \ReflectionProperty(PlanningAlert::class, 'status');
        $rp->setValue($alert, $status);
        return $alert;
    }

    // ── Reassign: success ─────────────────────────────────────────────────────

    public function test_reassign_success_sets_instrumentist_and_assigns_open_mission(): void
    {
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, MissionStatus::OPEN);
        $alert   = $this->makeAlert($mission);
        $newInst = $this->makeUser('new-inst@test.com');
        $this->foundUsers[$newInst->getId()] = $newInst;

        $this->alertService->expects($this->once())->method('resolve')->with($alert, $this->anything(), $this->isType('string'));
        $this->notificationService->expects($this->once())
            ->method('planningAlertReassignedNotify')
            ->with($mission, null, $newInst);

        $result = $this->makeService()->reassign($alert, $newInst->getId(), $this->makeUser('manager@test.com', true, ['ROLE_MANAGER']), null);

        $this->assertSame($newInst, $mission->getInstrumentist());
        $this->assertSame(MissionStatus::ASSIGNED, $mission->getStatus());
        $this->assertSame($mission, $result['mission']);
    }

    public function test_reassign_keeps_assigned_status_when_already_assigned(): void
    {
        $site    = $this->makeSite();
        $oldInst = $this->makeUser('old-inst@test.com');
        $mission = $this->makeMission($site, MissionStatus::ASSIGNED, $oldInst);
        $alert   = $this->makeAlert($mission);
        $newInst = $this->makeUser('new-inst@test.com');
        $this->foundUsers[$newInst->getId()] = $newInst;

        $this->notificationService->expects($this->once())
            ->method('planningAlertReassignedNotify')
            ->with($mission, $oldInst, $newInst);

        $this->makeService()->reassign($alert, $newInst->getId(), null, 'note');

        $this->assertSame($newInst, $mission->getInstrumentist());
        $this->assertSame(MissionStatus::ASSIGNED, $mission->getStatus());
    }

    public function test_reassign_does_not_dispatch_the_original_alert_raised_notification_again(): void
    {
        // Reassign's feedback notification is synchronous (planningAlertReassignedNotify),
        // entirely separate from the async PlanningAlertRaisedMessage fan-out — resolving
        // the alert here must never re-trigger that original notification path.
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, MissionStatus::OPEN);
        $alert   = $this->makeAlert($mission);
        $newInst = $this->makeUser('new-inst@test.com');
        $this->foundUsers[$newInst->getId()] = $newInst;

        $this->notificationService->expects($this->never())->method('planningAlertRaisedNotifyInApp');
        $this->notificationService->expects($this->once())->method('planningAlertReassignedNotify');

        $this->makeService()->reassign($alert, $newInst->getId(), null, null);
    }

    public function test_reassign_uses_provided_note(): void
    {
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, MissionStatus::OPEN);
        $alert   = $this->makeAlert($mission);
        $newInst = $this->makeUser('new-inst@test.com');
        $this->foundUsers[$newInst->getId()] = $newInst;

        $this->alertService->expects($this->once())->method('resolve')->with($alert, $this->anything(), 'Trouvé un remplaçant.');

        $this->makeService()->reassign($alert, $newInst->getId(), null, 'Trouvé un remplaçant.');
    }

    // ── Reassign: rejections ──────────────────────────────────────────────────

    public function test_reassign_rejects_unknown_instrumentist(): void
    {
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, MissionStatus::OPEN);
        $alert   = $this->makeAlert($mission);

        $this->expectException(NotFoundHttpException::class);
        $this->makeService()->reassign($alert, 999999, null, null);
    }

    public function test_reassign_rejects_inactive_instrumentist(): void
    {
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, MissionStatus::OPEN);
        $alert   = $this->makeAlert($mission);
        $inactive = $this->makeUser('inactive@test.com', false);
        $this->foundUsers[$inactive->getId()] = $inactive;

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->makeService()->reassign($alert, $inactive->getId(), null, null);
    }

    public function test_reassign_rejects_non_affiliated_instrumentist(): void
    {
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, MissionStatus::OPEN);
        $alert   = $this->makeAlert($mission);
        $other   = $this->makeUser('elsewhere@test.com');
        $this->foundUsers[$other->getId()] = $other;
        $this->affiliated = false;

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->makeService()->reassign($alert, $other->getId(), null, null);
    }

    public function test_reassign_rejects_absent_instrumentist(): void
    {
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, MissionStatus::OPEN);
        $alert   = $this->makeAlert($mission);
        $absentUser = $this->makeUser('absent@test.com');
        $this->foundUsers[$absentUser->getId()] = $absentUser;
        $this->absent = true;

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->makeService()->reassign($alert, $absentUser->getId(), null, null);
    }

    public function test_reassign_rejects_conflicting_instrumentist(): void
    {
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, MissionStatus::OPEN);
        $alert   = $this->makeAlert($mission);
        $busyUser = $this->makeUser('busy@test.com');
        $this->foundUsers[$busyUser->getId()] = $busyUser;
        $this->conflicting = true;

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->makeService()->reassign($alert, $busyUser->getId(), null, null);
    }

    public function test_reassign_rejects_non_instrumentist_role(): void
    {
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, MissionStatus::OPEN);
        $alert   = $this->makeAlert($mission);
        $notInst = $this->makeUser('notinst@test.com', true, ['ROLE_SURGEON']);
        $this->foundUsers[$notInst->getId()] = $notInst;

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->makeService()->reassign($alert, $notInst->getId(), null, null);
    }

    #[DataProvider('lockedStatusesProvider')]
    public function test_reassign_rejects_locked_mission_status(MissionStatus $status): void
    {
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $status);
        $alert   = $this->makeAlert($mission);
        $newInst = $this->makeUser('new-inst@test.com');
        $this->foundUsers[$newInst->getId()] = $newInst;

        $this->expectException(ConflictHttpException::class);
        $this->makeService()->reassign($alert, $newInst->getId(), null, null);
    }

    public static function lockedStatusesProvider(): array
    {
        return [
            'SUBMITTED' => [MissionStatus::SUBMITTED],
            'VALIDATED' => [MissionStatus::VALIDATED],
            'CLOSED'    => [MissionStatus::CLOSED],
        ];
    }

    public function test_reassign_rejects_when_alert_not_active(): void
    {
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, MissionStatus::OPEN);
        $alert   = $this->makeAlert($mission, PlanningAlertStatus::RESOLVED);
        $newInst = $this->makeUser('new-inst@test.com');
        $this->foundUsers[$newInst->getId()] = $newInst;

        $this->expectException(ConflictHttpException::class);
        $this->makeService()->reassign($alert, $newInst->getId(), null, null);
    }

    // ── Open as available ─────────────────────────────────────────────────────

    public function test_open_as_available_clears_instrumentist_and_sets_open(): void
    {
        $site    = $this->makeSite();
        $inst    = $this->makeUser('inst@test.com');
        $mission = $this->makeMission($site, MissionStatus::ASSIGNED, $inst);
        $alert   = $this->makeAlert($mission);

        $this->alertService->expects($this->once())->method('resolve')->with($alert, $this->anything(), $this->isType('string'));
        // Per Batch 7 scope: open-as-available does not notify the eligible pool yet
        // ("later, but not now unless current infra supports it").
        $this->notificationService->expects($this->never())->method('planningAlertReassignedNotify');

        $this->makeService()->openAsAvailable($alert, null, null);

        $this->assertNull($mission->getInstrumentist());
        $this->assertSame(MissionStatus::OPEN, $mission->getStatus());
    }

    #[DataProvider('lockedStatusesProvider')]
    public function test_open_as_available_rejects_locked_mission_status(MissionStatus $status): void
    {
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $status);
        $alert   = $this->makeAlert($mission);

        $this->expectException(ConflictHttpException::class);
        $this->makeService()->openAsAvailable($alert, null, null);
    }

    public function test_open_as_available_rejects_when_alert_not_active(): void
    {
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, MissionStatus::ASSIGNED);
        $alert   = $this->makeAlert($mission, PlanningAlertStatus::IGNORED);

        $this->expectException(ConflictHttpException::class);
        $this->makeService()->openAsAvailable($alert, null, null);
    }

    // ── Eligible instrumentists ───────────────────────────────────────────────

    public function test_find_eligible_instrumentists_excludes_absent_and_conflicting(): void
    {
        $site = $this->makeSite();
        $mission = $this->makeMission($site, MissionStatus::OPEN);
        $eligible = $this->makeUser('eligible@test.com');
        $this->candidateRows = [$eligible];
        $this->absent = false;
        $this->conflicting = false;

        $result = $this->makeService()->findEligibleInstrumentists($mission);

        $this->assertSame([$eligible], $result);
    }

    public function test_find_eligible_instrumentists_returns_empty_when_all_unavailable(): void
    {
        $site = $this->makeSite();
        $mission = $this->makeMission($site, MissionStatus::OPEN);
        $this->candidateRows = [$this->makeUser('busy@test.com')];
        $this->conflicting = true;

        $result = $this->makeService()->findEligibleInstrumentists($mission);

        $this->assertSame([], $result);
    }
}
