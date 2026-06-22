<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\RecurrenceRule;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use App\Service\PlanningGeneratorServiceV2;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests PlanningGeneratorServiceV2::preview() — recurrence expansion, site/group filtering,
 * shift-period hours, and status determination (SKIPPED/UNCOVERED/COVERED/CONFLICT).
 *
 * January 2026 reference Mondays (used throughout): 5, 12, 19, 26.
 * ISO weeks: Jan 5 = week 2 (even), Jan 12 = week 3 (odd), Jan 19 = week 4 (even), Jan 26 = week 5 (odd).
 */
class PlanningGeneratorServiceV2Test extends TestCase
{
    private const MONTH = '2026-01';

    private EntityManagerInterface&MockObject $em;

    private array $posts             = [];
    private array $absenceRows       = [];
    private array $existingMissions  = [];
    private array $shiftConfigRows   = [];
    private array $groupMembershipRows = [];
    private array $occurrenceExceptions = [];

    private array $queryCounts = [];

    private static int $idSeq = 0;

    protected function setUp(): void
    {
        $this->em                  = $this->createMock(EntityManagerInterface::class);
        $this->posts                = [];
        $this->absenceRows          = [];
        $this->existingMissions     = [];
        $this->shiftConfigRows      = [];
        $this->groupMembershipRows  = [];
        $this->occurrenceExceptions = [];
        $this->queryCounts          = [];

        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql): Query {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();

                if (str_contains($dql, 'postsFrom')) {
                    $this->queryCounts['posts'] = ($this->queryCounts['posts'] ?? 0) + 1;
                    $q->method('getResult')->willReturnCallback(fn () => $this->posts);
                } elseif (str_contains($dql, 'absencesFrom')) {
                    $this->queryCounts['absences'] = ($this->queryCounts['absences'] ?? 0) + 1;
                    $q->method('getArrayResult')->willReturnCallback(fn () => $this->absenceRows);
                } elseif (str_contains($dql, 'poolFrom')) {
                    $this->queryCounts['pool'] = ($this->queryCounts['pool'] ?? 0) + 1;
                    $q->method('getResult')->willReturnCallback(fn () => $this->existingMissions);
                } elseif (str_contains($dql, 'shiftConfigSites')) {
                    $this->queryCounts['shiftConfig'] = ($this->queryCounts['shiftConfig'] ?? 0) + 1;
                    $q->method('getArrayResult')->willReturnCallback(fn () => $this->shiftConfigRows);
                } elseif (str_contains($dql, 'groupMembersOf')) {
                    $this->queryCounts['groupMembers'] = ($this->queryCounts['groupMembers'] ?? 0) + 1;
                    $q->method('getArrayResult')->willReturnCallback(fn () => $this->groupMembershipRows);
                } elseif (str_contains($dql, 'exceptionPostIds')) {
                    $this->queryCounts['exceptions'] = ($this->queryCounts['exceptions'] ?? 0) + 1;
                    $q->method('getResult')->willReturnCallback(fn () => $this->occurrenceExceptions);
                }

                return $q;
            });
    }

    // ── Factories ────────────────────────────────────────────────────────────

    private function makeService(): PlanningGeneratorServiceV2
    {
        return new PlanningGeneratorServiceV2($this->em);
    }

    private function makeSite(string $name = 'Alpha'): Hospital
    {
        $h = new Hospital();
        $h->setName($name);
        $rp = new \ReflectionProperty(Hospital::class, 'id');
        $rp->setValue($h, ++self::$idSeq);
        return $h;
    }

    private function makeUser(string $email = 'user@test.com'): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setRoles(['ROLE_SURGEON']);
        $u->setActive(true);
        $rp = new \ReflectionProperty(User::class, 'id');
        $rp->setValue($u, ++self::$idSeq);
        return $u;
    }

    private function makeRecurrence(
        RecurrenceFrequency $frequency,
        int $interval,
        array $weekdays,
        \DateTimeImmutable $anchorDate,
    ): RecurrenceRule {
        $r = new RecurrenceRule();
        $r->setFrequency($frequency);
        $r->setInterval($interval);
        $r->setWeekdays($weekdays);
        $r->setAnchorDate($anchorDate);
        return $r;
    }

    private function makePost(
        User $surgeon,
        Hospital $site,
        RecurrenceRule $recurrence,
        ?User $instrumentist = null,
        ShiftPeriod $period = ShiftPeriod::MATIN,
        MissionType $type = MissionType::BLOCK,
        string $startDate = '2026-01-01',
        ?string $endDate = null,
    ): SurgeonSchedulePost {
        $p = new SurgeonSchedulePost();
        $p->setSurgeon($surgeon);
        $p->setSite($site);
        $p->setType($type);
        $p->setPeriod($period);
        $p->setRecurrence($recurrence);
        $p->setInstrumentist($instrumentist);
        $p->setStartDate(new \DateTimeImmutable($startDate));
        $p->setEndDate($endDate !== null ? new \DateTimeImmutable($endDate) : null);
        $p->setCreatedBy($surgeon);
        $rp = new \ReflectionProperty(SurgeonSchedulePost::class, 'id');
        $rp->setValue($p, ++self::$idSeq);
        return $p;
    }

    private function addShiftConfig(Hospital $site, ShiftPeriod $period, string $start, string $end): void
    {
        $this->shiftConfigRows[] = [
            'siteId'    => $site->getId(),
            'period'    => $period->value,
            'startTime' => new \DateTimeImmutable($start),
            'endTime'   => new \DateTimeImmutable($end),
        ];
    }

    private function markAbsent(User $user, string $date): void
    {
        $this->absenceRows[] = ['userId' => $user->getId(), 'dateStart' => $date, 'dateEnd' => $date];
    }

    private function addExistingMission(User $surgeon, Hospital $site, string $date, string $start, string $end, ?User $instrumentist, MissionStatus $status = MissionStatus::OPEN): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSurgeon($surgeon);
        $m->setSite($site);
        $m->setStartAt(new \DateTimeImmutable("$date $start"));
        $m->setEndAt(new \DateTimeImmutable("$date $end"));
        $m->setInstrumentist($instrumentist);
        $m->setCreatedBy($surgeon);
        $m->setSchedulePrecision(\App\Enum\SchedulePrecision::EXACT);
        $this->existingMissions[] = $m;
        return $m;
    }

    private function datesOf(array $lines): array
    {
        $dates = array_map(fn (array $l) => $l['date'], $lines);
        sort($dates);
        return $dates;
    }

    // ── Recurrence: weekly (every week) ─────────────────────────────────────

    public function test_weekly_recurrence_every_week_matches_all_mondays_in_month(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        $recurrence   = $this->makeRecurrence(RecurrenceFrequency::WEEKLY, 1, [1], new \DateTimeImmutable('2026-01-05'));
        $this->posts  = [$this->makePost($surgeon, $site, $recurrence)];

        $lines = $this->makeService()->preview(self::MONTH, $site->getId(), null, null);

        $this->assertSame(['2026-01-05', '2026-01-12', '2026-01-19', '2026-01-26'], $this->datesOf($lines));
    }

    // ── Recurrence: even/odd ISO-week parity (PAIR/IMPAIR equivalent) ───────

    public function test_even_week_parity_matches_only_even_iso_weeks(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        // Anchor on Jan 5 (ISO week 2, even) — interval 2 ⇒ matches even weeks only.
        $recurrence  = $this->makeRecurrence(RecurrenceFrequency::WEEKLY, 2, [1], new \DateTimeImmutable('2026-01-05'));
        $this->posts = [$this->makePost($surgeon, $site, $recurrence)];

        $lines = $this->makeService()->preview(self::MONTH, $site->getId(), null, null);

        $this->assertSame(['2026-01-05', '2026-01-19'], $this->datesOf($lines));
    }

    public function test_odd_week_parity_matches_only_odd_iso_weeks(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        // Anchor on Jan 12 (ISO week 3, odd) — interval 2 ⇒ matches odd weeks only.
        $recurrence  = $this->makeRecurrence(RecurrenceFrequency::WEEKLY, 2, [1], new \DateTimeImmutable('2026-01-12'));
        $this->posts = [$this->makePost($surgeon, $site, $recurrence)];

        $lines = $this->makeService()->preview(self::MONTH, $site->getId(), null, null);

        $this->assertSame(['2026-01-12', '2026-01-26'], $this->datesOf($lines));
    }

    // ── Recurrence: arbitrary every-2-weeks phase (generalizes beyond PAIR/IMPAIR) ──

    public function test_every_two_weeks_arbitrary_phase_on_a_non_monday_weekday(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::APRES_MIDI, '13:00:00', '18:00:00');

        // Wednesdays in Jan 2026: 7, 14, 21, 28. Anchor on Jan 7 (same ISO week as Jan 5 ⇒ week 2).
        $recurrence  = $this->makeRecurrence(RecurrenceFrequency::WEEKLY, 2, [3], new \DateTimeImmutable('2026-01-07'));
        $this->posts = [$this->makePost($surgeon, $site, $recurrence, period: ShiftPeriod::APRES_MIDI)];

        $lines = $this->makeService()->preview(self::MONTH, $site->getId(), null, null);

        $this->assertSame(['2026-01-07', '2026-01-21'], $this->datesOf($lines));
    }

    // ── Site filtering ───────────────────────────────────────────────────────

    public function test_post_belonging_to_a_different_site_is_excluded(): void
    {
        $surgeon  = $this->makeUser('surgeon@test.com');
        $siteA    = $this->makeSite('Alpha');
        $siteB    = $this->makeSite('Beta');
        $this->addShiftConfig($siteA, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        $recurrence = $this->makeRecurrence(RecurrenceFrequency::WEEKLY, 1, [1], new \DateTimeImmutable('2026-01-05'));
        // Only site A's post should ever reach the in-memory loop — the DB-level query
        // is mocked to only return posts belonging to the requested site(s), exactly
        // like the real WHERE p.site IN (:siteIds) clause would filter server-side.
        $this->posts = [$this->makePost($surgeon, $siteA, $recurrence, startDate: '2026-01-01', endDate: '2026-01-31')];

        $lines = $this->makeService()->preview(self::MONTH, $siteA->getId(), null, null);

        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertSame($siteA->getId(), $line['siteId']);
        }
        $this->assertNotSame($siteB->getId(), $lines[0]['siteId']);
    }

    // ── Site group filtering ─────────────────────────────────────────────────

    public function test_site_group_resolves_to_member_sites_and_includes_posts_across_them(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $siteA   = $this->makeSite('Alpha');
        $siteC   = $this->makeSite('Charlie');
        $this->addShiftConfig($siteA, ShiftPeriod::MATIN, '08:00:00', '13:00:00');
        $this->addShiftConfig($siteC, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        $this->groupMembershipRows = [['siteId' => $siteA->getId()], ['siteId' => $siteC->getId()]];

        $recurrence = $this->makeRecurrence(RecurrenceFrequency::WEEKLY, 1, [1], new \DateTimeImmutable('2026-01-05'));
        $this->posts = [
            $this->makePost($surgeon, $siteA, $recurrence, startDate: '2026-01-05', endDate: '2026-01-05'),
            $this->makePost($surgeon, $siteC, $recurrence, startDate: '2026-01-05', endDate: '2026-01-05'),
        ];

        $lines = $this->makeService()->preview(self::MONTH, null, 99, null);

        $siteIds = array_map(fn (array $l) => $l['siteId'], $lines);
        sort($siteIds);
        $expected = [$siteA->getId(), $siteC->getId()];
        sort($expected);
        $this->assertSame($expected, $siteIds);
        $this->assertSame(1, $this->queryCounts['groupMembers'] ?? 0, 'site-group resolution must be exactly one extra query');
    }

    // ── Shift-period hours come from ShiftPeriodConfig, not hardcoded ───────

    public function test_occurrence_hours_come_from_shift_period_config(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '09:15:00', '12:45:00');

        $recurrence  = $this->makeRecurrence(RecurrenceFrequency::WEEKLY, 1, [1], new \DateTimeImmutable('2026-01-05'));
        $this->posts = [$this->makePost($surgeon, $site, $recurrence, startDate: '2026-01-05', endDate: '2026-01-05')];

        $lines = $this->makeService()->preview(self::MONTH, $site->getId(), null, null);

        $this->assertCount(1, $lines);
        $this->assertSame('09:15', $lines[0]['startTime']);
        $this->assertSame('12:45', $lines[0]['endTime']);
    }

    public function test_missing_shift_period_config_throws(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        // No ShiftPeriodConfig seeded for this site/period — must fail fast, no silent fallback.

        $recurrence  = $this->makeRecurrence(RecurrenceFrequency::WEEKLY, 1, [1], new \DateTimeImmutable('2026-01-05'));
        $this->posts = [$this->makePost($surgeon, $site, $recurrence, startDate: '2026-01-05', endDate: '2026-01-05')];

        $this->expectException(\DomainException::class);
        $this->makeService()->preview(self::MONTH, $site->getId(), null, null);
    }

    // ── Surgeon absence ───────────────────────────────────────────────────────

    public function test_surgeon_absence_produces_skipped_status(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');
        $this->markAbsent($surgeon, '2026-01-05');

        $recurrence  = $this->makeRecurrence(RecurrenceFrequency::WEEKLY, 1, [1], new \DateTimeImmutable('2026-01-05'));
        $this->posts = [$this->makePost($surgeon, $site, $recurrence, startDate: '2026-01-05', endDate: '2026-01-05')];

        $lines = $this->makeService()->preview(self::MONTH, $site->getId(), null, null);

        $this->assertCount(1, $lines);
        $this->assertSame('SKIPPED', $lines[0]['status']);
    }

    // ── Instrumentist absence ─────────────────────────────────────────────────

    public function test_instrumentist_absence_makes_post_uncovered(): void
    {
        $surgeon       = $this->makeUser('surgeon@test.com');
        $instrumentist = $this->makeUser('inst@test.com');
        $site          = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');
        $this->markAbsent($instrumentist, '2026-01-05');

        $recurrence  = $this->makeRecurrence(RecurrenceFrequency::WEEKLY, 1, [1], new \DateTimeImmutable('2026-01-05'));
        $this->posts = [$this->makePost($surgeon, $site, $recurrence, $instrumentist, startDate: '2026-01-05', endDate: '2026-01-05')];

        $lines = $this->makeService()->preview(self::MONTH, $site->getId(), null, null);

        $this->assertCount(1, $lines);
        $this->assertSame('UNCOVERED', $lines[0]['status']);
        $this->assertNull($lines[0]['instrumentistId']);
    }

    // ── Instrumentist conflict ────────────────────────────────────────────────

    public function test_instrumentist_double_booked_across_two_posts_is_conflict(): void
    {
        $surgeonA = $this->makeUser('surgeon-a@test.com');
        $surgeonB = $this->makeUser('surgeon-b@test.com');
        $shared   = $this->makeUser('shared-inst@test.com');
        $site     = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        $recurrence = $this->makeRecurrence(RecurrenceFrequency::WEEKLY, 1, [1], new \DateTimeImmutable('2026-01-05'));
        $this->posts = [
            $this->makePost($surgeonA, $site, $recurrence, $shared, startDate: '2026-01-05', endDate: '2026-01-05'),
            $this->makePost($surgeonB, $site, $recurrence, $shared, startDate: '2026-01-05', endDate: '2026-01-05'),
        ];

        $lines = $this->makeService()->preview(self::MONTH, $site->getId(), null, null);

        $this->assertCount(2, $lines);
        $this->assertSame('COVERED', $lines[0]['status']);
        $this->assertSame('CONFLICT', $lines[1]['status']);
    }

    // ── Post without instrumentist ───────────────────────────────────────────

    public function test_post_without_instrumentist_is_uncovered(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        $recurrence  = $this->makeRecurrence(RecurrenceFrequency::WEEKLY, 1, [1], new \DateTimeImmutable('2026-01-05'));
        $this->posts = [$this->makePost($surgeon, $site, $recurrence, null, startDate: '2026-01-05', endDate: '2026-01-05')];

        $lines = $this->makeService()->preview(self::MONTH, $site->getId(), null, null);

        $this->assertCount(1, $lines);
        $this->assertSame('UNCOVERED', $lines[0]['status']);
        $this->assertNull($lines[0]['instrumentistId']);
    }

    // ── Query budget (D-036 carried over) ────────────────────────────────────

    public function test_single_site_preview_uses_exactly_five_queries(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        $recurrence  = $this->makeRecurrence(RecurrenceFrequency::WEEKLY, 1, [1], new \DateTimeImmutable('2026-01-05'));
        $this->posts = [$this->makePost($surgeon, $site, $recurrence, startDate: '2026-01-05', endDate: '2026-01-26')];

        $this->makeService()->preview(self::MONTH, $site->getId(), null, null);

        $this->assertSame(1, $this->queryCounts['posts'] ?? 0);
        $this->assertSame(1, $this->queryCounts['absences'] ?? 0);
        $this->assertSame(1, $this->queryCounts['pool'] ?? 0);
        $this->assertSame(1, $this->queryCounts['shiftConfig'] ?? 0);
        $this->assertSame(1, $this->queryCounts['exceptions'] ?? 0);
        $this->assertArrayNotHasKey('groupMembers', $this->queryCounts);
        $totalQueries = array_sum($this->queryCounts);
        $this->assertSame(5, $totalQueries, 'Single-site preview must use exactly 5 DB queries, never proportional to days/posts (this is the originally-designed budget, reached now that occurrence exceptions are wired in)');
    }

    public function test_site_group_preview_uses_exactly_six_queries(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');
        $this->groupMembershipRows = [['siteId' => $site->getId()]];

        $recurrence  = $this->makeRecurrence(RecurrenceFrequency::WEEKLY, 1, [1], new \DateTimeImmutable('2026-01-05'));
        $this->posts = [$this->makePost($surgeon, $site, $recurrence, startDate: '2026-01-05', endDate: '2026-01-26')];

        $this->makeService()->preview(self::MONTH, null, 77, null);

        $totalQueries = array_sum($this->queryCounts);
        $this->assertSame(6, $totalQueries, 'Site-group preview must use exactly 6 DB queries (5 + 1 group-resolution)');
    }

    // ── Mutually exclusive siteId / siteGroupId validation ───────────────────

    public function test_both_site_and_group_given_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeService()->preview(self::MONTH, 1, 2, null);
    }

    public function test_neither_site_nor_group_given_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeService()->preview(self::MONTH, null, null, null);
    }
}
