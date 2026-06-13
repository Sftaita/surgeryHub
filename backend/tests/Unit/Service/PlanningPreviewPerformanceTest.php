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
 * Tests the performance optimization of PlanningGeneratorService::preview():
 *
 * Before: 1 DB query per day (templates) + 1-3 DB queries per slot (absences, conflicts)
 *         → ~1 830 queries for 61 days × 10 slots/day
 *
 * After:  3 DB queries total for any period length:
 *   1. loadAllTemplates()     — templates + slots, filtered by site
 *   2. loadAbsencesMap()      — all absences in the period (token: "absencesFrom")
 *   3. loadExistingMissionsPool() — all missions in the period (token: "poolFrom")
 *
 * All filtering (PAIR/IMPAIR weeks, absences, conflicts) is done IN MEMORY.
 */
class PlanningPreviewPerformanceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PlanningScoreService&MockObject   $scoreService;

    private array $templates    = [];
    private array $absenceRows  = [];
    private array $poolMissions = [];

    private int $qbCallCount      = 0;
    private int $absenceCallCount = 0;
    private int $poolCallCount    = 0;

    private static int $idSeq = 0;

    protected function setUp(): void
    {
        $this->em           = $this->createMock(EntityManagerInterface::class);
        $this->scoreService = $this->createMock(PlanningScoreService::class);
        self::$idSeq        = 0;
        $this->templates    = [];
        $this->absenceRows  = [];
        $this->poolMissions = [];
        $this->qbCallCount      = 0;
        $this->absenceCallCount = 0;
        $this->poolCallCount    = 0;

        // QB → loadAllTemplates (counted to verify called once)
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

        $this->em->method('createQueryBuilder')->willReturnCallback(function () use ($qb): QueryBuilder {
            $this->qbCallCount++;
            return $qb;
        });

        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql): AbstractQuery {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();

                if (str_contains($dql, 'absencesFrom')) {
                    $this->absenceCallCount++;
                    $q->method('getArrayResult')->willReturnCallback(fn () => $this->absenceRows);
                } elseif (str_contains($dql, 'poolFrom')) {
                    $this->poolCallCount++;
                    $q->method('getResult')->willReturnCallback(fn () => $this->poolMissions);
                }

                return $q;
            });

        $this->em->method('persist');
        $this->em->method('flush');
    }

    // ── Factories ─────────────────────────────────────────────────────────────

    private function id(): int { return ++self::$idSeq; }

    private function makeUser(string $email): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setRoles(['ROLE_SURGEON']);
        $u->setActive(true);
        $rp = new \ReflectionProperty(User::class, 'id');
        $rp->setValue($u, $this->id());
        return $u;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Alpha');
        $rp = new \ReflectionProperty(Hospital::class, 'id');
        $rp->setValue($h, $this->id());
        return $h;
    }

    private function makeSlot(User $surgeon, ?User $instrumentist = null, int $dow = 1): PlanningSlot
    {
        $s = new PlanningSlot();
        $s->setDayOfWeek($dow);
        $s->setPeriod(SlotPeriod::AM);
        $s->setStartTime(new \DateTimeImmutable('08:00:00'));
        $s->setEndTime(new \DateTimeImmutable('13:00:00'));
        $s->setMissionType(MissionType::BLOCK);
        $s->setSurgeon($surgeon);
        $s->setInstrumentist($instrumentist);
        return $s;
    }

    private function makeTemplate(Hospital $site, PlanningTemplateType $type, PlanningSlot ...$slots): PlanningTemplate
    {
        $t = new PlanningTemplate();
        $t->setType($type);
        $t->setSite($site);
        foreach ($slots as $slot) { $t->addSlot($slot); }
        return $t;
    }

    private function preview(string $from, string $to): array
    {
        return (new PlanningGeneratorService($this->em, $this->scoreService))
            ->preview($from, $to, null, null);
    }

    // ── Tests: single pre-load queries ────────────────────────────────────────

    /**
     * Templates must be loaded ONCE regardless of the number of days in the preview.
     * Before the optimization, QB was called once per day (61 calls for a 2-month preview).
     */
    public function test_templates_loaded_exactly_once_regardless_of_period_length(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $this->templates = [$this->makeTemplate($this->makeSite(), PlanningTemplateType::TOUTES,
            $this->makeSlot($surgeon, null, 1), // Monday
        )];

        // 61-day preview
        $this->preview('2026-04-28', '2026-06-28');

        $this->assertSame(1, $this->qbCallCount,
            'REGRESSION: createQueryBuilder() must be called exactly ONCE (loadAllTemplates). ' .
            'If > 1, templates are still being loaded per day instead of pre-loaded.'
        );
    }

    /**
     * The absence query must be called ONCE for the full period.
     * Before the optimization, it was called once per slot (surgeon + instrumentist check).
     */
    public function test_absence_query_called_exactly_once_for_full_period(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $this->templates = [$this->makeTemplate($this->makeSite(), PlanningTemplateType::TOUTES,
            $this->makeSlot($surgeon, null, 1),
            $this->makeSlot($surgeon, null, 2),
            $this->makeSlot($surgeon, null, 3),
        )];

        $this->preview('2026-04-28', '2026-06-28');

        $this->assertSame(1, $this->absenceCallCount,
            'REGRESSION: absence query must be called exactly ONCE (loadAbsencesMap). ' .
            'If > 1, absence checks are still being done per slot with separate DB queries.'
        );
    }

    /**
     * The missions pool query must be called ONCE for the full period.
     */
    public function test_missions_pool_query_called_exactly_once(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $this->templates = [$this->makeTemplate($this->makeSite(), PlanningTemplateType::TOUTES,
            $this->makeSlot($surgeon),
        )];

        $this->preview('2026-04-28', '2026-06-28');

        $this->assertSame(1, $this->poolCallCount,
            'REGRESSION: pool query must be called exactly ONCE (loadExistingMissionsPool).'
        );
    }

    // ── Tests: in-memory week-type filtering ──────────────────────────────────

    /**
     * PAIR template must only generate lines on even ISO weeks.
     * 2026-01-05 = Monday, week 2 (PAIR) → line generated.
     * 2026-01-12 = Monday, week 3 (IMPAIR) → no line.
     */
    public function test_pair_template_applied_only_on_even_weeks(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $this->templates = [$this->makeTemplate($this->makeSite(), PlanningTemplateType::PAIR,
            $this->makeSlot($surgeon, null, 1), // Monday
        )];

        // Week 2 (PAIR) + Week 3 (IMPAIR)
        $result = $this->preview('2026-01-05', '2026-01-11'); // week 2 only
        $this->assertCount(1, $result, 'PAIR template must produce 1 line on a PAIR week');

        $this->qbCallCount = 0; // reset for next preview

        $result = $this->preview('2026-01-12', '2026-01-18'); // week 3 (IMPAIR)
        $this->assertCount(0, $result, 'PAIR template must produce 0 lines on an IMPAIR week');
    }

    /**
     * IMPAIR template must only generate lines on odd ISO weeks.
     */
    public function test_impair_template_applied_only_on_odd_weeks(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $this->templates = [$this->makeTemplate($this->makeSite(), PlanningTemplateType::IMPAIR,
            $this->makeSlot($surgeon, null, 1),
        )];

        // Week 3 (IMPAIR)
        $result = $this->preview('2026-01-12', '2026-01-18');
        $this->assertCount(1, $result, 'IMPAIR template must produce 1 line on an IMPAIR week');

        $this->qbCallCount = 0;

        // Week 2 (PAIR)
        $result = $this->preview('2026-01-05', '2026-01-11');
        $this->assertCount(0, $result, 'IMPAIR template must produce 0 lines on a PAIR week');
    }

    /**
     * TOUTES template is applied every week regardless of parity.
     */
    public function test_toutes_template_applied_every_week(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $this->templates = [$this->makeTemplate($this->makeSite(), PlanningTemplateType::TOUTES,
            $this->makeSlot($surgeon, null, 1),
        )];

        // One full week PAIR + one full week IMPAIR = 2 lines total
        $result = $this->preview('2026-01-05', '2026-01-18');
        $this->assertCount(2, $result, 'TOUTES template must produce a line on every week');
    }

    // ── Tests: in-memory absence detection ────────────────────────────────────

    /**
     * An absence spanning the exact day must mark the surgeon as absent.
     */
    public function test_absence_exact_day_detected_in_memory(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $this->templates = [$this->makeTemplate($this->makeSite(), PlanningTemplateType::TOUTES,
            $this->makeSlot($surgeon, null, 1), // Monday
        )];
        // Surgeon absent on 2026-01-05
        $this->absenceRows = [
            ['userId' => $surgeon->getId(), 'dateStart' => '2026-01-05', 'dateEnd' => '2026-01-05'],
        ];

        $result = $this->preview('2026-01-05', '2026-01-05');

        $this->assertCount(1, $result);
        $this->assertSame('SKIPPED', $result[0]['status'],
            'Surgeon with absence on the slot day must produce SKIPPED status'
        );
    }

    /**
     * An absence spanning multiple days must be detected for each covered day.
     */
    public function test_multi_day_absence_detected_across_days(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $this->templates = [$this->makeTemplate($this->makeSite(), PlanningTemplateType::TOUTES,
            $this->makeSlot($surgeon, null, 1), // Monday
            $this->makeSlot($surgeon, null, 2), // Tuesday
            $this->makeSlot($surgeon, null, 3), // Wednesday
        )];
        // Absent Mon-Wed
        $this->absenceRows = [
            ['userId' => $surgeon->getId(), 'dateStart' => '2026-01-05', 'dateEnd' => '2026-01-07'],
        ];

        $result = $this->preview('2026-01-05', '2026-01-07');

        $this->assertCount(3, $result);
        foreach ($result as $line) {
            $this->assertSame('SKIPPED', $line['status'],
                "All 3 days in absence range must be SKIPPED: {$line['date']}"
            );
        }
    }

    /**
     * An absence on a different user must not affect other users.
     */
    public function test_absence_only_affects_the_absent_user(): void
    {
        $surgeonA = $this->makeUser('surgeon-a@test.com'); // absent
        $surgeonB = $this->makeUser('surgeon-b@test.com'); // present
        $site     = $this->makeSite();

        $this->templates = [
            $this->makeTemplate($site, PlanningTemplateType::TOUTES, $this->makeSlot($surgeonA, null, 1)),
            $this->makeTemplate($site, PlanningTemplateType::TOUTES, $this->makeSlot($surgeonB, null, 1)),
        ];
        $this->absenceRows = [
            ['userId' => $surgeonA->getId(), 'dateStart' => '2026-01-05', 'dateEnd' => '2026-01-05'],
        ];

        $result = $this->preview('2026-01-05', '2026-01-05');

        $this->assertCount(2, $result);
        $statuses = array_column($result, 'status');
        $this->assertContains('SKIPPED',   $statuses, 'Absent surgeon must be SKIPPED');
        $this->assertContains('UNCOVERED', $statuses, 'Present surgeon must not be affected');
    }

    // ── Tests: in-memory conflict detection ───────────────────────────────────

    /**
     * A mission in the pool that overlaps the slot must produce CONFLICT status.
     */
    public function test_conflict_detected_via_in_memory_pool(): void
    {
        $surgeon      = $this->makeUser('surgeon@test.com');
        $instrumentist = $this->makeUser('instr@test.com');
        $site         = $this->makeSite();

        $this->templates = [$this->makeTemplate($site, PlanningTemplateType::TOUTES,
            $this->makeSlot($surgeon, $instrumentist, 1), // Mon 08:00-13:00
        )];

        // Existing mission for the same instrumentist that overlaps 08:00-13:00
        $conflicting = new Mission();
        $conflicting->setStatus(MissionStatus::OPEN);
        $conflicting->setType(MissionType::BLOCK);
        $conflicting->setSchedulePrecision(SchedulePrecision::EXACT);
        $conflicting->setStartAt(new \DateTimeImmutable('2026-01-05 09:00:00'));
        $conflicting->setEndAt(new \DateTimeImmutable('2026-01-05 14:00:00'));
        $conflicting->setInstrumentist($instrumentist);
        $conflicting->setSurgeon($surgeon);
        $conflicting->setCreatedBy($surgeon);
        $conflicting->setSite($site);
        $rp = new \ReflectionProperty(Mission::class, 'id');
        $rp->setValue($conflicting, 999);
        $this->poolMissions = [$conflicting];

        $result = $this->preview('2026-01-05', '2026-01-05');

        $this->assertCount(1, $result);
        $this->assertSame('CONFLICT', $result[0]['status'],
            'An instrumentist with an overlapping mission in the pool must produce CONFLICT'
        );
    }

    /**
     * A REJECTED mission in the pool must NOT produce a conflict.
     */
    public function test_rejected_mission_in_pool_does_not_cause_conflict(): void
    {
        $surgeon      = $this->makeUser('surgeon@test.com');
        $instrumentist = $this->makeUser('instr@test.com');
        $site         = $this->makeSite();

        $this->templates = [$this->makeTemplate($site, PlanningTemplateType::TOUTES,
            $this->makeSlot($surgeon, $instrumentist, 1),
        )];

        $rejected = new Mission();
        $rejected->setStatus(MissionStatus::REJECTED); // rejected → ignored in conflict check
        $rejected->setType(MissionType::BLOCK);
        $rejected->setSchedulePrecision(SchedulePrecision::EXACT);
        $rejected->setStartAt(new \DateTimeImmutable('2026-01-05 09:00:00'));
        $rejected->setEndAt(new \DateTimeImmutable('2026-01-05 14:00:00'));
        $rejected->setInstrumentist($instrumentist);
        $rejected->setSurgeon($surgeon);
        $rejected->setCreatedBy($surgeon);
        $rejected->setSite($site);
        $rp = new \ReflectionProperty(Mission::class, 'id');
        $rp->setValue($rejected, 888);
        $this->poolMissions = [$rejected];

        $result = $this->preview('2026-01-05', '2026-01-05');

        $this->assertCount(1, $result);
        $this->assertSame('COVERED', $result[0]['status'],
            'A REJECTED mission must not produce a conflict'
        );
    }

    /**
     * A non-overlapping mission in the pool must NOT conflict.
     */
    public function test_non_overlapping_mission_does_not_conflict(): void
    {
        $surgeon      = $this->makeUser('surgeon@test.com');
        $instrumentist = $this->makeUser('instr@test.com');
        $site         = $this->makeSite();

        $this->templates = [$this->makeTemplate($site, PlanningTemplateType::TOUTES,
            $this->makeSlot($surgeon, $instrumentist, 1), // 08:00-13:00
        )];

        // Mission strictly after the slot (13:00-17:00 — no overlap)
        $after = new Mission();
        $after->setStatus(MissionStatus::OPEN);
        $after->setType(MissionType::BLOCK);
        $after->setSchedulePrecision(SchedulePrecision::EXACT);
        $after->setStartAt(new \DateTimeImmutable('2026-01-05 13:00:00')); // starts exactly at slot end
        $after->setEndAt(new \DateTimeImmutable('2026-01-05 17:00:00'));
        $after->setInstrumentist($instrumentist);
        $after->setSurgeon($surgeon);
        $after->setCreatedBy($surgeon);
        $after->setSite($site);
        $rp = new \ReflectionProperty(Mission::class, 'id');
        $rp->setValue($after, 777);
        $this->poolMissions = [$after];

        $result = $this->preview('2026-01-05', '2026-01-05');

        $this->assertCount(1, $result);
        $this->assertSame('COVERED', $result[0]['status'],
            'A mission starting exactly at slot end must NOT conflict (strict overlap: start < end)'
        );
    }

    // ── Test: no extra queries during the loop ─────────────────────────────────

    /**
     * A 2-month preview (61 days) must still only use 3 DB queries total.
     * This directly validates the performance claim.
     */
    public function test_two_month_preview_uses_only_3_db_queries(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $this->templates = [$this->makeTemplate($this->makeSite(), PlanningTemplateType::TOUTES,
            $this->makeSlot($surgeon, null, 1), // Mon
            $this->makeSlot($surgeon, null, 3), // Wed
            $this->makeSlot($surgeon, null, 5), // Fri
        )];

        $this->preview('2026-04-28', '2026-06-28'); // 61 days

        $totalQueries = $this->qbCallCount + $this->absenceCallCount + $this->poolCallCount;

        $this->assertSame(1, $this->qbCallCount,      'loadAllTemplates: exactly 1 QB call');
        $this->assertSame(1, $this->absenceCallCount, 'loadAbsencesMap: exactly 1 DQL call');
        $this->assertSame(1, $this->poolCallCount,    'loadExistingMissionsPool: exactly 1 DQL call');
        $this->assertSame(3, $totalQueries,
            'REGRESSION: total DB queries for 2-month preview must be exactly 3. ' .
            "Got: QB={$this->qbCallCount}, absence={$this->absenceCallCount}, pool={$this->poolCallCount}. " .
            'If any count > 1, queries are being made inside the day/slot loop.'
        );
    }
}
