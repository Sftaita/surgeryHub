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
use App\Enum\SlotPeriod;
use App\Service\PlanningGeneratorService;
use App\Service\PlanningScoreService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the status-determination logic of PlanningGeneratorService::preview().
 *
 * Test date: 2026-01-05 (Monday, ISO week 2 = PAIR).
 * Templates use type TOUTES so week-parity never filters them out.
 * Slots use dayOfWeek=1 to match that Monday.
 */
class PlanningGeneratorServiceTest extends TestCase
{
    private const TEST_DATE = '2026-01-05';

    private EntityManagerInterface&MockObject $em;
    private PlanningScoreService&MockObject   $scoreService;

    private array $templates        = [];
    private array $existingMissions = [];
    /** Absence rows returned by loadAbsencesMap: [['userId' => int, 'dateStart' => str, 'dateEnd' => str]] */
    private array $absenceRows      = [];

    private static int $userIdSeq = 0;

    protected function setUp(): void
    {
        $this->em           = $this->createMock(EntityManagerInterface::class);
        $this->scoreService = $this->createMock(PlanningScoreService::class);
        self::$userIdSeq    = 0;
        $this->absenceRows  = [];

        // ── QueryBuilder → template list (loadAllTemplates) ──────────────────
        $innerQuery = $this->createMock(Query::class);
        $innerQuery->method('getResult')->willReturnCallback(fn () => $this->templates);
        $innerQuery->method('getSingleScalarResult')->willReturn(null);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('leftJoin')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($innerQuery);
        $this->em->method('createQueryBuilder')->willReturn($qb);

        // ── createQuery: route by DQL content ────────────────────────────────
        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql): Query {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                $q->method('setMaxResults')->willReturnSelf();

                if (str_contains($dql, 'absencesFrom')) {
                    // loadAbsencesMap — returns plain arrays [userId, dateStart, dateEnd]
                    $q->method('getArrayResult')->willReturnCallback(fn () => $this->absenceRows);
                } elseif (str_contains($dql, 'poolFrom')) {
                    // loadExistingMissionsPool
                    $q->method('getResult')->willReturn($this->existingMissions);
                }
                // No other createQuery calls expected (conflict check is now in-memory)
                return $q;
            });
    }

    // ── Factories ─────────────────────────────────────────────────────────────

    private function makeService(): PlanningGeneratorService
    {
        return new PlanningGeneratorService($this->em, $this->scoreService);
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Alpha');
        $rp = new \ReflectionProperty(Hospital::class, 'id');
        $rp->setValue($h, ++self::$userIdSeq);
        return $h;
    }

    private function makeUser(string $email = 'user@test.com'): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setRoles(['ROLE_SURGEON']);
        $u->setActive(true);
        $rp = new \ReflectionProperty(User::class, 'id');
        $rp->setValue($u, ++self::$userIdSeq);
        return $u;
    }

    /** Mark a user as absent on TEST_DATE. */
    private function markAbsent(User $user): void
    {
        $this->absenceRows[] = [
            'userId'    => $user->getId(),
            'dateStart' => self::TEST_DATE,
            'dateEnd'   => self::TEST_DATE,
        ];
    }

    /** Add an existing mission that conflicts with the slot (08:00-13:00 on TEST_DATE). */
    private function addConflictingMission(User $instrumentist): void
    {
        $m = new Mission();
        $m->setStatus(MissionStatus::OPEN);
        $m->setType(MissionType::BLOCK);
        $m->setStartAt(new \DateTimeImmutable(self::TEST_DATE . ' 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable(self::TEST_DATE . ' 13:00:00'));
        $m->setInstrumentist($instrumentist);
        $site = new Hospital(); $site->setName('Other');
        $m->setSite($site);
        $m->setSurgeon($this->makeUser('other-surgeon@test.com'));
        $m->setCreatedBy($m->getSurgeon());
        $m->setSchedulePrecision(\App\Enum\SchedulePrecision::EXACT);
        // Add to existingMissions so buildInstrumentistIndex finds it
        $this->existingMissions[] = $m;
    }

    private function makeSlot(User $surgeon, ?User $instrumentist = null): PlanningSlot
    {
        $s = new PlanningSlot();
        $s->setDayOfWeek(1); // Monday = 2026-01-05
        $s->setPeriod(SlotPeriod::AM);
        $s->setStartTime(new \DateTimeImmutable('08:00:00'));
        $s->setEndTime(new \DateTimeImmutable('13:00:00'));
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
        foreach ($slots as $slot) {
            $t->addSlot($slot);
        }
        return $t;
    }

    private function makeExistingMission(?User $instrumentist = null): Mission
    {
        $m = new Mission();
        $m->setStatus(MissionStatus::ASSIGNED);
        $m->setType(MissionType::BLOCK);
        $m->setStartAt(new \DateTimeImmutable('2026-01-05 08:00:00')); // matches slot start
        $m->setEndAt(new \DateTimeImmutable('2026-01-05 13:00:00'));
        $m->setInstrumentist($instrumentist);
        return $m;
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_returns_empty_when_no_templates(): void
    {
        $this->templates = [];

        $result = $this->makeService()->preview(self::TEST_DATE, self::TEST_DATE, null, null);

        $this->assertSame([], $result);
    }

    public function test_surgeon_absence_produces_skipped_status(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $slot    = $this->makeSlot($surgeon);
        $this->markAbsent($surgeon);

        $this->templates = [$this->makeTemplate($this->makeSite(), $slot)];
        $result = $this->makeService()->preview(self::TEST_DATE, self::TEST_DATE, null, null);

        $this->assertCount(1, $result);
        $this->assertSame('SKIPPED', $result[0]['status']);
    }

    public function test_covered_when_instrumentist_available(): void
    {
        $surgeon      = $this->makeUser('surgeon@test.com');
        $instrumentist = $this->makeUser('inst@test.com');
        $instrumentist->setRoles(['ROLE_INSTRUMENTIST']);
        $slot = $this->makeSlot($surgeon, $instrumentist);

        $this->templates = [$this->makeTemplate($this->makeSite(), $slot)];

        $result = $this->makeService()->preview(self::TEST_DATE, self::TEST_DATE, null, null);

        $this->assertCount(1, $result);
        $this->assertSame('COVERED', $result[0]['status']);
        $this->assertSame('inst@test.com', $result[0]['instrumentistName']);
    }

    public function test_uncovered_when_instrumentist_is_absent(): void
    {
        $surgeon      = $this->makeUser('surgeon@test.com');
        $instrumentist = $this->makeUser('inst@test.com');
        $slot = $this->makeSlot($surgeon, $instrumentist);
        $this->markAbsent($instrumentist);

        $this->templates = [$this->makeTemplate($this->makeSite(), $slot)];

        $result = $this->makeService()->preview(self::TEST_DATE, self::TEST_DATE, null, null);

        $this->assertCount(1, $result);
        $this->assertSame('UNCOVERED', $result[0]['status']);
        $this->assertNull($result[0]['instrumentistId'], 'Absent instrumentist must be cleared from output');
        $this->assertSame('', $result[0]['instrumentistName']);
    }

    public function test_uncovered_when_slot_has_no_instrumentist(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $slot    = $this->makeSlot($surgeon, null);

        $this->templates = [$this->makeTemplate($this->makeSite(), $slot)];

        $result = $this->makeService()->preview(self::TEST_DATE, self::TEST_DATE, null, null);

        $this->assertCount(1, $result);
        $this->assertSame('UNCOVERED', $result[0]['status']);
    }

    public function test_conflict_when_instrumentist_has_overlapping_mission(): void
    {
        $surgeon      = $this->makeUser('surgeon@test.com');
        $instrumentist = $this->makeUser('inst@test.com');
        $slot = $this->makeSlot($surgeon, $instrumentist);
        // Add an existing mission that conflicts with this slot (same instr, same time)
        $this->addConflictingMission($instrumentist);

        $this->templates = [$this->makeTemplate($this->makeSite(), $slot)];

        $result = $this->makeService()->preview(self::TEST_DATE, self::TEST_DATE, null, null);

        $this->assertCount(1, $result);
        $this->assertSame('CONFLICT', $result[0]['status']);
    }

    public function test_intra_preview_conflict_detected_across_templates(): void
    {
        $surgeonA = $this->makeUser('surgeon-a@test.com');
        $surgeonB = $this->makeUser('surgeon-b@test.com');
        $shared   = $this->makeUser('shared-inst@test.com');

        $slotA = $this->makeSlot($surgeonA, $shared); // 08:00-13:00
        $slotB = $this->makeSlot($surgeonB, $shared); // same time — overlap

        $site = $this->makeSite();
        $this->templates = [
            $this->makeTemplate($site, $slotA),
            $this->makeTemplate($site, $slotB),
        ];

        $result = $this->makeService()->preview(self::TEST_DATE, self::TEST_DATE, null, null);

        $this->assertCount(2, $result);
        $this->assertSame('COVERED', $result[0]['status']);
        $this->assertSame('CONFLICT', $result[1]['status']);
    }

    /**
     * REGRESSION — multi-room scenario: same surgeon, same time, two slots with different instrumentists.
     *
     * Before fix: findExistingMission() always returned the SAME mission for both slots.
     *   → Slot 1 (Salve) matched mission → COVERED
     *   → Slot 2 (Sophie) same mission found, but Sophie ≠ Salve → MODIFIED (incorrect)
     *
     * After fix: claimMission() assigns each mission exclusively.
     *   → Two missions in DB → each slot claims its exact match → both COVERED
     *   → One mission in DB → slot 1 claims it (exact), slot 2 gets nothing → UNCOVERED
     */
    public function test_multi_room_two_slots_same_surgeon_same_time_each_claim_own_mission(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $salve   = $this->makeUser('salve@test.com');
        $sophie  = $this->makeUser('sophie@test.com');

        $site = $this->makeSite();

        // Two slots: same surgeon, same AM time, different instrumentists (multi-room)
        $slotSalve  = $this->makeSlot($surgeon, $salve);   // 08:00-13:00
        $slotSophie = $this->makeSlot($surgeon, $sophie);  // 08:00-13:00

        // Two existing missions: one with Salve, one with Sophie
        $missionSalve  = $this->makeExistingMission($salve);
        $missionSophie = $this->makeExistingMission($sophie);

        // Set surgeons/sites on missions so claimMission() pool key matches
        $rpM = new \ReflectionProperty(\App\Entity\Mission::class, 'id');
        $rpM->setValue($missionSalve,  100);
        $rpM->setValue($missionSophie, 101);

        $missionSalve->setSurgeon($surgeon);
        $missionSalve->setSite($site);
        $missionSophie->setSurgeon($surgeon);
        $missionSophie->setSite($site);

        $this->templates        = [$this->makeTemplate($site, $slotSalve, $slotSophie)];
        $this->existingMissions = [$missionSalve, $missionSophie];

        $result = $this->makeService()->preview(self::TEST_DATE, self::TEST_DATE, null, null);

        $this->assertCount(2, $result);

        foreach ($result as $line) {
            $this->assertSame('COVERED', $line['status'],
                'REGRESSION: both multi-room slots must be COVERED — each must claim its own mission. ' .
                'If one is MODIFIED, claimMission() is returning the same mission for both slots.');
        }

        // Each slot must show its own instrumentist
        $names = array_column($result, 'instrumentistName');
        $this->assertContains('salve@test.com', $names);
        $this->assertContains('sophie@test.com', $names);
    }

}
