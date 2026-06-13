<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningSlot;
use App\Entity\PlanningTemplate;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningTemplateType;
use App\Enum\SchedulePrecision;
use App\Enum\SlotPeriod;
use App\Service\PlanningGeneratorService;
use App\Service\PlanningScoreService;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Multi-room scenario: one surgeon operates in two (or more) rooms simultaneously.
 * Same surgeon, same site, same time → multiple slots with different instrumentists.
 *
 * Test date: 2026-01-05 (Monday, ISO week 2 = PAIR, dayOfWeek=1).
 *
 * Key rule tested: claimMission() assigns each existing mission to at most ONE slot.
 * Without exclusive claiming, both slots would get the SAME mission → MODIFIED on the second.
 */
class PlanningMultiRoomTest extends TestCase
{
    private const TEST_DATE = '2026-01-05';

    private EntityManagerInterface&MockObject $em;
    private PlanningScoreService&MockObject   $scoreService;

    /** Templates returned by the QB template query */
    private array $templates    = [];

    /** Missions returned by loadExistingMissionsPool (routed via "poolFrom" DQL token) */
    private array $poolMissions = [];

    private static int $idSeq = 0;

    protected function setUp(): void
    {
        $this->em           = $this->createMock(EntityManagerInterface::class);
        $this->scoreService = $this->createMock(PlanningScoreService::class);
        self::$idSeq        = 0;
        $this->poolMissions = [];

        // ── QB → template query ───────────────────────────────────────────────
        $templateQuery = $this->createMock(Query::class);
        $templateQuery->method('getResult')->willReturnCallback(fn () => $this->templates);
        $templateQuery->method('getSingleScalarResult')->willReturn(null);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('leftJoin')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($templateQuery);
        $this->em->method('createQueryBuilder')->willReturn($qb);

        // ── DQL routing — same pattern as other working test classes ─────────
        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql): AbstractQuery {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();

                if (str_contains($dql, 'absencesFrom')) {
                    $q->method('getArrayResult')->willReturn([]); // no absences
                } elseif (str_contains($dql, 'poolFrom')) {
                    // Capture $this by reference so the test can set poolMissions later
                    $q->method('getResult')->willReturnCallback(fn () => $this->poolMissions);
                } else {
                    $q->method('getSingleScalarResult')->willReturn(0);
                    $q->method('getResult')->willReturn([]);
                }

                return $q;
            });

        $this->em->method('persist');
        $this->em->method('flush');
    }

    // ── Factories ─────────────────────────────────────────────────────────────

    private function id(): int { return ++self::$idSeq; }

    private function makeUser(string $email, string $role = 'ROLE_INSTRUMENTIST'): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setRoles([$role]);
        $u->setActive(true);
        $rp = new \ReflectionProperty(User::class, 'id');
        $rp->setValue($u, $this->id());
        return $u;
    }

    private function makeSite(string $name = 'Alpha'): Hospital
    {
        $h = new Hospital();
        $h->setName($name);
        $rp = new \ReflectionProperty(Hospital::class, 'id');
        $rp->setValue($h, $this->id());
        return $h;
    }

    private function makeSlot(User $surgeon, ?User $instrumentist, string $start = '08:00:00', string $end = '13:00:00'): PlanningSlot
    {
        $s = new PlanningSlot();
        $s->setDayOfWeek(1);
        $s->setPeriod(SlotPeriod::AM);
        $s->setStartTime(new \DateTimeImmutable($start));
        $s->setEndTime(new \DateTimeImmutable($end));
        $s->setMissionType(MissionType::BLOCK);
        $s->setSurgeon($surgeon);
        $s->setInstrumentist($instrumentist);
        return $s;
    }

    private function makeTemplate(Hospital $site, PlanningSlot ...$slots): PlanningTemplate
    {
        $t = new PlanningTemplate();
        $t->setType(PlanningTemplateType::TOUTES);
        $t->setSite($site);
        foreach ($slots as $slot) { $t->addSlot($slot); }
        return $t;
    }

    /**
     * Create an existing DRAFT mission with surgeon, site, startAt and instrumentist already set.
     * These are the missions returned by loadExistingMissionsPool().
     */
    private function makeMission(User $surgeon, Hospital $site, ?User $instrumentist, string $startAt = '2026-01-05 08:00:00'): Mission
    {
        $m = new Mission();
        $m->setStatus(MissionStatus::DRAFT);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setStartAt(new \DateTimeImmutable($startAt));
        $m->setEndAt(new \DateTimeImmutable(str_replace('08:00', '13:00', $startAt)));
        $m->setSurgeon($surgeon);
        $m->setSite($site);
        $m->setInstrumentist($instrumentist);
        $m->setCreatedBy($surgeon);
        $rp = new \ReflectionProperty(Mission::class, 'id');
        $rp->setValue($m, $this->id());
        return $m;
    }

    private function preview(array $templates): array
    {
        $this->templates = $templates;
        return (new PlanningGeneratorService($this->em, $this->scoreService))
            ->preview(self::TEST_DATE, self::TEST_DATE, null, null);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * REGRESSION — the original bug.
     *
     * Two slots, same surgeon, same AM time, different instrumentists.
     * Two missions in DB, one per instrumentist.
     *
     * Bug: findExistingMission() returned the same mission for both slots.
     *      Slot 1 (Salve) → COVERED. Slot 2 (Sophie) → MODIFIED (Salve ≠ Sophie).
     *
     * Fix: claimMission() assigns each mission exclusively.
     *      Slot 1 claims mission-Salve (exact match). Slot 2 claims mission-Sophie. → Both COVERED.
     */
    public function test_two_rooms_same_time_each_slot_claims_its_own_mission(): void
    {
        $surgeon = $this->makeUser('arnaud@test.com', 'ROLE_SURGEON');
        $salve   = $this->makeUser('salve@test.com');
        $sophie  = $this->makeUser('sophie@test.com');
        $site    = $this->makeSite();

        $missionSalve  = $this->makeMission($surgeon, $site, $salve);
        $missionSophie = $this->makeMission($surgeon, $site, $sophie);

        $this->poolMissions = [$missionSalve, $missionSophie];

        $result = $this->preview([
            $this->makeTemplate($site,
                $this->makeSlot($surgeon, $salve),   // Room A
                $this->makeSlot($surgeon, $sophie),  // Room B
            ),
        ]);

        $this->assertCount(2, $result, '2 slots must produce 2 preview lines');

        foreach ($result as $line) {
            $this->assertSame('COVERED', $line['status'],
                'REGRESSION: both multi-room slots must be COVERED. ' .
                'If one is MODIFIED, claimMission() returned the same mission for both slots.'
            );
        }

        $names = array_column($result, 'instrumentistName');
        sort($names);
        $this->assertSame(['salve@test.com', 'sophie@test.com'], $names,
            'Each slot must display its own instrumentist, not the same one twice'
        );
    }

    /**
     * One existing mission (Salve) but two slots (Salve + Sophie).
     *
     * Bug: findExistingMission() returned the same mission for both slots.
     *      → Salve slot: COVERED. Sophie slot: MODIFIED (mission.instrumentist=Salve ≠ slot.instrumentist=Sophie).
     *
     * Fix: claimMission() assigns each mission exclusively.
     *      → Salve slot: COVERED with the existing mission (exact match).
     *      → Sophie slot: no unclaimed mission → COVERED with no existingMissionId
     *                     (instrumentist Sophie is available, generate() will create a new mission).
     *
     * The key assertion: Sophie's slot must NOT be MODIFIED (that would mean the same
     * Salve mission was returned for Sophie's slot, which is the regression).
     */
    public function test_one_mission_two_slots_second_slot_is_covered_not_modified(): void
    {
        $surgeon    = $this->makeUser('arnaud@test.com', 'ROLE_SURGEON');
        $salve      = $this->makeUser('salve@test.com');
        $sophie     = $this->makeUser('sophie@test.com');
        $site       = $this->makeSite();
        $missionSalve = $this->makeMission($surgeon, $site, $salve);

        // Only Salve's mission exists in DB
        $this->poolMissions = [$missionSalve];

        $result = $this->preview([
            $this->makeTemplate($site,
                $this->makeSlot($surgeon, $salve),
                $this->makeSlot($surgeon, $sophie),
            ),
        ]);

        $this->assertCount(2, $result);

        $byInstr = array_column($result, null, 'instrumentistName');

        // Salve: claims its existing mission
        $this->assertSame('COVERED', $byInstr['salve@test.com']['status'],
            'Slot with existing mission (Salve) must be COVERED'
        );
        $this->assertSame($missionSalve->getId(), $byInstr['salve@test.com']['existingMissionId'],
            'Salve slot must reference the existing Salve mission'
        );

        // Sophie: no existing mission, but instrumentist is available → COVERED (new mission at generate)
        $this->assertSame('COVERED', $byInstr['sophie@test.com']['status'],
            'Sophie slot must be COVERED (instrumentist available, new mission will be created at generate)'
        );
        $this->assertNull($byInstr['sophie@test.com']['existingMissionId'],
            'Sophie slot must have no existingMissionId — generate() will create a new mission for her'
        );

        // REGRESSION: the bug would make Sophie's slot MODIFIED (wrong mission claimed)
        $this->assertNotSame('MODIFIED', $byInstr['sophie@test.com']['status'],
            'REGRESSION: Sophie must NOT be MODIFIED. MODIFIED means the Salve mission ' .
            'was returned for Sophie\'s slot (same mission claimed twice).'
        );
    }

    /**
     * Three rooms simultaneously: same surgeon, same time, three different instrumentists.
     * Three missions in DB. Each slot must claim its own.
     */
    public function test_three_rooms_same_time_each_claims_own_mission(): void
    {
        $surgeon = $this->makeUser('arnaud@test.com', 'ROLE_SURGEON');
        $inst1   = $this->makeUser('inst1@test.com');
        $inst2   = $this->makeUser('inst2@test.com');
        $inst3   = $this->makeUser('inst3@test.com');
        $site    = $this->makeSite();

        $this->poolMissions = [
            $this->makeMission($surgeon, $site, $inst1),
            $this->makeMission($surgeon, $site, $inst2),
            $this->makeMission($surgeon, $site, $inst3),
        ];

        $result = $this->preview([
            $this->makeTemplate($site,
                $this->makeSlot($surgeon, $inst1),
                $this->makeSlot($surgeon, $inst2),
                $this->makeSlot($surgeon, $inst3),
            ),
        ]);

        $this->assertCount(3, $result);

        $statuses = array_column($result, 'status');
        $this->assertSame(['COVERED', 'COVERED', 'COVERED'], $statuses,
            'All three rooms must be COVERED, each with a distinct mission'
        );

        $existingIds = array_unique(array_column($result, 'existingMissionId'));
        $this->assertCount(3, $existingIds, 'All three lines must reference different missions');
    }

    /**
     * Multi-room AM + single slot PM — no interference between morning and afternoon.
     */
    public function test_multi_room_am_does_not_affect_pm_slot(): void
    {
        $surgeon = $this->makeUser('arnaud@test.com', 'ROLE_SURGEON');
        $salve   = $this->makeUser('salve@test.com');
        $sophie  = $this->makeUser('sophie@test.com');
        $cecile  = $this->makeUser('cecile@test.com');
        $site    = $this->makeSite();

        $this->poolMissions = [
            $this->makeMission($surgeon, $site, $salve,  '2026-01-05 08:00:00'), // AM room A
            $this->makeMission($surgeon, $site, $sophie, '2026-01-05 08:00:00'), // AM room B
            $this->makeMission($surgeon, $site, $cecile, '2026-01-05 13:00:00'), // PM
        ];

        $result = $this->preview([
            $this->makeTemplate($site,
                $this->makeSlot($surgeon, $salve,  '08:00:00', '13:00:00'), // AM room A
                $this->makeSlot($surgeon, $sophie, '08:00:00', '13:00:00'), // AM room B
                $this->makeSlot($surgeon, $cecile, '13:00:00', '17:00:00'), // PM
            ),
        ]);

        $this->assertCount(3, $result);

        foreach ($result as $line) {
            $this->assertSame('COVERED', $line['status'],
                "All slots (AM room A, AM room B, PM) must be COVERED. Failed on: {$line['instrumentistName']}"
            );
        }

        $existingIds = array_unique(array_column($result, 'existingMissionId'));
        $this->assertCount(3, $existingIds, 'Each of the 3 slots must claim a distinct mission');
    }

    /**
     * Slot order should not matter: even if Sophie's slot comes first in the template,
     * it should still claim Sophie's mission (exact match priority).
     */
    public function test_exact_match_priority_regardless_of_slot_order(): void
    {
        $surgeon = $this->makeUser('arnaud@test.com', 'ROLE_SURGEON');
        $salve   = $this->makeUser('salve@test.com');
        $sophie  = $this->makeUser('sophie@test.com');
        $site    = $this->makeSite();

        $mSalve  = $this->makeMission($surgeon, $site, $salve);
        $mSophie = $this->makeMission($surgeon, $site, $sophie);

        $this->poolMissions = [$mSalve, $mSophie];

        // Sophie's slot comes FIRST in the template this time
        $result = $this->preview([
            $this->makeTemplate($site,
                $this->makeSlot($surgeon, $sophie), // Sophie first
                $this->makeSlot($surgeon, $salve),  // Salve second
            ),
        ]);

        $this->assertCount(2, $result);

        $byInstr = [];
        foreach ($result as $line) {
            $byInstr[$line['instrumentistName']] = $line;
        }

        $this->assertSame('COVERED', $byInstr['sophie@test.com']['status']);
        $this->assertSame('COVERED', $byInstr['salve@test.com']['status']);

        // Each slot must reference its OWN mission (exact match priority)
        $this->assertSame($mSophie->getId(), $byInstr['sophie@test.com']['existingMissionId'],
            'Sophie slot must claim Sophie mission regardless of slot order');
        $this->assertSame($mSalve->getId(), $byInstr['salve@test.com']['existingMissionId'],
            'Salve slot must claim Salve mission regardless of slot order');
    }
}
