<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\PlanningOccurrenceException;
use App\Entity\RecurrenceRule;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\MissionType;
use App\Enum\OccurrenceExceptionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use App\Service\PlanningGeneratorServiceV2;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests that PlanningGeneratorServiceV2 correctly applies PlanningOccurrenceException
 * (cancel/move/change period/change instrumentist) to a single occurrence WITHOUT ever
 * mutating the recurring SurgeonSchedulePost or affecting any other occurrence it produces.
 *
 * January 2026 Mondays: 5, 12, 19, 26 (weekly, every week, weekdays=[1]).
 */
class PlanningGeneratorServiceV2OccurrenceExceptionTest extends TestCase
{
    private const MONTH = '2026-01';

    private EntityManagerInterface&MockObject $em;

    private array $posts               = [];
    private array $absenceRows         = [];
    private array $existingMissions    = [];
    private array $shiftConfigRows     = [];
    private array $occurrenceExceptions = [];

    private static int $idSeq = 0;

    protected function setUp(): void
    {
        $this->em                   = $this->createMock(EntityManagerInterface::class);
        $this->posts                = [];
        $this->absenceRows          = [];
        $this->existingMissions     = [];
        $this->shiftConfigRows      = [];
        $this->occurrenceExceptions = [];

        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql): Query {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();

                if (str_contains($dql, 'postsFrom')) {
                    $q->method('getResult')->willReturnCallback(fn () => $this->posts);
                } elseif (str_contains($dql, 'absencesFrom')) {
                    $q->method('getArrayResult')->willReturnCallback(fn () => $this->absenceRows);
                } elseif (str_contains($dql, 'poolFrom')) {
                    $q->method('getResult')->willReturnCallback(fn () => $this->existingMissions);
                } elseif (str_contains($dql, 'shiftConfigSites')) {
                    $q->method('getArrayResult')->willReturnCallback(fn () => $this->shiftConfigRows);
                } elseif (str_contains($dql, 'exceptionPostIds')) {
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

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Alpha');
        $rp = new \ReflectionProperty(Hospital::class, 'id');
        $rp->setValue($h, ++self::$idSeq);
        return $h;
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

    private function makePost(User $surgeon, Hospital $site, ?User $instrumentist = null): SurgeonSchedulePost
    {
        $r = new RecurrenceRule();
        $r->setFrequency(RecurrenceFrequency::WEEKLY);
        $r->setInterval(1);
        $r->setWeekdays([1]);
        $r->setAnchorDate(new \DateTimeImmutable('2026-01-05'));

        $p = new SurgeonSchedulePost();
        $p->setSurgeon($surgeon);
        $p->setSite($site);
        $p->setType(MissionType::BLOCK);
        $p->setPeriod(ShiftPeriod::MATIN);
        $p->setRecurrence($r);
        $p->setInstrumentist($instrumentist);
        $p->setStartDate(new \DateTimeImmutable('2026-01-01'));
        $p->setCreatedBy($surgeon);
        $rp = new \ReflectionProperty(SurgeonSchedulePost::class, 'id');
        $rp->setValue($p, ++self::$idSeq);
        return $p;
    }

    /** Monthly variant of makePost: 2nd+3rd Thursday of the month, weekdays read from the rule, not from startDate. */
    private function makeMonthlyPost(User $surgeon, Hospital $site, ?User $instrumentist = null): SurgeonSchedulePost
    {
        $r = new RecurrenceRule();
        $r->setFrequency(RecurrenceFrequency::MONTHLY);
        $r->setInterval(1);
        $r->setWeekdays([4]);
        $r->setMonthWeeks([2, 3]);
        $r->setAnchorDate(new \DateTimeImmutable('2026-01-01'));

        $p = new SurgeonSchedulePost();
        $p->setSurgeon($surgeon);
        $p->setSite($site);
        $p->setType(MissionType::BLOCK);
        $p->setPeriod(ShiftPeriod::MATIN);
        $p->setRecurrence($r);
        $p->setInstrumentist($instrumentist);
        $p->setStartDate(new \DateTimeImmutable('2026-01-01'));
        $p->setCreatedBy($surgeon);
        $rp = new \ReflectionProperty(SurgeonSchedulePost::class, 'id');
        $rp->setValue($p, ++self::$idSeq);
        return $p;
    }

    private function makeException(SurgeonSchedulePost $post, string $occurrenceDate, OccurrenceExceptionType $type, User $createdBy): PlanningOccurrenceException
    {
        $e = new PlanningOccurrenceException();
        $e->setPost($post);
        $e->setOccurrenceDate(new \DateTimeImmutable($occurrenceDate));
        $e->setType($type);
        $e->setCreatedBy($createdBy);
        return $e;
    }

    private function addShiftConfig(Hospital $site, string $start, string $end): void
    {
        $this->shiftConfigRows[] = [
            'siteId'    => $site->getId(),
            'period'    => ShiftPeriod::MATIN->value,
            'startTime' => new \DateTimeImmutable($start),
            'endTime'   => new \DateTimeImmutable($end),
        ];
    }

    private function lineOn(array $lines, string $date): ?array
    {
        foreach ($lines as $line) {
            if ($line['date'] === $date) {
                return $line;
            }
        }
        return null;
    }

    // ── Cancel a single occurrence ───────────────────────────────────────────

    public function test_cancelling_one_occurrence_removes_only_that_date(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, '08:00:00', '13:00:00');

        $post        = $this->makePost($surgeon, $site);
        $this->posts = [$post];
        $this->occurrenceExceptions = [
            $this->makeException($post, '2026-01-12', OccurrenceExceptionType::CANCELLED, $surgeon),
        ];

        $lines = $this->makeService()->preview(self::MONTH, $site->getId(), null, null);
        $dates = array_map(fn (array $l) => $l['date'], $lines);

        $this->assertNotContains('2026-01-12', $dates, 'Cancelled occurrence must not appear');
        $this->assertContains('2026-01-05', $dates, 'Other occurrences must remain');
        $this->assertContains('2026-01-19', $dates, 'Other occurrences must remain');
        $this->assertContains('2026-01-26', $dates, 'Other occurrences must remain');
    }

    public function test_cancelling_one_occurrence_never_mutates_the_recurring_post(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, '08:00:00', '13:00:00');

        $post        = $this->makePost($surgeon, $site);
        $this->posts = [$post];
        $this->occurrenceExceptions = [
            $this->makeException($post, '2026-01-12', OccurrenceExceptionType::CANCELLED, $surgeon),
        ];

        $recurrenceBefore = clone $post->getRecurrence();

        $this->makeService()->preview(self::MONTH, $site->getId(), null, null);

        $this->assertEquals($recurrenceBefore->getInterval(), $post->getRecurrence()->getInterval());
        $this->assertEquals($recurrenceBefore->getWeekdays(), $post->getRecurrence()->getWeekdays());
        $this->assertEquals($recurrenceBefore->getAnchorDate(), $post->getRecurrence()->getAnchorDate());
        $this->assertTrue($post->isActive(), 'Post must remain active — a one-off cancellation is not a post-level change');
    }

