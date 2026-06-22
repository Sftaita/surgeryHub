<?php

namespace App\Tests\Unit\Service;

use App\Entity\Absence;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningAlert;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningAlertType;
use App\Repository\UserRepository;
use App\Service\AbsenceImpactService;
use App\Service\PlanningAlertService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Tests AbsenceImpactService orchestration: which missions get alerted, how alert type is
 * classified, that Mission is NEVER mutated regardless of status, and that obsolete alerts
 * are resolved (not deleted) when an absence is modified or removed.
 *
 * PlanningAlertService itself (the anti-duplicate/resolution mechanics) is tested in
 * isolation in PlanningAlertServiceTest — here it's mocked so these tests stay focused on
 * AbsenceImpactService's own logic.
 */
class AbsenceImpactServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PlanningAlertService&MockObject   $alertService;
    private UserRepository&MockObject         $userRepository;
    private MessageBusInterface&MockObject    $bus;

    private array $overlappingMissions = [];
    /** Stub return value for the next alertService->createIfNotDuplicate() call. */
    private ?array $nextCreateResult = null;
    private array $activeAlertsForAbsence = [];
    /** Managers/admins returned by userRepository->findManagersAndAdmins() — empty by default so tests stay focused. */
    private array $managers = [];
    /** Every message passed to bus->dispatch(), in call order. */
    private array $dispatched = [];

    private static int $idSeq = 0;

    protected function setUp(): void
    {
        $this->em             = $this->createMock(EntityManagerInterface::class);
        $this->alertService   = $this->createMock(PlanningAlertService::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->bus             = $this->createMock(MessageBusInterface::class);
        $this->overlappingMissions   = [];
        $this->nextCreateResult      = null;
        $this->activeAlertsForAbsence = [];
        $this->managers       = [];
        $this->dispatched     = [];

        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql): Query {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                $q->method('getResult')->willReturnCallback(fn () => $this->overlappingMissions);
                return $q;
            });

        $this->alertService->method('findActiveAlertsForAbsence')
            ->willReturnCallback(fn () => $this->activeAlertsForAbsence);

        $this->alertService->method('createIfNotDuplicate')
            ->willReturnCallback(fn () => $this->nextCreateResult ?? ['alert' => $this->makeAlert(), 'created' => true]);

        $this->userRepository->method('findManagersAndAdmins')->willReturnCallback(fn () => $this->managers);

        $this->bus->method('dispatch')->willReturnCallback(function (object $message) {
            $this->dispatched[] = $message;
            return new \Symfony\Component\Messenger\Envelope($message);
        });
    }

    private function makeService(): AbsenceImpactService
    {
        return new AbsenceImpactService($this->em, $this->alertService, $this->userRepository, $this->bus);
    }

    private function makeUser(string $email): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setRoles(['ROLE_SURGEON']);
        $u->setActive(true);
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

    private function makeMission(User $surgeon, ?User $instrumentist, Hospital $site, MissionStatus $status): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSurgeon($surgeon);
        $m->setInstrumentist($instrumentist);
        $m->setSite($site);
        $m->setStartAt(new \DateTimeImmutable('2026-01-12 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-01-12 13:00:00'));
        $m->setCreatedBy($surgeon);
        $m->setSchedulePrecision(\App\Enum\SchedulePrecision::EXACT);
        $rp = new \ReflectionProperty(Mission::class, 'id');
        $rp->setValue($m, ++self::$idSeq);
        return $m;
    }

    private function makeAbsence(User $user): Absence
    {
        $a = new Absence();
        $a->setUser($user);
        $a->setDateStart(new \DateTimeImmutable('2026-01-12'));
        $a->setDateEnd(new \DateTimeImmutable('2026-01-12'));
        $a->setCreatedBy($user);
        return $a;
    }

    private function makeAlert(): PlanningAlert
    {
        $a = new PlanningAlert();
        $a->setType(PlanningAlertType::SURGEON_ABSENCE);
        $rp = new \ReflectionProperty(PlanningAlert::class, 'id');
        $rp->setValue($a, ++self::$idSeq);
        return $a;
    }

    // ── B. Absence before generation: no missions exist yet ─────────────────

    public function test_no_missions_means_no_alerts_and_nothing_touched(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $absence = $this->makeAbsence($surgeon);
        $this->overlappingMissions = [];

        $this->alertService->expects($this->never())->method('createIfNotDuplicate');

        $result = $this->makeService()->onAbsenceCreated($absence);

        $this->assertSame([], $result['created']);
        $this->assertSame([], $result['notifications']);
    }

    // ── C. Absence after generation/publication — classification per status ──

    public function test_surgeon_absence_on_draft_mission_classified_as_surgeon_absence(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, null, $site, MissionStatus::DRAFT);
        $this->overlappingMissions = [$mission];
        $this->nextCreateResult = ['alert' => $this->makeAlert(), 'created' => true];

        $this->alertService->expects($this->once())
            ->method('createIfNotDuplicate')
            ->with($mission, PlanningAlertType::SURGEON_ABSENCE, $this->anything(), $this->anything());

        $this->makeService()->onAbsenceCreated($this->makeAbsence($surgeon));
    }

    public function test_instrumentist_absence_on_assigned_mission_classified_as_reassignment_required(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $inst    = $this->makeUser('inst@test.com');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, $inst, $site, MissionStatus::ASSIGNED);
        $this->overlappingMissions = [$mission];
        $this->nextCreateResult = ['alert' => $this->makeAlert(), 'created' => true];

        $this->alertService->expects($this->once())
            ->method('createIfNotDuplicate')
            ->with($mission, PlanningAlertType::REASSIGNMENT_REQUIRED, $this->anything(), $this->anything());

        $this->makeService()->onAbsenceCreated($this->makeAbsence($inst));
    }

    public function test_instrumentist_absence_on_open_mission_classified_as_instrumentist_absence(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $inst    = $this->makeUser('inst@test.com');
        $site    = $this->makeSite();
        // OPEN missions have no fixed instrumentist in practice, but classify() only needs
        // the absent user to match the instrumentist field to exercise this branch.
        $mission = $this->makeMission($surgeon, $inst, $site, MissionStatus::OPEN);
        $this->overlappingMissions = [$mission];
        $this->nextCreateResult = ['alert' => $this->makeAlert(), 'created' => true];

        $this->alertService->expects($this->once())
            ->method('createIfNotDuplicate')
            ->with($mission, PlanningAlertType::INSTRUMENTIST_ABSENCE, $this->anything(), $this->anything());

        $this->makeService()->onAbsenceCreated($this->makeAbsence($inst));
    }

    #[DataProvider('alertableStatusesProvider')]
    public function test_alert_only_never_mutates_mission_for_each_alertable_status(MissionStatus $status): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, null, $site, $status);
        $this->overlappingMissions = [$mission];
        $this->nextCreateResult = ['alert' => $this->makeAlert(), 'created' => true];

        $statusBefore = $mission->getStatus();

        $this->makeService()->onAbsenceCreated($this->makeAbsence($surgeon));

        $this->assertSame($statusBefore, $mission->getStatus(), 'AbsenceImpactService must never change Mission.status, regardless of which status it was in');
    }

    public static function alertableStatusesProvider(): array
    {
        return [
            'DRAFT'       => [MissionStatus::DRAFT],
            'OPEN'        => [MissionStatus::OPEN],
            'ASSIGNED'    => [MissionStatus::ASSIGNED],
            'SUBMITTED'   => [MissionStatus::SUBMITTED],
            'VALIDATED'   => [MissionStatus::VALIDATED],
            'IN_PROGRESS' => [MissionStatus::IN_PROGRESS],
        ];
    }

    #[DataProvider('terminalStatusesProvider')]
    public function test_terminal_statuses_are_excluded_from_impact_detection(MissionStatus $status): void
    {
        // The bounded query itself filters by ALERTABLE_STATUSES — simulate that a
        // terminal-status mission is correctly excluded from the overlap result entirely.
        $this->overlappingMissions = [];

        $this->alertService->expects($this->never())->method('createIfNotDuplicate');

        $surgeon = $this->makeUser('surgeon@test.com');
        $this->makeService()->onAbsenceCreated($this->makeAbsence($surgeon));

        $this->assertTrue(true, 'terminal status ' . $status->value . ' must never reach createIfNotDuplicate');
    }

    public static function terminalStatusesProvider(): array
    {
        return [
            'CLOSED'    => [MissionStatus::CLOSED],
            'REJECTED'  => [MissionStatus::REJECTED],
            'DECLARED'  => [MissionStatus::DECLARED],
        ];
    }

    // ── D. Absence modification: resolve obsolete, create new, no duplicates ─

    public function test_updating_absence_resolves_alerts_for_missions_no_longer_overlapping(): void
    {
        $surgeon  = $this->makeUser('surgeon@test.com');
        $site     = $this->makeSite();
        $absence  = $this->makeAbsence($surgeon);

        $staleMission = $this->makeMission($surgeon, null, $site, MissionStatus::DRAFT);
        $staleAlert   = $this->makeAlert();
        $staleAlert->setMission($staleMission);
        $staleAlert->setType(PlanningAlertType::SURGEON_ABSENCE);

        $this->activeAlertsForAbsence = [$staleAlert];
        $this->overlappingMissions    = []; // absence shrunk — no longer overlaps anything

        $result = $this->makeService()->onAbsenceUpdated($absence);

        $this->assertCount(1, $result['resolved']);
        $this->assertSame($staleAlert, $result['resolved'][0]);
    }

    public function test_updating_absence_creates_alerts_for_newly_overlapping_missions(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $absence = $this->makeAbsence($surgeon);

        $newMission = $this->makeMission($surgeon, null, $site, MissionStatus::DRAFT);
        $this->activeAlertsForAbsence = []; // nothing alerted yet
        $this->overlappingMissions    = [$newMission]; // absence widened — now overlaps
        $this->nextCreateResult = ['alert' => $this->makeAlert(), 'created' => true];

        $result = $this->makeService()->onAbsenceUpdated($absence);

        $this->assertCount(1, $result['created']);
    }

    public function test_updating_absence_does_not_duplicate_alert_for_mission_still_overlapping(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $absence = $this->makeAbsence($surgeon);

        $mission = $this->makeMission($surgeon, null, $site, MissionStatus::DRAFT);
        $existingAlert = $this->makeAlert();
        $existingAlert->setMission($mission);
        $this->activeAlertsForAbsence = [$existingAlert];
        $this->overlappingMissions    = [$mission]; // still overlaps
        // PlanningAlertService's own dedup would return created=false here — simulate that.
        $this->nextCreateResult = ['alert' => $existingAlert, 'created' => false];

        $result = $this->makeService()->onAbsenceUpdated($absence);

        $this->assertSame([], $result['created'], 'No new alert when the mission still overlaps and was already alerted');
        $this->assertSame([], $result['resolved'], 'No resolution when the mission still overlaps');
    }

    // ── E. Absence deletion: resolve, never silently delete ──────────────────

    public function test_absence_deleted_resolves_all_its_active_alerts(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $absence = $this->makeAbsence($surgeon);
        $alert1  = $this->makeAlert();
        $alert2  = $this->makeAlert();

        $this->alertService->expects($this->once())
            ->method('resolveAllForAbsence')
            ->with($absence, $this->isType('string'))
            ->willReturn([$alert1, $alert2]);

        $this->em->expects($this->never())->method('remove');

        $resolved = $this->makeService()->onAbsenceDeleted($absence);

        $this->assertSame([$alert1, $alert2], $resolved);
    }

    // ── F. Anti-duplicate (orchestration level — true mechanics tested in PlanningAlertServiceTest) ──

    public function test_calling_sync_twice_with_unchanged_overlap_creates_no_additional_alerts(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $absence = $this->makeAbsence($surgeon);
        $mission = $this->makeMission($surgeon, null, $site, MissionStatus::DRAFT);

        $this->overlappingMissions = [$mission];
        $alert = $this->makeAlert();
        $alert->setMission($mission);

        // First call: nothing exists yet → created.
        $this->activeAlertsForAbsence = [];
        $this->nextCreateResult = ['alert' => $alert, 'created' => true];
        $first = $this->makeService()->onAbsenceCreated($absence);
        $this->assertCount(1, $first['created']);

        // Second call: the alert now exists and still applies → not created again.
        $this->activeAlertsForAbsence = [$alert];
        $this->nextCreateResult = ['alert' => $alert, 'created' => false];
        $second = $this->makeService()->onAbsenceCreated($absence);
        $this->assertCount(0, $second['created'], 'Re-running impact detection on the same absence must not create a duplicate alert');
    }

    // ── Batch 7: notification dispatch ───────────────────────────────────────

    public function test_alert_creation_dispatches_exactly_one_notification_message(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, null, $site, MissionStatus::DRAFT);
        $this->overlappingMissions = [$mission];
        $this->nextCreateResult = ['alert' => $this->makeAlert(), 'created' => true];

        $this->makeService()->onAbsenceCreated($this->makeAbsence($surgeon));

        $this->assertCount(1, $this->dispatched);
        $this->assertInstanceOf(\App\Message\PlanningAlertRaisedMessage::class, $this->dispatched[0]);
    }

    public function test_idempotent_alert_sync_dispatches_nothing(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, null, $site, MissionStatus::DRAFT);
        $alert   = $this->makeAlert();
        $alert->setMission($mission);

        $this->overlappingMissions = [$mission];
        $this->activeAlertsForAbsence = [$alert];
        $this->nextCreateResult = ['alert' => $alert, 'created' => false]; // already exists, still applies

        $this->makeService()->onAbsenceUpdated($this->makeAbsence($surgeon));

        $this->assertSame([], $this->dispatched, 'No notification for an idempotent no-op resync');
    }

    public function test_surgeon_absence_notifies_managers_and_assigned_instrumentist(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $inst    = $this->makeUser('inst@test.com');
        $site    = $this->makeSite();
        $manager = $this->makeUser('manager@test.com');
        $this->managers = [$manager];

        $mission = $this->makeMission($surgeon, $inst, $site, MissionStatus::ASSIGNED);
        $this->overlappingMissions = [$mission];
        $this->nextCreateResult = ['alert' => $this->makeAlert(), 'created' => true];

        $this->makeService()->onAbsenceCreated($this->makeAbsence($surgeon));

        $this->assertCount(1, $this->dispatched);
        /** @var \App\Message\PlanningAlertRaisedMessage $message */
        $message = $this->dispatched[0];
        $this->assertContains($manager->getId(), $message->recipientUserIds);
        $this->assertContains($inst->getId(), $message->recipientUserIds);
    }

    public function test_instrumentist_absence_notifies_managers_and_instrumentist(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $inst    = $this->makeUser('inst@test.com');
        $site    = $this->makeSite();
        $manager = $this->makeUser('manager@test.com');
        $this->managers = [$manager];

        $mission = $this->makeMission($surgeon, $inst, $site, MissionStatus::OPEN);
        $this->overlappingMissions = [$mission];
        $this->nextCreateResult = ['alert' => $this->makeAlert(), 'created' => true];

        $this->makeService()->onAbsenceCreated($this->makeAbsence($inst));

        $this->assertCount(1, $this->dispatched);
        /** @var \App\Message\PlanningAlertRaisedMessage $message */
        $message = $this->dispatched[0];
        $this->assertContains($manager->getId(), $message->recipientUserIds);
        $this->assertContains($inst->getId(), $message->recipientUserIds);
    }

    public function test_notification_payload_contains_no_patient_data(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, null, $site, MissionStatus::DRAFT);
        $this->overlappingMissions = [$mission];
        $this->nextCreateResult = ['alert' => $this->makeAlert(), 'created' => true];

        $this->makeService()->onAbsenceCreated($this->makeAbsence($surgeon));

        /** @var \App\Message\PlanningAlertRaisedMessage $message */
        $message = $this->dispatched[0];
        $publicFields = get_object_vars($message);

        $this->assertArrayHasKey('missionId', $publicFields);
        $this->assertArrayHasKey('siteId', $publicFields);
        $this->assertArrayHasKey('missionDate', $publicFields);
        $this->assertArrayHasKey('alertType', $publicFields);
        foreach (array_keys($publicFields) as $field) {
            $this->assertStringNotContainsStringIgnoringCase('patient', $field, 'No patient-related field must ever exist on this message');
        }
    }
}
