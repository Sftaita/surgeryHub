<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\RecurrenceRule;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\MissionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use App\Service\PlanningGeneratorServiceV2;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Batch 14B: locks down PlanningGeneratorServiceV2's MONTHLY_NTH_WEEKDAY-shaped recurrence
 * (frequency=MONTHLY, weekdays=int[], monthWeeks=int[] 1-5) — Batch 14A only evolved the
 * model and made the minimal generator change; this is the real test matrix proving it.
 *
 * Reference calendar facts used throughout (2026, verified, not assumed):
 *   Jan 1, 2026 = Thursday   Apr 1, 2026 = Wednesday
 *   Feb 1, 2026 = Sunday     May 1, 2026 = Friday
 *   Mar 1, 2026 = Sunday     Jun 1, 2026 = Monday
 * Feb 2026 has 28 days (2026 is not a leap year) — no weekday can have a 5th
 * occurrence that month, which is exactly why Feb is the "no 5th occurrence" case.
 * Mar 2026 has 31 days starting on a Sunday — Mondays fall on 2,9,16,23,30 (5 of them),
 * making it the "5th occurrence exists" case.
 */
class PlanningGeneratorServiceV2MonthlyTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;

    private array $posts                = [];
    private array $absenceRows          = [];
    private array $existingMissions     = [];
    private array $shiftConfigRows      = [];
    private array $groupMembershipRows  = [];
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

    /** Monthly recurrence. Anchor's day-of-month is deliberately NOT the matched weekday's
     *  day, to prove matching no longer derives the weekday from the post's startDate/anchor. */
    private function makeMonthlyRecurrence(
        array $weekdays,
        array $monthWeeks,
        int $interval = 1,
        string $anchorDate = '2026-01-01',
    ): RecurrenceRule {
        $r = new RecurrenceRule();
        $r->setFrequency(RecurrenceFrequency::MONTHLY);
        $r->setInterval($interval);
        $r->setWeekdays($weekdays);
        $r->setMonthWeeks($monthWeeks);
        $r->setAnchorDate(new \DateTimeImmutable($anchorDate));
        return $r;
    }

    private function makePost(
        User $surgeon,
        Hospital $site,
        RecurrenceRule $recurrence,
        ?User $instrumentist = null,
        ShiftPeriod $period = ShiftPeriod::MATIN,
        string $startDate = '2026-01-01',
        ?string $endDate = null,
    ): SurgeonSchedulePost {
        $p = new SurgeonSchedulePost();
        $p->setSurgeon($surgeon);
        $p->setSite($site);
        $p->setType(MissionType::BLOCK);
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

    private function datesOf(array $lines): array
    {
        $dates = array_map(fn (array $l) => $l['date'], $lines);
        sort($dates);
        return $dates;
    }

    /** @return array<string,string[]> month => matched dates, across the 6 requested months */
    private function previewAcrossMonths(PlanningGeneratorServiceV2 $service, Hospital $site, array $months): array
    {
        $result = [];
        foreach ($months as $month) {
            $result[$month] = $this->datesOf($service->preview($month, $site->getId(), null, null));
        }
        return $result;
    }

    private const SIX_MONTHS = ['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'];

    // ── 1. 1st Monday over 6 months ─────────────────────────────────────────

    public function test_first_monday_over_six_months(): void
    {
        $surgeon = $this->makeUser();
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        $recurrence  = $this->makeMonthlyRecurrence([1], [1]);
        $this->posts = [$this->makePost($surgeon, $site, $recurrence)];

        $result = $this->previewAcrossMonths($this->makeService(), $site, self::SIX_MONTHS);

        $this->assertSame([
            '2026-01' => ['2026-01-05'],
            '2026-02' => ['2026-02-02'],
            '2026-03' => ['2026-03-02'],
            '2026-04' => ['2026-04-06'],
            '2026-05' => ['2026-05-04'],
            '2026-06' => ['2026-06-01'],
        ], $result);
    }

    // ── 2. 2nd Tuesday over 6 months ────────────────────────────────────────

    public function test_second_tuesday_over_six_months(): void
    {
        $surgeon = $this->makeUser();
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        $recurrence  = $this->makeMonthlyRecurrence([2], [2]);
        $this->posts = [$this->makePost($surgeon, $site, $recurrence)];

        $result = $this->previewAcrossMonths($this->makeService(), $site, self::SIX_MONTHS);

        $this->assertSame([
            '2026-01' => ['2026-01-13'],
            '2026-02' => ['2026-02-10'],
            '2026-03' => ['2026-03-10'],
            '2026-04' => ['2026-04-14'],
            '2026-05' => ['2026-05-12'],
            '2026-06' => ['2026-06-09'],
        ], $result);
    }

    // ── 3. 2nd and 3rd Thursday over 6 months ───────────────────────────────

    public function test_second_and_third_thursday_over_six_months(): void
    {
        $surgeon = $this->makeUser();
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        $recurrence  = $this->makeMonthlyRecurrence([4], [2, 3]);
        $this->posts = [$this->makePost($surgeon, $site, $recurrence)];

        $result = $this->previewAcrossMonths($this->makeService(), $site, self::SIX_MONTHS);

        $this->assertSame([
            '2026-01' => ['2026-01-08', '2026-01-15'],
            '2026-02' => ['2026-02-12', '2026-02-19'],
            '2026-03' => ['2026-03-12', '2026-03-19'],
            '2026-04' => ['2026-04-09', '2026-04-16'],
            '2026-05' => ['2026-05-14', '2026-05-21'],
            '2026-06' => ['2026-06-11', '2026-06-18'],
        ], $result);
    }

    // ── 4. 1st and 4th Friday over 6 months ─────────────────────────────────

    public function test_first_and_fourth_friday_over_six_months(): void
    {
        $surgeon = $this->makeUser();
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        $recurrence  = $this->makeMonthlyRecurrence([5], [1, 4]);
        $this->posts = [$this->makePost($surgeon, $site, $recurrence)];

        $result = $this->previewAcrossMonths($this->makeService(), $site, self::SIX_MONTHS);

        $this->assertSame([
            '2026-01' => ['2026-01-02', '2026-01-23'],
            '2026-02' => ['2026-02-06', '2026-02-27'],
            '2026-03' => ['2026-03-06', '2026-03-27'],
            '2026-04' => ['2026-04-03', '2026-04-24'],
            '2026-05' => ['2026-05-01', '2026-05-22'],
            '2026-06' => ['2026-06-05', '2026-06-26'],
        ], $result);
    }

    // ── 5. 5th Monday when it exists ────────────────────────────────────────

    public function test_fifth_monday_when_it_exists(): void
    {
        $surgeon = $this->makeUser();
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        $recurrence  = $this->makeMonthlyRecurrence([1], [5]);
        $this->posts = [$this->makePost($surgeon, $site, $recurrence)];

        // March 2026 has 5 Mondays: 2, 9, 16, 23, 30.
        $lines = $this->makeService()->preview('2026-03', $site->getId(), null, null);

        $this->assertSame(['2026-03-30'], $this->datesOf($lines));
    }

    // ── 6. 5th Monday when it does not exist: zero occurrences, no error ───

    public function test_fifth_monday_when_absent_produces_zero_occurrences_not_an_error(): void
    {
        $surgeon = $this->makeUser();
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        $recurrence  = $this->makeMonthlyRecurrence([1], [5]);
        $this->posts = [$this->makePost($surgeon, $site, $recurrence)];

        // January 2026 has only 4 Mondays: 5, 12, 19, 26.
        $lines = $this->makeService()->preview('2026-01', $site->getId(), null, null);

        $this->assertSame([], $lines);
    }

    // ── 7. February (normal, 28 days) ────────────────────────────────────────

    public function test_february_normal_year_caps_at_fourth_occurrence(): void
    {
        $surgeon = $this->makeUser();
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        $recurrence  = $this->makeMonthlyRecurrence([1], [4]);
        $this->posts = [$this->makePost($surgeon, $site, $recurrence)];

        // Feb 2026 (28 days, non-leap): Mondays 2, 9, 16, 23 — 4th = 23, never a 5th.
        $lines = $this->makeService()->preview('2026-02', $site->getId(), null, null);

        $this->assertSame(['2026-02-23'], $this->datesOf($lines));
    }

    // ── 8. February, leap year ───────────────────────────────────────────────

    public function test_leap_year_february_can_produce_a_fifth_occurrence(): void
    {
        $surgeon = $this->makeUser();
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        // Feb 2024 is leap (29 days). Feb 1, 2024 = Thursday, so Thursdays fall on
        // 1, 8, 15, 22, 29 — a 5th Thursday exists only because of the leap day.
        $recurrence  = $this->makeMonthlyRecurrence([4], [5], anchorDate: '2024-01-01');
        $this->posts = [$this->makePost($surgeon, $site, $recurrence, startDate: '2024-01-01')];

        $lines = $this->makeService()->preview('2024-02', $site->getId(), null, null);

        $this->assertSame(['2024-02-29'], $this->datesOf($lines));
    }

    // ── 9. Month starting on a Sunday ───────────────────────────────────────

    public function test_month_starting_on_sunday(): void
    {
        $surgeon = $this->makeUser();
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        // Feb 2026 starts on a Sunday. 1st Monday must be Feb 2 (day 2), not "the
        // first calendar week" (which would be ambiguous/empty for a Sunday-starting month).
        $recurrence  = $this->makeMonthlyRecurrence([1], [1]);
        $this->posts = [$this->makePost($surgeon, $site, $recurrence)];

        $lines = $this->makeService()->preview('2026-02', $site->getId(), null, null);

        $this->assertSame(['2026-02-02'], $this->datesOf($lines));
    }

    // ── 10. Month starting on a Monday ──────────────────────────────────────

    public function test_month_starting_on_monday(): void
    {
        $surgeon = $this->makeUser();
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        // Jun 2026 starts on a Monday — day 1 itself must count as the 1st occurrence.
        $recurrence  = $this->makeMonthlyRecurrence([1], [1]);
        $this->posts = [$this->makePost($surgeon, $site, $recurrence)];

        $lines = $this->makeService()->preview('2026-06', $site->getId(), null, null);

        $this->assertSame(['2026-06-01'], $this->datesOf($lines));
    }

    // ── 11. startDate mid-month excludes an earlier occurrence ─────────────

    public function test_start_date_mid_month_excludes_earlier_occurrence(): void
    {
        $surgeon = $this->makeUser();
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        // All 4 Mondays of Jan 2026 requested (5,12,19,26), but startDate=Jan 10
        // must exclude the 1st Monday (Jan 5), which falls before it.
        $recurrence  = $this->makeMonthlyRecurrence([1], [1, 2, 3, 4]);
        $this->posts = [$this->makePost($surgeon, $site, $recurrence, startDate: '2026-01-10')];

        $lines = $this->makeService()->preview('2026-01', $site->getId(), null, null);

        $this->assertSame(['2026-01-12', '2026-01-19', '2026-01-26'], $this->datesOf($lines));
    }

    // ── 12. endDate mid-month excludes a later occurrence ───────────────────

    public function test_end_date_mid_month_excludes_later_occurrence(): void
    {
        $surgeon = $this->makeUser();
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        $recurrence  = $this->makeMonthlyRecurrence([1], [1, 2, 3, 4]);
        $this->posts = [$this->makePost($surgeon, $site, $recurrence, startDate: '2026-01-01', endDate: '2026-01-20')];

        $lines = $this->makeService()->preview('2026-01', $site->getId(), null, null);

        $this->assertSame(['2026-01-05', '2026-01-12', '2026-01-19'], $this->datesOf($lines));
    }

    // ── 13. Multiple weekdays ────────────────────────────────────────────────

    public function test_multiple_weekdays_each_match_independently(): void
    {
        $surgeon = $this->makeUser();
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        // weekdays=[Monday,Thursday], monthWeeks=[2] -> 2nd Monday AND 2nd Thursday of Jan.
        $recurrence  = $this->makeMonthlyRecurrence([1, 4], [2]);
        $this->posts = [$this->makePost($surgeon, $site, $recurrence)];

        $lines = $this->makeService()->preview('2026-01', $site->getId(), null, null);

        $this->assertSame(['2026-01-08', '2026-01-12'], $this->datesOf($lines));
    }

    // ── 14. Multiple monthWeeks ──────────────────────────────────────────────

    public function test_multiple_month_weeks_each_match_independently(): void
    {
        $surgeon = $this->makeUser();
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        // weekdays=[Friday], monthWeeks=[1,4] -> already covered as the dedicated
        // "1st+4th Friday" scenario; here with a different single month for clarity.
        $recurrence  = $this->makeMonthlyRecurrence([5], [1, 4]);
        $this->posts = [$this->makePost($surgeon, $site, $recurrence)];

        $lines = $this->makeService()->preview('2026-04', $site->getId(), null, null);

        $this->assertSame(['2026-04-03', '2026-04-24'], $this->datesOf($lines));
    }

    // ── Anti-regression: matching must NOT depend on startDate's weekday ───

    public function test_matching_does_not_depend_on_start_date_weekday(): void
    {
        $surgeon = $this->makeUser();
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        // startDate=2026-01-01 is a THURSDAY, but the rule asks for Friday (weekdays=[5]).
        // If the old behaviour (deriving weekday from startDate) were still in effect,
        // isoDay===startDate->format('N') would force a Thursday match and this would
        // produce ZERO Friday occurrences instead of the correct ones.
        $recurrence  = $this->makeMonthlyRecurrence([5], [1, 4], anchorDate: '2026-01-01');
        $this->posts = [$this->makePost($surgeon, $site, $recurrence, startDate: '2026-01-01')];

        $lines = $this->makeService()->preview('2026-01', $site->getId(), null, null);

        $this->assertSame(['2026-01-02', '2026-01-23'], $this->datesOf($lines));
    }

    public function test_weekdays_2_and_3_thursday_does_not_depend_on_start_date(): void
    {
        $surgeon = $this->makeUser();
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        // startDate is a Monday (2026-01-05), rule is Thursday (weekdays=[4]) — proves
        // weekdays=[4]+monthWeeks=[2,3] resolves from the rule's own weekdays array, not
        // from whatever weekday the post happened to be created/started on.
        $recurrence  = $this->makeMonthlyRecurrence([4], [2, 3], anchorDate: '2026-01-01');
        $this->posts = [$this->makePost($surgeon, $site, $recurrence, startDate: '2026-01-05')];

        $lines = $this->makeService()->preview('2026-01', $site->getId(), null, null);

        $this->assertSame(['2026-01-08', '2026-01-15'], $this->datesOf($lines));
    }

    // ── 15. December -> January year boundary, with interval > 1 ───────────

    public function test_interval_two_monthly_carries_correctly_across_year_boundary(): void
    {
        $surgeon = $this->makeUser();
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        // Anchored on November 2026 (month 11), interval=2 -> active months are
        // Nov, Jan(2027), Mar(2027)... Dec 2026 (diff=1) must NOT match; Jan 2027 (diff=2) must.
        $recurrence  = $this->makeMonthlyRecurrence([1], [1], interval: 2, anchorDate: '2026-11-02');
        $this->posts = [$this->makePost($surgeon, $site, $recurrence, startDate: '2026-01-01')];

        $service  = $this->makeService();
        $december = $service->preview('2026-12', $site->getId(), null, null);
        $january  = $service->preview('2027-01', $site->getId(), null, null);

        $this->assertSame([], $december, 'December (odd phase relative to the Nov anchor) must not match');
        // Jan 1, 2027 = Friday -> 1st Monday of Jan 2027 = Jan 4.
        $this->assertSame(['2027-01-04'], $this->datesOf($january));
    }

    // ── 16. Europe/Brussels timezone — date-only arithmetic, DST-transition-safe ─

    public function test_timezone_europe_brussels_does_not_shift_dates_across_dst_change(): void
    {
        $surgeon = $this->makeUser();
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        $tz = new \DateTimeZone('Europe/Brussels');
        // EU DST starts the last Sunday of March (2026-03-29) — squarely inside this month.
        $recurrence = $this->makeMonthlyRecurrence([1], [1, 2, 3, 4, 5]);
        $recurrence->setAnchorDate(new \DateTimeImmutable('2026-01-01', $tz));

        $post = $this->makePost($surgeon, $site, $recurrence, startDate: '2026-01-01');
        $post->setStartDate(new \DateTimeImmutable('2026-01-01', $tz));
        $this->posts = [$post];

        $lines = $this->makeService()->preview('2026-03', $site->getId(), null, null);

        // Mar 2026 Mondays (5 of them, spanning the DST transition): 2,9,16,23,30 — every
        // single one must appear once, with no skip/duplicate around the Mar 29 transition.
        $this->assertSame(['2026-03-02', '2026-03-09', '2026-03-16', '2026-03-23', '2026-03-30'], $this->datesOf($lines));
    }

    // ── 21. Site filtering still works for monthly posts ───────────────────

    public function test_monthly_post_belonging_to_a_different_site_is_excluded(): void
    {
        $surgeon = $this->makeUser();
        $siteA   = $this->makeSite('Alpha');
        $siteB   = $this->makeSite('Beta');
        $this->addShiftConfig($siteA, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        $recurrence  = $this->makeMonthlyRecurrence([1], [1]);
        $this->posts = [$this->makePost($surgeon, $siteA, $recurrence)];

        $lines = $this->makeService()->preview('2026-01', $siteA->getId(), null, null);

        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertSame($siteA->getId(), $line['siteId']);
        }
        $this->assertNotSame($siteB->getId(), $lines[0]['siteId']);
    }

    // ── 22. Site-group filtering still works for monthly posts ─────────────

    public function test_monthly_posts_across_a_site_group_are_all_included(): void
    {
        $surgeon = $this->makeUser();
        $siteA   = $this->makeSite('Alpha');
        $siteC   = $this->makeSite('Charlie');
        $this->addShiftConfig($siteA, ShiftPeriod::MATIN, '08:00:00', '13:00:00');
        $this->addShiftConfig($siteC, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        $this->groupMembershipRows = [['siteId' => $siteA->getId()], ['siteId' => $siteC->getId()]];

        $recurrence = $this->makeMonthlyRecurrence([1], [1]);
        $this->posts = [
            $this->makePost($surgeon, $siteA, $recurrence),
            $this->makePost($surgeon, $siteC, $recurrence),
        ];

        $lines = $this->makeService()->preview('2026-01', null, 99, null);

        $siteIds = array_map(fn (array $l) => $l['siteId'], $lines);
        sort($siteIds);
        $expected = [$siteA->getId(), $siteC->getId()];
        sort($expected);
        $this->assertSame($expected, $siteIds);
    }

    // ── 23. Query budget unchanged for monthly posts ────────────────────────

    public function test_monthly_single_site_preview_uses_exactly_five_queries(): void
    {
        $surgeon = $this->makeUser();
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');

        $recurrence  = $this->makeMonthlyRecurrence([1, 4], [1, 2, 3, 4, 5]);
        $this->posts = [$this->makePost($surgeon, $site, $recurrence)];

        $this->makeService()->preview('2026-01', $site->getId(), null, null);

        $totalQueries = array_sum($this->queryCounts);
        $this->assertSame(5, $totalQueries, 'Monthly recurrence must use the exact same 5-query budget as weekly');
    }

    public function test_monthly_site_group_preview_uses_exactly_six_queries(): void
    {
        $surgeon = $this->makeUser();
        $site    = $this->makeSite();
        $this->addShiftConfig($site, ShiftPeriod::MATIN, '08:00:00', '13:00:00');
        $this->groupMembershipRows = [['siteId' => $site->getId()]];

        $recurrence  = $this->makeMonthlyRecurrence([1, 4], [1, 2, 3, 4, 5]);
        $this->posts = [$this->makePost($surgeon, $site, $recurrence)];

        $this->makeService()->preview('2026-01', null, 77, null);

        $totalQueries = array_sum($this->queryCounts);
        $this->assertSame(6, $totalQueries, 'Monthly recurrence must use the exact same 6-query budget as weekly for a site group');
    }
}
