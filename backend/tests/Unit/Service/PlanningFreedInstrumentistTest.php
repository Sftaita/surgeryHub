<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\PlanningSlot;
use App\Entity\PlanningTemplate;
use App\Entity\User;
use App\Enum\MissionType;
use App\Enum\PlanningTemplateType;
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
 * Tests the second-pass "freed instrumentist" auto-assignment logic in preview().
 *
 * Test date: 2026-01-05 (Monday, ISO week 2 = PAIR).
 * All templates use type TOUTES so week parity never filters them out.
 * All slots use dayOfWeek=1 (Monday).
 */
class PlanningFreedInstrumentistTest extends TestCase
{
    private const TEST_DATE = '2026-01-05';

    private EntityManagerInterface&MockObject $em;
    private PlanningScoreService&MockObject   $scoreService;

    private array $templates       = [];
    private bool  $surgeonAbsent   = false; // global flag (overridden per surgeon in callback)
    private array $surgeonAbsences = []; // [surgeonEmail => bool]
    private int   $absenceCall     = 0;

    protected function setUp(): void
    {
        $this->em           = $this->createMock(EntityManagerInterface::class);
        $this->scoreService = $this->createMock(PlanningScoreService::class);
        $this->absenceCall  = 0;

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

        // createQuery routes by DQL content
        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql): AbstractQuery {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();

                if (str_contains($dql, 'absencesFrom')) {
                    // No absences in freed instrumentist tests (absences are set per-test via custom em)
                    $q->method('getArrayResult')->willReturn([]);
                } elseif (str_contains($dql, 'poolFrom')) {
                    $q->method('getResult')->willReturn([]); // no existing missions
                }

                return $q;
            });
    }

    private function makeService(): PlanningGeneratorService
    {
        return new PlanningGeneratorService($this->em, $this->scoreService);
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Alpha');
        $rp = new \ReflectionProperty(Hospital::class, 'id');
        $rp->setValue($h, 1);
        return $h;
    }

    private static int $userIdSeq = 0;

    private function makeUser(string $email): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setRoles(['ROLE_INSTRUMENTIST']);
        $u->setActive(true);
        // Set auto-increment ID so getId() returns a non-null value
        $rp = new \ReflectionProperty(User::class, 'id');
        $rp->setValue($u, ++self::$userIdSeq);
        return $u;
    }

    private function makeSlot(int $day, User $surgeon, ?User $instrumentist, string $start = '08:00:00', string $end = '13:00:00'): PlanningSlot
    {
        $s = new PlanningSlot();
        $s->setDayOfWeek($day);
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
        foreach ($slots as $slot) {
            $t->addSlot($slot);
        }
        return $t;
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * When surgeon A is absent (slot SKIPPED), their instrumentist (shared)
     * should be auto-assigned to surgeon B's UNCOVERED slot on the same day.
     */
    public function test_freed_instrumentist_auto_assigned_to_uncovered_slot(): void
    {
        $surgeonA      = $this->makeUser('surgeon-a@test.com'); // will be absent
        $surgeonB      = $this->makeUser('surgeon-b@test.com'); // present, needs instrumentist
        $instrumentist = $this->makeUser('inst@test.com');

        $site = $this->makeSite();

        // Slot A: surgeon absent → will be SKIPPED, instrumentist freed
        $slotA = $this->makeSlot(1, $surgeonA, $instrumentist);
        // Slot B: no instrumentist → would be UNCOVERED without second pass
        $slotB = $this->makeSlot(1, $surgeonB, null);

        // Two separate templates so both slots exist in the preview
        $templateA = $this->makeTemplate($site, $slotA);
        $templateB = $this->makeTemplate($site, $slotB);

        // Configure absence: surgeon A is absent (first absence check per slot)
        // We need slot A to return absent, slot B to return present.
        // Since createQuery is called once per surgeon per slot, we use a call counter.
        $callCount = 0;
        $this->em = $this->createMock(EntityManagerInterface::class);

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

        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql) use ($surgeonA): AbstractQuery {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();

                if (str_contains($dql, 'absencesFrom')) {
                    $q->method('getArrayResult')->willReturn([
                        ['userId' => $surgeonA->getId(), 'dateStart' => self::TEST_DATE, 'dateEnd' => self::TEST_DATE],
                    ]);
                } elseif (str_contains($dql, 'poolFrom')) {
                    $q->method('getResult')->willReturn([]);
                }

                return $q;
            });

        $this->templates = [$templateA, $templateB];

        $result = (new PlanningGeneratorService($this->em, $this->scoreService))
            ->preview(self::TEST_DATE, self::TEST_DATE, null, null);

        $this->assertCount(2, $result);

        $skippedLine  = array_values(array_filter($result, fn ($l) => $l['status'] === 'SKIPPED'))[0]  ?? null;
        $coveredLine  = array_values(array_filter($result, fn ($l) => $l['status'] === 'COVERED'))[0]  ?? null;
        $uncoveredLine = array_values(array_filter($result, fn ($l) => $l['status'] === 'UNCOVERED'))[0] ?? null;

        $this->assertNotNull($skippedLine,  'Slot A (surgeon absent) must be SKIPPED');
        $this->assertNotNull($coveredLine,  'Slot B must be auto-COVERED with the freed instrumentist');
        $this->assertNull($uncoveredLine,   'No UNCOVERED line should remain after second pass');

        $this->assertTrue($coveredLine['freedFrom'], 'COVERED line must have freedFrom=true');
        $this->assertSame('inst@test.com', $coveredLine['instrumentistName']);
    }

    /**
     * Françoise is freed (surgeon absent, her slot was AM 08-13).
     * Two surgeons need coverage: Jérôme (AM 08-13) and Samy (PM 13-17).
     * Since Jérôme's slot ends at 13:00 and Samy's starts at 13:00 (no overlap),
     * Françoise must be auto-assigned to BOTH.
     */
    public function test_freed_instrumentist_assigned_to_both_am_and_pm_slots(): void
    {
        $surgeonAbsent = $this->makeUser('surgeon-absent@test.com');
        $surgeonAM     = $this->makeUser('surgeon-am@test.com');   // morning, no instr
        $surgeonPM     = $this->makeUser('surgeon-pm@test.com');   // afternoon, no instr
        $francoise     = $this->makeUser('francoise@test.com');

        $site = $this->makeSite();

        // Slot absent surgeon (AM 08-13): SKIPPED → Françoise freed
        $slotAbsent = $this->makeSlot(1, $surgeonAbsent, $francoise, '08:00:00', '13:00:00');
        // Slot AM (08-13): no instrumentist → should get Françoise
        $slotAM = $this->makeSlot(1, $surgeonAM, null, '08:00:00', '13:00:00');
        // Slot PM (13-17): no instrumentist → should ALSO get Françoise (no overlap)
        $slotPM = $this->makeSlot(1, $surgeonPM, null, '13:00:00', '17:00:00');

        $callCount = 0;
        $this->em = $this->createMock(EntityManagerInterface::class);

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

        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql) use ($surgeonAbsent): AbstractQuery {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                if (str_contains($dql, 'absencesFrom')) {
                    $q->method('getArrayResult')->willReturn([
                        ['userId' => $surgeonAbsent->getId(), 'dateStart' => self::TEST_DATE, 'dateEnd' => self::TEST_DATE],
                    ]);
                } elseif (str_contains($dql, 'poolFrom')) {
                    $q->method('getResult')->willReturn([]);
                }
                return $q;
            });

        $this->templates = [
            $this->makeTemplate($site, $slotAbsent),
            $this->makeTemplate($site, $slotAM),
            $this->makeTemplate($site, $slotPM),
        ];

        $result = (new PlanningGeneratorService($this->em, $this->scoreService))
            ->preview(self::TEST_DATE, self::TEST_DATE, null, null);

        $this->assertCount(3, $result);

        $skipped   = array_values(array_filter($result, fn ($l) => $l['status'] === 'SKIPPED'));
        $covered   = array_values(array_filter($result, fn ($l) => $l['status'] === 'COVERED'));
        $uncovered = array_values(array_filter($result, fn ($l) => $l['status'] === 'UNCOVERED'));

        $this->assertCount(1, $skipped,   'One SKIPPED (absent surgeon)');
        $this->assertCount(2, $covered,   'Both AM and PM slots must be COVERED by Françoise');
        $this->assertCount(0, $uncovered, 'No UNCOVERED lines should remain');

        foreach ($covered as $line) {
            $this->assertTrue($line['freedFrom'], 'Both covered lines must have freedFrom=true');
            $this->assertSame($francoise->getEmail(), $line['instrumentistName'],
                'Françoise must be assigned to both slots');
        }
    }

    public function test_freed_instrumentist_not_assigned_when_time_overlaps_active_slot(): void
    {
        // The freed instrumentist already has another active slot at the same time on the same day.
        // They should NOT be auto-assigned to the UNCOVERED slot.

        $surgeonA      = $this->makeUser('surgeon-a@test.com'); // absent
        $surgeonB      = $this->makeUser('surgeon-b@test.com'); // present, UNCOVERED
        $surgeonC      = $this->makeUser('surgeon-c@test.com'); // present, instrumentist busy here
        $instrumentist = $this->makeUser('inst@test.com');

        $site = $this->makeSite();

        // Slot A: surgeon A absent → SKIPPED, instrumentist freed
        $slotA = $this->makeSlot(1, $surgeonA, $instrumentist, '08:00:00', '13:00:00');
        // Slot B: no instrumentist → UNCOVERED
        $slotB = $this->makeSlot(1, $surgeonB, null, '08:00:00', '13:00:00');
        // Slot C: same instrumentist active at same time → prevents reassignment
        $slotC = $this->makeSlot(1, $surgeonC, $instrumentist, '09:00:00', '12:00:00');

        $callCount = 0;
        $this->em = $this->createMock(EntityManagerInterface::class);

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

        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql) use ($surgeonA): AbstractQuery {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();

                if (str_contains($dql, 'absencesFrom')) {
                    $q->method('getArrayResult')->willReturn([
                        ['userId' => $surgeonA->getId(), 'dateStart' => self::TEST_DATE, 'dateEnd' => self::TEST_DATE],
                    ]);
                } elseif (str_contains($dql, 'poolFrom')) {
                    $q->method('getResult')->willReturn([]);
                }

                return $q;
            });

        $this->templates = [
            $this->makeTemplate($site, $slotA),
            $this->makeTemplate($site, $slotB),
            $this->makeTemplate($site, $slotC),
        ];

        $result = (new PlanningGeneratorService($this->em, $this->scoreService))
            ->preview(self::TEST_DATE, self::TEST_DATE, null, null);

        $uncoveredLines = array_values(array_filter($result, fn ($l) => $l['status'] === 'UNCOVERED'));
        $this->assertNotEmpty($uncoveredLines, 'Slot B must remain UNCOVERED — freed instrumentist is blocked by active slot C');
    }

    /**
     * REGRESSION — bug: second pass only processed UNCOVERED, not COVERED+no-instr.
     *
     * If the condition in resolveFreedInstrumentists() reverts to checking only UNCOVERED,
     * this test MUST fail: the freed instrumentist will not be assigned to lines that are
     * COVERED (existing mission) but have no instrumentist.
     */
    public function test_regression_second_pass_must_process_covered_lines_with_no_instrumentist(): void
    {
        // Surgeon A is absent → instrumentist Françoise is freed.
        // Surgeon B has an EXISTING DRAFT mission with NO instrumentist (COVERED in preview).
        //
        // Bug condition: second pass only checked `status === 'UNCOVERED'`.
        // The Surgeon B line was COVERED (mission exists), so Françoise was never proposed.
        //
        // Fix: second pass also checks `status === 'COVERED' && instrumentistId === null`.

        $surgeonAbsent = $this->makeUser('surgeon-absent@test.com');
        $surgeonB      = $this->makeUser('surgeon-b@test.com');
        $francoise     = $this->makeUser('francoise@test.com');
        $site          = $this->makeSite();

        $slotAbsent = $this->makeSlot(1, $surgeonAbsent, $francoise, '08:00:00', '13:00:00');
        $slotB      = $this->makeSlot(1, $surgeonB,      null,       '08:00:00', '13:00:00');

        // Surgeon B already has an existing DRAFT mission with NO instrumentist.
        // Must have surgeon + site set so loadExistingMissionsPool() can build the pool key.
        $existingMission = $this->makeMissionWithId(99, null);
        $existingMission->setSurgeon($surgeonB);
        $existingMission->setCreatedBy($surgeonB);
        $existingMission->setSite($site);

        $this->em = $this->createMock(EntityManagerInterface::class);

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

        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql) use ($surgeonAbsent, $existingMission): AbstractQuery {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();

                if (str_contains($dql, 'absencesFrom')) {
                    $q->method('getArrayResult')->willReturn([
                        ['userId' => $surgeonAbsent->getId(), 'dateStart' => self::TEST_DATE, 'dateEnd' => self::TEST_DATE],
                    ]);
                } elseif (str_contains($dql, 'poolFrom')) {
                    // loadExistingMissionsPool → return surgeon B's existing mission (no instrumentist)
                    $q->method('getResult')->willReturn([$existingMission]);
                }

                return $q;
            });

        $this->templates = [
            $this->makeTemplate($site, $slotAbsent),
            $this->makeTemplate($site, $slotB),
        ];

        $result = (new PlanningGeneratorService($this->em, $this->scoreService))
            ->preview(self::TEST_DATE, self::TEST_DATE, null, null);

        $this->assertCount(2, $result);

        $skippedLines  = array_values(array_filter($result, fn ($l) => $l['status'] === 'SKIPPED'));
        $coveredLines  = array_values(array_filter($result, fn ($l) => $l['status'] === 'COVERED'));
        $uncoveredLines = array_values(array_filter($result, fn ($l) => $l['status'] === 'UNCOVERED'));

        $this->assertCount(1, $skippedLines, 'Absent surgeon line must be SKIPPED');

        // This assertion catches the regression:
        // If second pass reverts to UNCOVERED-only, surgeon B's line stays COVERED with null instrumentist
        // and instrumentistName would be null (Françoise not assigned).
        $this->assertCount(1, $coveredLines, 'Surgeon B line must be COVERED');
        $this->assertCount(0, $uncoveredLines, 'No UNCOVERED lines');

        $coveredLine = $coveredLines[0];
        $this->assertNotNull($coveredLine['instrumentistId'],
            'REGRESSION: freed instrumentist must be assigned to COVERED+no-instr line. ' .
            'If this fails, resolveFreedInstrumentists() only checks UNCOVERED, not COVERED+null-instr.');
        $this->assertTrue($coveredLine['freedFrom'],
            'freedFrom must be true for auto-assigned freed instrumentist');
        $this->assertSame($francoise->getEmail(), $coveredLine['instrumentistName']);
    }

    /**
     * Real-world case from the screenshot:
     * - Stéphane absent AM + PM → Françoise freed all day
     * - Jérôme: has an existing DRAFT mission (COVERED) but with NO instrumentist
     * - Samy: same
     * → Françoise must be auto-assigned to BOTH (the existing missions need an instrumentist)
     *
     * This tests that the second pass treats COVERED+no-instr lines, not just UNCOVERED.
     */
    public function test_freed_instrumentist_assigned_to_covered_missions_without_instrumentist(): void
    {
        $stephane  = $this->makeUser('stephane@test.com');  // absent
        $jerome    = $this->makeUser('jerome@test.com');    // has existing mission, no instr
        $samy      = $this->makeUser('samy@test.com');      // has existing mission, no instr
        $francoise = $this->makeUser('francoise@test.com'); // freed from Stéphane's slots

        $site = $this->makeSite();

        // Stéphane: AM + PM slots, both SKIPPED → Françoise freed all day
        $slotStephaneAM = $this->makeSlot(1, $stephane, $francoise, '08:00:00', '13:00:00');
        $slotStephanePM = $this->makeSlot(1, $stephane, $francoise, '13:00:00', '17:00:00');
        // Jérôme: AM slot, no instrumentist in template
        $slotJerome = $this->makeSlot(1, $jerome, null, '08:00:00', '13:00:00');
        // Samy: PM slot, no instrumentist in template
        $slotSamy = $this->makeSlot(1, $samy, null, '13:00:00', '17:00:00');

        // Simulate existing DRAFT missions for Jérôme and Samy (already generated, no instr).
        // Must have surgeon + site set so loadExistingMissionsPool() can build the pool key.
        $existingJerome = $this->makeMissionWithId(100, null);
        $existingJerome->setSurgeon($jerome);
        $existingJerome->setCreatedBy($jerome);
        $existingJerome->setSite($site);
        $existingJerome->setStartAt(new \DateTimeImmutable('2026-01-05 08:00:00'));

        $existingSamy = $this->makeMissionWithId(101, null);
        $existingSamy->setSurgeon($samy);
        $existingSamy->setCreatedBy($samy);
        $existingSamy->setSite($site);
        $existingSamy->setStartAt(new \DateTimeImmutable('2026-01-05 13:00:00'));

        $this->em = $this->createMock(EntityManagerInterface::class);

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

        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql) use ($stephane, $existingJerome, $existingSamy): AbstractQuery {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();

                if (str_contains($dql, 'absencesFrom')) {
                    // Stéphane is absent the whole day (has both AM and PM slots)
                    $q->method('getArrayResult')->willReturn([
                        ['userId' => $stephane->getId(), 'dateStart' => self::TEST_DATE, 'dateEnd' => self::TEST_DATE],
                    ]);
                } elseif (str_contains($dql, 'poolFrom')) {
                    // loadExistingMissionsPool returns all missions for the period (1 call)
                    $q->method('getResult')->willReturn([$existingJerome, $existingSamy]);
                }

                return $q;
            });

        $this->templates = [
            $this->makeTemplate($site, $slotStephaneAM, $slotStephanePM),
            $this->makeTemplate($site, $slotJerome),
            $this->makeTemplate($site, $slotSamy),
        ];

        $result = (new PlanningGeneratorService($this->em, $this->scoreService))
            ->preview(self::TEST_DATE, self::TEST_DATE, null, null);

        // We should have 4 lines: 2 SKIPPED + 2 COVERED (with Françoise)
        $skipped  = array_filter($result, fn ($l) => $l['status'] === 'SKIPPED');
        $covered  = array_filter($result, fn ($l) => $l['status'] === 'COVERED');
        $uncovered = array_filter($result, fn ($l) => $l['status'] === 'UNCOVERED');

        $this->assertCount(2, $skipped,   '2 SKIPPED lines for Stéphane AM + PM');
        $this->assertCount(2, $covered,   'Jérôme and Samy must be COVERED with Françoise');
        $this->assertCount(0, $uncovered, 'No UNCOVERED lines must remain');

        foreach ($covered as $line) {
            $this->assertTrue($line['freedFrom'], 'freedFrom must be true for freed-assigned lines');
            $this->assertSame($francoise->getEmail(), $line['instrumentistName']);
        }
    }

    private function makeMissionWithId(int $id, ?User $instrumentist): \App\Entity\Mission
    {
        $m = new \App\Entity\Mission();
        $m->setStatus(\App\Enum\MissionStatus::DRAFT);
        $m->setType(\App\Enum\MissionType::BLOCK);
        $m->setSchedulePrecision(\App\Enum\SchedulePrecision::EXACT);
        $m->setStartAt(new \DateTimeImmutable('2026-01-05 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-01-05 13:00:00'));
        $m->setInstrumentist($instrumentist);

        $site = new \App\Entity\Hospital();
        $site->setName('Alpha');
        $m->setSite($site);

        $rp = new \ReflectionProperty(\App\Entity\Mission::class, 'id');
        $rp->setValue($m, $id);
        return $m;
    }

    public function test_preview_lines_always_have_freed_from_field(): void
    {
        // Every preview line must have 'freedFrom' set (default false, true if auto-assigned).
        $surgeonA = $this->makeUser('surgeon@test.com');
        $slot     = $this->makeSlot(1, $surgeonA, null);
        $site     = $this->makeSite();
        $this->templates = [$this->makeTemplate($site, $slot)];
        // No absences needed for this structural test

        $result = $this->makeService()->preview(self::TEST_DATE, self::TEST_DATE, null, null);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('freedFrom', $result[0]);
        $this->assertFalse($result[0]['freedFrom']);
        $this->assertArrayHasKey('existingInstrumentistId', $result[0]);
        $this->assertArrayHasKey('existingInstrumentistName', $result[0]);
    }
}
