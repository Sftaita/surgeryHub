<?php

namespace App\Tests\Unit\Command;

use App\Command\PlanningAuditModificationTimezoneShiftsCommand;
use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Entity\AuditEvent;
use App\Entity\Mission;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * D-090 — the forensic audit command is read-only (never persist()/flush()/remove()
 * on $this->em, verified below by never stubbing those methods and letting a
 * MockObject fail the test if they're ever called) and must correctly distinguish a
 * genuine DST-shift signature (60/120 min wall-clock delta) from an unrelated or
 * absent drift.
 */
class PlanningAuditModificationTimezoneShiftsCommandTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private array $queryResult = [];

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->queryResult = [];

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $q = $this->createMock(Query::class);
        $q->method('getResult')->willReturnCallback(fn () => $this->queryResult);
        $qb->method('getQuery')->willReturn($q);
        $this->em->method('createQueryBuilder')->willReturn($qb);
    }

    private function makeMission(string $date, string $startTime): Mission
    {
        $tz = new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE);
        $m = new Mission();
        $m->setType(MissionType::BLOCK);
        $m->setStatus(MissionStatus::ASSIGNED);
        $m->setStartAt(new \DateTimeImmutable("{$date}T{$startTime}:00", $tz));
        $m->setEndAt(new \DateTimeImmutable("{$date}T{$startTime}:00", $tz));
        $rp = new \ReflectionProperty(Mission::class, 'id');
        $rp->setValue($m, 501);
        return $m;
    }

    private function makeEvent(Mission $mission, string $toStartAtAtom, string $createdAt = '2026-07-20 10:00:00'): AuditEvent
    {
        $e = new AuditEvent();
        $e->setEventType(AuditEventType::MISSION_TIME_CHANGED_POST_DEPLOY);
        $e->setMission($mission);
        $e->setPayload([
            'fromStartAt' => '2026-07-15T08:00:00+02:00',
            'toStartAt'   => $toStartAtAtom,
        ]);
        $e->setCreatedAt(new \DateTimeImmutable($createdAt));
        $rp = new \ReflectionProperty(AuditEvent::class, 'id');
        $rp->setValue($e, 9001);
        return $e;
    }

    public function test_never_mutates_anything(): void
    {
        // The mock has no stubbed persist()/flush()/remove() — PHPUnit fails the test
        // if the command ever calls them (strict mock, undefined method call throws).
        $mission = $this->makeMission('2026-07-15', '10:00');
        $this->queryResult = [$this->makeEvent($mission, '2026-07-15T10:00:00+02:00')];

        $tester = new CommandTester(new PlanningAuditModificationTimezoneShiftsCommand($this->em));
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
    }

    public function test_no_events_reports_nothing_to_audit(): void
    {
        $this->queryResult = [];

        $tester = new CommandTester(new PlanningAuditModificationTimezoneShiftsCommand($this->em));
        $tester->execute([]);

        $this->assertStringContainsString('Aucun événement', $tester->getDisplay());
    }

    public function test_matching_wall_clock_is_not_flagged(): void
    {
        // Audited "toStartAt" (10:00, mislabeled +02:00 by the bug — irrelevant, only
        // digits matter) matches the mission's CURRENT stored wall-clock (10:00) exactly.
        $mission = $this->makeMission('2026-07-15', '10:00');
        $this->queryResult = [$this->makeEvent($mission, '2026-07-15T10:00:00+02:00')];

        $tester = new CommandTester(new PlanningAuditModificationTimezoneShiftsCommand($this->em));
        $tester->execute([]);

        $this->assertStringContainsString('Aucun écart détecté', $tester->getDisplay());
    }

    public function test_two_hour_summer_drift_is_flagged_as_suspected(): void
    {
        // Audited digits say 10:00 was intended; the mission is currently stored at
        // 12:00 — exactly the D-089 CEST (+2h) shift signature.
        $mission = $this->makeMission('2026-07-15', '12:00');
        $this->queryResult = [$this->makeEvent($mission, '2026-07-15T10:00:00+02:00')];

        $tester = new CommandTester(new PlanningAuditModificationTimezoneShiftsCommand($this->em));
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode, 'The command reports findings via output/exit 0 — it never fails just because it found something.');
        $this->assertStringContainsString('SUSPECTED_DST_SHIFT', $tester->getDisplay());
        $this->assertStringContainsString('120 min', $tester->getDisplay());
    }

    public function test_one_hour_winter_drift_is_flagged_as_suspected(): void
    {
        $mission = $this->makeMission('2027-01-15', '11:00');
        $this->queryResult = [$this->makeEvent($mission, '2027-01-15T10:00:00+01:00')];

        $tester = new CommandTester(new PlanningAuditModificationTimezoneShiftsCommand($this->em));
        $tester->execute([]);

        $this->assertStringContainsString('SUSPECTED_DST_SHIFT', $tester->getDisplay());
        $this->assertStringContainsString('60 min', $tester->getDisplay());
    }

    public function test_non_dst_shaped_delta_is_reported_but_not_flagged_as_suspected(): void
    {
        // A genuine, unrelated 37-minute drift (e.g. a legitimate later edit) must be
        // reported as a discrepancy for manual review, but never mislabeled as the
        // specific D-089 signature — only exactly 60/120 minutes qualifies as that.
        $mission = $this->makeMission('2026-07-15', '10:37');
        $this->queryResult = [$this->makeEvent($mission, '2026-07-15T10:00:00+02:00')];

        $tester = new CommandTester(new PlanningAuditModificationTimezoneShiftsCommand($this->em));
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringNotContainsString('SUSPECTED_DST_SHIFT', $display);
        $this->assertStringContainsString('37 min', $display);
    }
}
