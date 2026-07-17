<?php

namespace App\Tests\Integration;

use App\Entity\Absence;
use App\Entity\AuditEvent;
use App\Entity\ExportLog;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\PlanningAlert;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningAlertType;
use App\Enum\SchedulePrecision;
use App\Dto\Request\ExportSurgeonActivityRequest;
use App\Service\AbsenceImpactService;
use App\Service\ExportService;
use App\Service\InstrumentistServiceManager;
use App\Service\MissionMapper;
use App\Service\MissionPostDeployService;
use App\Service\NotificationService;
use App\Service\PlanningAlertService;
use App\Service\SurgeonServiceManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * D-066 — real-database, end-to-end proof for the business_datetime_immutable Doctrine
 * type fix. Unlike BusinessDateTimeImmutableTypeTest (pure unit, calls the type's
 * methods directly), everything here goes through a REAL EntityManager against the real
 * test database, with `$em->clear()` between write and read so Doctrine actually
 * re-hydrates from the DB — proving the fix works through the real ORM pipeline, not
 * just in isolation.
 *
 * This file is also the proof for D-065's audit: none of the 9 originally-affected call
 * sites (MissionMapper, SurgeonServiceManager, InstrumentistServiceManager,
 * MissionPostDeployService's audit payload, etc.) were individually modified for this
 * fix — this test calls them as-is and confirms they now receive a correctly-labeled
 * entity automatically.
 */
final class MissionBusinessTimezoneIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $createdIds = ['missions' => [], 'users' => [], 'sites' => [], 'absences' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds['missions'] as $id) {
            $mission = $this->em->find(Mission::class, $id);
            if ($mission === null) { continue; }
            foreach ($this->em->createQueryBuilder()->select('e')->from(AuditEvent::class, 'e')->where('e.mission = :m')->setParameter('m', $mission)->getQuery()->getResult() as $evt) {
                $this->em->remove($evt);
            }
            foreach ($this->em->createQueryBuilder()->select('a')->from(PlanningAlert::class, 'a')->where('a.mission = :m')->setParameter('m', $mission)->getQuery()->getResult() as $alert) {
                $this->em->remove($alert);
            }
        }
        $this->em->flush();
        foreach ($this->createdIds['users'] as $id) {
            $user = $this->em->find(User::class, $id);
            if ($user === null) { continue; }
            foreach ($this->em->createQueryBuilder()->select('n')->from(NotificationEvent::class, 'n')->where('n.user = :u')->setParameter('u', $user)->getQuery()->getResult() as $notif) {
                $this->em->remove($notif);
            }
            foreach ($this->em->createQueryBuilder()->select('l')->from(ExportLog::class, 'l')->where('l.user = :u')->setParameter('u', $user)->getQuery()->getResult() as $log) {
                $this->em->remove($log);
            }
        }
        $this->em->flush();
        foreach ($this->createdIds['absences'] as $id) {
            $e = $this->em->find(Absence::class, $id);
            if ($e !== null) { $this->em->remove($e); }
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

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('D066-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('d066-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('D066');
        $u->setLastname('Test');
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeMission(User $surgeon, User $instrumentist, Hospital $site, \DateTimeImmutable $start, \DateTimeImmutable $end): Mission
    {
        $m = new Mission();
        $m->setStatus(MissionStatus::ASSIGNED);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setSurgeon($surgeon);
        $m->setInstrumentist($instrumentist);
        $m->setSite($site);
        $m->setStartAt($start);
        $m->setEndAt($end);
        $m->setCreatedBy($surgeon);
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    /** Raw SQL read of the DB column, bypassing Doctrine entirely — the ground truth. */
    private function rawStartAt(int $missionId): string
    {
        return (string) $this->em->getConnection()
            ->fetchOne('SELECT start_at FROM mission WHERE id = ?', [$missionId]);
    }

    private function rawEndAt(int $missionId): string
    {
        return (string) $this->em->getConnection()
            ->fetchOne('SELECT end_at FROM mission WHERE id = ?', [$missionId]);
    }

    // ── Real DB read: summer / winter ────────────────────────────────────────────

    public function test_real_db_read_summer_mission_gets_the_true_dst_offset(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeMission(
            $surgeon, $instr, $site,
            new \DateTimeImmutable('2026-07-15T10:11:00+02:00'),
            new \DateTimeImmutable('2026-07-15T14:11:00+02:00'),
        );
        $id = $mission->getId();

        self::assertSame('2026-07-15 10:11:00', $this->rawStartAt($id), 'Ground truth: raw DB value');

        $this->em->clear(); // force real re-hydration from the DB, not the in-memory object
        $reread = $this->em->find(Mission::class, $id);

        self::assertSame('2026-07-15T10:11:00+02:00', $reread->getStartAt()->format(\DateTimeInterface::ATOM));
    }

    public function test_real_db_read_winter_mission_gets_the_true_standard_offset(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeMission(
            $surgeon, $instr, $site,
            new \DateTimeImmutable('2026-01-15T10:11:00+01:00'),
            new \DateTimeImmutable('2026-01-15T14:11:00+01:00'),
        );
        $id = $mission->getId();

        self::assertSame('2026-01-15 10:11:00', $this->rawStartAt($id));

        $this->em->clear();
        $reread = $this->em->find(Mission::class, $id);

        self::assertSame('2026-01-15T10:11:00+01:00', $reread->getStartAt()->format(\DateTimeInterface::ATOM));
    }

    // ── Planning-generator construction pattern (day + time-of-day) ─────────────
    //
    // D-066 fallout caught by the full suite run (PlanningV2CrudControllerTest): both
    // PlanningGeneratorService and PlanningGeneratorServiceV2 build Mission.startAt/
    // endAt as `(new \DateTimeImmutable($dateString))->setTime($h, $m)` — a naive
    // construction that, before being fixed, would get silently shifted by the DST
    // offset once written through business_datetime_immutable (a naive object is
    // implicitly UTC-labeled by the container's date.timezone, and the type's write
    // side genuinely CONVERTS whatever offset it's given). Both generators were fixed
    // to construct with an explicit Europe/Brussels timezone. This test locks in that
    // exact pattern end-to-end through the real DB, independent of the generators'
    // own (heavier-to-set-up) fixtures.

    public function test_day_plus_time_of_day_construction_pattern_round_trips_correctly(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();

        // Exactly the pattern used by PlanningGeneratorService(V2)::generate().
        $day = new \DateTimeImmutable('2026-01-05', new \DateTimeZone('Europe/Brussels'));
        $startAt = $day->setTime(8, 0);
        $endAt = $day->setTime(13, 0);

        $mission = $this->makeMission($surgeon, $instr, $site, $startAt, $endAt);
        $id = $mission->getId();

        self::assertSame('2026-01-05 08:00:00', $this->rawStartAt($id), 'Ground truth: raw DB value must not drift from the intended wall clock');

        $this->em->clear();
        $reread = $this->em->find(Mission::class, $id);

        self::assertSame('2026-01-05T08:00:00+01:00', $reread->getStartAt()->format(\DateTimeInterface::ATOM));
    }

    public function test_naive_construction_without_explicit_timezone_would_have_drifted_regression_guard(): void
    {
        // Documents WHY the explicit timezone matters: a naive DateTimeImmutable (no
        // tz argument) is implicitly labeled with the container's default (UTC), and
        // the business type's write side genuinely converts UTC -> Brussels, shifting
        // the wall clock by +1h (winter). This is not a bug in the type — it is
        // correctly converting what it was told is a UTC instant. The bug, when it
        // happened, was in the CALLER constructing naively when it meant Brussels wall
        // clock all along. This test pins the (correct, if surprising) type behavior so
        // a future reader understands why every business-datetime construction site
        // must be explicit.
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();

        $naiveDay = new \DateTimeImmutable('2026-01-05'); // no explicit timezone — container default (UTC)
        $mission = $this->makeMission($surgeon, $instr, $site, $naiveDay->setTime(8, 0), $naiveDay->setTime(13, 0));

        self::assertSame(
            '2026-01-05 09:00:00',
            $this->rawStartAt($mission->getId()),
            'A naive (UTC-labeled) 08:00 is correctly converted to its true Brussels wall-clock equivalent (09:00 in winter) — this is why every real construction site must be explicit about Europe/Brussels.',
        );
    }

    // ── Real DB write: offset-bearing input ──────────────────────────────────────

    public function test_real_db_write_of_a_brussels_dst_offset_value(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeMission(
            $surgeon, $instr, $site,
            new \DateTimeImmutable('2026-07-15T10:11:00+02:00'),
            new \DateTimeImmutable('2026-07-15T12:11:00+02:00'),
        );

        self::assertSame('2026-07-15 10:11:00', $this->rawStartAt($mission->getId()));
    }

    public function test_real_db_write_of_a_utc_value_representing_the_same_real_instant(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        // 08:11 UTC === 10:11+02:00 (Brussels DST) — same real-world instant.
        $mission = $this->makeMission(
            $surgeon, $instr, $site,
            new \DateTimeImmutable('2026-07-15T08:11:00+00:00'),
            new \DateTimeImmutable('2026-07-15T10:11:00+00:00'),
        );

        self::assertSame(
            '2026-07-15 10:11:00',
            $this->rawStartAt($mission->getId()),
            'Must store the true Brussels wall-clock, not the raw UTC digits',
        );
    }

    // ── Round-trip / idempotence over real read-write cycles ────────────────────

    public function test_round_trip_through_the_real_em_does_not_drift(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeMission(
            $surgeon, $instr, $site,
            new \DateTimeImmutable('2026-07-15T10:11:00+02:00'),
            new \DateTimeImmutable('2026-07-15T12:11:00+02:00'),
        );
        $id = $mission->getId();

        for ($i = 0; $i < 3; $i++) {
            $this->em->clear();
            $m = $this->em->find(Mission::class, $id);
            self::assertSame('2026-07-15T10:11:00+02:00', $m->getStartAt()->format(\DateTimeInterface::ATOM), "Drifted on cycle {$i}");
            // Re-set the SAME already-correct value and flush again — must not shift.
            $m->setStartAt($m->getStartAt());
            $this->em->flush();
            self::assertSame('2026-07-15 10:11:00', $this->rawStartAt($id), "DB drifted on cycle {$i}");
        }
    }

    // ── MissionMapper — real hydration, no individual code change needed ────────

    public function test_mission_mapper_receives_a_correctly_labeled_entity_automatically(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeMission(
            $surgeon, $instr, $site,
            new \DateTimeImmutable('2026-07-15T10:11:00+02:00'),
            new \DateTimeImmutable('2026-07-15T12:11:00+02:00'),
        );
        $id = $mission->getId();

        $this->em->clear();
        $reread = $this->em->find(Mission::class, $id);
        $viewer = $this->em->find(User::class, $surgeon->getId());

        $mapper = self::getContainer()->get(MissionMapper::class);
        $detailDto = $mapper->toDetailDto($reread, $viewer);
        $listDto = $mapper->toListDto($reread, $viewer);

        self::assertSame('2026-07-15T10:11:00+02:00', $detailDto->startAt);
        self::assertSame('2026-07-15T12:11:00+02:00', $detailDto->endAt);
        self::assertSame('2026-07-15T10:11:00+02:00', $listDto->startAt);
    }

    // ── SurgeonServiceManager / InstrumentistServiceManager planning APIs ───────
    // (D-065 audit findings #1 and #2 — highest severity, live production endpoints)

    public function test_surgeon_service_manager_planning_gets_the_correct_offset(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $this->makeMission(
            $surgeon, $instr, $site,
            new \DateTimeImmutable('2026-07-15T10:11:00+02:00'),
            new \DateTimeImmutable('2026-07-15T12:11:00+02:00'),
        );

        $this->em->clear();
        $freshSurgeon = $this->em->find(User::class, $surgeon->getId());

        $service = self::getContainer()->get(SurgeonServiceManager::class);
        $rows = $service->getPlanning(
            $freshSurgeon,
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-07-31'),
        );

        self::assertNotEmpty($rows);
        self::assertSame('2026-07-15T10:11:00+02:00', $rows[0]['start']);
        self::assertSame('2026-07-15T12:11:00+02:00', $rows[0]['end']);
    }

    public function test_instrumentist_service_manager_planning_gets_the_correct_offset(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $this->makeMission(
            $surgeon, $instr, $site,
            new \DateTimeImmutable('2026-01-15T10:11:00+01:00'),
            new \DateTimeImmutable('2026-01-15T12:11:00+01:00'),
        );

        $this->em->clear();
        $freshInstr = $this->em->find(User::class, $instr->getId());

        $service = self::getContainer()->get(InstrumentistServiceManager::class);
        $rows = $service->getPlanning(
            $freshInstr,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
        );

        self::assertNotEmpty($rows);
        self::assertSame('2026-01-15T10:11:00+01:00', $rows[0]['start']);
        self::assertSame('2026-01-15T12:11:00+01:00', $rows[0]['end']);
    }

    // ── Manually-formatted payload: MissionPostDeployService audit trail ────────
    // (D-065 audit finding #5)

    public function test_manually_formatted_audit_payload_gets_the_correct_offset(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeMission(
            $surgeon, $instr, $site,
            new \DateTimeImmutable('2026-07-15T10:11:00+02:00'),
            new \DateTimeImmutable('2026-07-15T12:11:00+02:00'),
        );
        $id = $mission->getId();

        $this->em->clear();
        $reread = $this->em->find(Mission::class, $id);
        $actor = $this->em->find(User::class, $surgeon->getId());

        $service = self::getContainer()->get(MissionPostDeployService::class);
        $service->updateSchedule(
            $reread,
            $actor,
            new \DateTimeImmutable('2026-07-15T11:11:00+02:00'),
            new \DateTimeImmutable('2026-07-15T13:11:00+02:00'),
            null,
            null,
            notify: false,
        );

        $events = $this->em->createQueryBuilder()
            ->select('e')->from(AuditEvent::class, 'e')
            ->where('e.mission = :m')->setParameter('m', $reread)
            ->getQuery()->getResult();

        self::assertCount(1, $events);
        $payload = $events[0]->getPayload();
        self::assertSame('2026-07-15T10:11:00+02:00', $payload['fromStartAt']);
        self::assertSame('2026-07-15T11:11:00+02:00', $payload['toStartAt']);
    }

    // ── PlanningAlertService::serializeMission() ─────────────────────────────────
    // (D-065 audit finding #3 — a second, independent mission-serialization path)

    public function test_planning_alert_service_serialization_gets_the_correct_offset(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeMission(
            $surgeon, $instr, $site,
            new \DateTimeImmutable('2026-07-15T10:11:00+02:00'),
            new \DateTimeImmutable('2026-07-15T12:11:00+02:00'),
        );
        $id = $mission->getId();

        $this->em->clear();
        $reread = $this->em->find(Mission::class, $id);

        $service = self::getContainer()->get(PlanningAlertService::class);
        $result = $service->createIfNotDuplicate($reread, PlanningAlertType::SURGEON_ABSENCE, null, ['note' => 'test']);
        $this->em->flush();

        $serialized = $service->serialize($result['alert']);

        self::assertSame('2026-07-15T10:11:00+02:00', $serialized['mission']['startAt']);
        self::assertSame('2026-07-15T12:11:00+02:00', $serialized['mission']['endAt']);
    }

    // ── ExportService::exportSurgeonActivity() ───────────────────────────────────
    // (D-065 audit finding #4)

    public function test_export_service_surgeon_activity_gets_the_correct_offset(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $this->makeMission(
            $surgeon, $instr, $site,
            new \DateTimeImmutable('2026-07-15T10:11:00+02:00'),
            new \DateTimeImmutable('2026-07-15T12:11:00+02:00'),
        );

        $this->em->clear();
        $freshSurgeon = $this->em->find(User::class, $surgeon->getId());

        $dto = new ExportSurgeonActivityRequest();
        $dto->periodStart = '2026-07-01';
        $dto->periodEnd = '2026-07-31';

        $service = self::getContainer()->get(ExportService::class);
        $result = $service->exportSurgeonActivity($freshSurgeon, $dto);
        $this->em->flush(); // ExportLog persisted internally

        self::assertNotEmpty($result['items']);
        self::assertSame('2026-07-15T10:11:00+02:00', $result['items'][0]['startAt']);
    }

    // ── AbsenceImpactService snapshot (PlanningAlert.snapshotJson) ───────────────
    // (D-065 audit finding #6 — dormant: no endpoint reads this column back today,
    // but the persisted JSON must still carry the correct offset for whenever one does)

    public function test_absence_impact_service_snapshot_gets_the_correct_offset(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeMission(
            $surgeon, $instr, $site,
            new \DateTimeImmutable('2026-07-15T10:11:00+02:00'),
            new \DateTimeImmutable('2026-07-15T12:11:00+02:00'),
        );
        $missionId = $mission->getId();

        $absence = new Absence();
        $absence->setUser($surgeon);
        $absence->setCreatedBy($surgeon);
        $absence->setDateStart(new \DateTimeImmutable('2026-07-15'));
        $absence->setDateEnd(new \DateTimeImmutable('2026-07-15'));
        $absence->setReason('Test D-066');
        $this->em->persist($absence);
        $this->em->flush();
        $this->createdIds['absences'][] = $absence->getId();

        $this->em->clear();
        $reread = $this->em->find(Absence::class, $absence->getId());

        $service = self::getContainer()->get(AbsenceImpactService::class);
        $service->onAbsenceCreated($reread);
        $this->em->flush();

        $freshMission = $this->em->find(Mission::class, $missionId);
        $alerts = $this->em->createQueryBuilder()
            ->select('a')->from(PlanningAlert::class, 'a')
            ->where('a.mission = :m')->setParameter('m', $freshMission)
            ->getQuery()->getResult();

        self::assertCount(1, $alerts);
        $snapshot = $alerts[0]->getSnapshotJson();
        self::assertSame('2026-07-15T10:11:00+02:00', $snapshot['missionStartAt']);
        self::assertSame('2026-07-15T12:11:00+02:00', $snapshot['missionEndAt']);
    }

    // ── NotificationService in-app payload ────────────────────────────────────────
    // (D-065 audit finding #7 — dormant: no endpoint exposes NotificationEvent.payload
    // today, but it must still carry the correct offset)

    public function test_notification_service_payload_gets_the_correct_offset(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeMission(
            $surgeon, $instr, $site,
            new \DateTimeImmutable('2026-07-15T10:11:00+02:00'),
            new \DateTimeImmutable('2026-07-15T12:11:00+02:00'),
        );
        $id = $mission->getId();
        $instrId = $instr->getId();

        $this->em->clear();
        $reread = $this->em->find(Mission::class, $id);

        $service = self::getContainer()->get(NotificationService::class);
        $service->planningMissionAssignedNotifyInstrumentist($reread);
        $this->em->flush();

        $freshInstr = $this->em->find(User::class, $instrId);
        $notifications = $this->em->createQueryBuilder()
            ->select('n')->from(NotificationEvent::class, 'n')
            ->where('n.user = :u')->setParameter('u', $freshInstr)
            ->getQuery()->getResult();

        self::assertCount(1, $notifications);
        self::assertSame('2026-07-15T10:11:00+02:00', $notifications[0]->getPayload()['startAt']);
    }
}