    // ── Move a single occurrence ──────────────────────────────────────────────

    public function test_moving_one_occurrence_relocates_it_and_suppresses_the_original_date(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, '08:00:00', '13:00:00');

        $post        = $this->makePost($surgeon, $site);
        $this->posts = [$post];

        $moved = $this->makeException($post, '2026-01-12', OccurrenceExceptionType::MOVED, $surgeon);
        $moved->setOverrideDate(new \DateTimeImmutable('2026-01-14')); // Wednesday of the same week
        $this->occurrenceExceptions = [$moved];

        $lines = $this->makeService()->preview(self::MONTH, $site->getId(), null, null);
        $dates = array_map(fn (array $l) => $l['date'], $lines);

        $this->assertNotContains('2026-01-12', $dates, 'Original date must be suppressed');
        $this->assertContains('2026-01-14', $dates, 'Moved-to date must appear');
        $this->assertContains('2026-01-05', $dates);
        $this->assertContains('2026-01-19', $dates);
        $this->assertContains('2026-01-26', $dates);
    }

    public function test_moved_occurrence_can_carry_a_new_time(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, '08:00:00', '13:00:00');

        $post        = $this->makePost($surgeon, $site);
        $this->posts = [$post];

        $moved = $this->makeException($post, '2026-01-12', OccurrenceExceptionType::MOVED, $surgeon);
        $moved->setOverrideDate(new \DateTimeImmutable('2026-01-14'));
        $moved->setOverrideStartTime(new \DateTimeImmutable('14:00:00'));
        $moved->setOverrideEndTime(new \DateTimeImmutable('17:00:00'));
        $this->occurrenceExceptions = [$moved];

        $lines = $this->makeService()->preview(self::MONTH, $site->getId(), null, null);
        $line  = $this->lineOn($lines, '2026-01-14');

        $this->assertNotNull($line);
        $this->assertSame('14:00', $line['startTime']);
        $this->assertSame('17:00', $line['endTime']);
    }

    // ── Change period (hours) of a single occurrence ─────────────────────────

    public function test_changing_period_of_one_occurrence_only_affects_that_date(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, '08:00:00', '13:00:00');

        $post        = $this->makePost($surgeon, $site);
        $this->posts = [$post];

        $override = $this->makeException($post, '2026-01-12', OccurrenceExceptionType::TIME_OVERRIDE, $surgeon);
        $override->setOverrideStartTime(new \DateTimeImmutable('13:00:00'));
        $override->setOverrideEndTime(new \DateTimeImmutable('18:00:00'));
        $this->occurrenceExceptions = [$override];

        $lines = $this->makeService()->preview(self::MONTH, $site->getId(), null, null);

        $changed   = $this->lineOn($lines, '2026-01-12');
        $unchanged = $this->lineOn($lines, '2026-01-05');

        $this->assertSame('13:00', $changed['startTime']);
        $this->assertSame('18:00', $changed['endTime']);
        $this->assertSame('08:00', $unchanged['startTime'], 'Other occurrences must keep the site-default hours');
        $this->assertSame('13:00', $unchanged['endTime']);
    }

    // ── Change instrumentist of a single occurrence ──────────────────────────

    public function test_changing_instrumentist_of_one_occurrence_only_affects_that_date(): void
    {
        $surgeon       = $this->makeUser('surgeon@test.com');
        $defaultInst   = $this->makeUser('default-inst@test.com');
        $oneOffInst    = $this->makeUser('one-off-inst@test.com');
        $site          = $this->makeSite();
        $this->addShiftConfig($site, '08:00:00', '13:00:00');

        $post        = $this->makePost($surgeon, $site, $defaultInst);
        $this->posts = [$post];

        $override = $this->makeException($post, '2026-01-12', OccurrenceExceptionType::INSTRUMENTIST_OVERRIDE, $surgeon);
        $override->setOverrideInstrumentist($oneOffInst);
        $this->occurrenceExceptions = [$override];

        $lines = $this->makeService()->preview(self::MONTH, $site->getId(), null, null);

        $changed   = $this->lineOn($lines, '2026-01-12');
        $unchanged = $this->lineOn($lines, '2026-01-05');

        $this->assertSame($oneOffInst->getId(), $changed['instrumentistId']);
        $this->assertSame($defaultInst->getId(), $unchanged['instrumentistId'], 'Other occurrences must keep the post default instrumentist');
        $this->assertSame($defaultInst, $post->getInstrumentist(), 'The post default instrumentist must remain unchanged');
    }

    // ── Batch 14B: exceptions on a MONTHLY (nth-weekday) occurrence ─────────

    public function test_cancelling_one_monthly_occurrence_removes_only_that_date(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, '08:00:00', '13:00:00');

        // Jan 2026 2nd+3rd Thursday = Jan 8 and Jan 15.
        $post        = $this->makeMonthlyPost($surgeon, $site);
        $this->posts = [$post];
        $this->occurrenceExceptions = [
            $this->makeException($post, '2026-01-08', OccurrenceExceptionType::CANCELLED, $surgeon),
        ];

        $lines = $this->makeService()->preview(self::MONTH, $site->getId(), null, null);
        $dates = array_map(fn (array $l) => $l['date'], $lines);

        $this->assertNotContains('2026-01-08', $dates, 'Cancelled monthly occurrence must not appear');
        $this->assertContains('2026-01-15', $dates, 'The other monthly occurrence must remain');
    }

    public function test_moving_one_monthly_occurrence_relocates_it_and_suppresses_the_original_date(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, '08:00:00', '13:00:00');

        $post        = $this->makeMonthlyPost($surgeon, $site);
        $this->posts = [$post];

        $moved = $this->makeException($post, '2026-01-08', OccurrenceExceptionType::MOVED, $surgeon);
        $moved->setOverrideDate(new \DateTimeImmutable('2026-01-09'));
        $this->occurrenceExceptions = [$moved];

        $lines = $this->makeService()->preview(self::MONTH, $site->getId(), null, null);
        $dates = array_map(fn (array $l) => $l['date'], $lines);

        $this->assertNotContains('2026-01-08', $dates, 'Original monthly occurrence date must be suppressed');
        $this->assertContains('2026-01-09', $dates, 'Moved-to date must appear');
        $this->assertContains('2026-01-15', $dates, 'Other monthly occurrence must remain untouched');
    }
}
