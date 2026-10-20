<?php

namespace App\Tests\Unit\Service;

use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\MissionIntervention;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Service\AbsenceMissionProtectionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** D-158 — when may a surgeon absence cancel a published mission automatically? */
final class AbsenceMissionProtectionPolicyTest extends TestCase
{
    private const NOW = '2026-11-10 09:00:00';

    private function mission(MissionStatus $status, string $startAt = '2026-11-12 08:00:00'): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setStartAt(new \DateTimeImmutable($startAt));
        $m->setEndAt((new \DateTimeImmutable($startAt))->modify('+5 hours'));

        return $m;
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    public function test_future_open_or_assigned_mission_without_activity_is_auto_cancellable(): void
    {
        foreach ([MissionStatus::OPEN, MissionStatus::ASSIGNED] as $status) {
            $m = $this->mission($status);
            self::assertNull(AbsenceMissionProtectionPolicy::blockedReason($m, self::now()));
            self::assertTrue(AbsenceMissionProtectionPolicy::canAutoCancel($m, self::now()));
        }
    }

    public function test_started_or_past_mission_is_never_auto_cancelled(): void
    {
        foreach (['2026-11-10 08:00:00', '2026-11-10 09:00:00', '2026-10-01 08:00:00'] as $startAt) {
            $m = $this->mission(MissionStatus::ASSIGNED, $startAt);
            self::assertSame(AbsenceMissionProtectionPolicy::BLOCKED_MISSION_STARTED, AbsenceMissionProtectionPolicy::blockedReason($m, self::now()));
            self::assertFalse(AbsenceMissionProtectionPolicy::canAutoCancel($m, self::now()));
        }
    }

    public function test_intervention_encoded_blocks_auto_cancellation(): void
    {
        $m = $this->mission(MissionStatus::ASSIGNED);
        $m->getInterventions()->add((new MissionIntervention())->setMission($m)->setCode('X')->setLabel('X'));

        self::assertSame(AbsenceMissionProtectionPolicy::BLOCKED_ACTIVITY_ENCODED, AbsenceMissionProtectionPolicy::blockedReason($m, self::now()));
    }

    public function test_material_line_encoded_blocks_auto_cancellation(): void
    {
        $m = $this->mission(MissionStatus::ASSIGNED);
        $m->getMaterialLines()->add(new MaterialLine());

        self::assertSame(AbsenceMissionProtectionPolicy::BLOCKED_ACTIVITY_ENCODED, AbsenceMissionProtectionPolicy::blockedReason($m, self::now()));
    }

    public function test_actual_hours_encoded_block_auto_cancellation(): void
    {
        $m = $this->mission(MissionStatus::ASSIGNED);
        $m->setExecution((new MissionExecution())->setActualStartAt(new \DateTimeImmutable('2026-11-12 08:10:00')));

        self::assertSame(AbsenceMissionProtectionPolicy::BLOCKED_ACTIVITY_ENCODED, AbsenceMissionProtectionPolicy::blockedReason($m, self::now()));
    }

    public function test_execution_row_without_actual_hours_does_not_block(): void
    {
        $m = $this->mission(MissionStatus::ASSIGNED);
        $m->setExecution(new MissionExecution());

        self::assertTrue(AbsenceMissionProtectionPolicy::canAutoCancel($m, self::now()));
    }

    public function test_activity_declared_without_instrumentist_blocks_auto_cancellation(): void
    {
        $m = $this->mission(MissionStatus::OPEN);
        $m->setExecutedWithoutInstrumentist(new \DateTimeImmutable('2026-11-09 18:00:00'), new User());

        self::assertSame(AbsenceMissionProtectionPolicy::BLOCKED_ACTIVITY_ENCODED, AbsenceMissionProtectionPolicy::blockedReason($m, self::now()));
    }

    /** @return iterable<string, array{MissionStatus}> */
    public static function outOfScopeStatuses(): iterable
    {
        foreach ([MissionStatus::DRAFT, MissionStatus::SUBMITTED, MissionStatus::VALIDATED, MissionStatus::IN_PROGRESS, MissionStatus::DECLARED, MissionStatus::CANCELLED, MissionStatus::CLOSED, MissionStatus::REJECTED] as $s) {
            yield $s->value => [$s];
        }
    }

    #[DataProvider('outOfScopeStatuses')]
    public function test_other_statuses_are_never_auto_cancelled(MissionStatus $status): void
    {
        $m = $this->mission($status);

        self::assertNull(AbsenceMissionProtectionPolicy::blockedReason($m, self::now()), 'Out of scope: no blocked reason either');
        self::assertFalse(AbsenceMissionProtectionPolicy::canAutoCancel($m, self::now()));
        self::assertFalse(AbsenceMissionProtectionPolicy::canAutoRelease($m, self::now()));
    }

    public function test_instrumentist_absence_releases_only_a_future_assigned_mission_without_activity(): void
    {
        self::assertTrue(AbsenceMissionProtectionPolicy::canAutoRelease($this->mission(MissionStatus::ASSIGNED), self::now()));
        self::assertFalse(AbsenceMissionProtectionPolicy::canAutoRelease($this->mission(MissionStatus::OPEN), self::now()), 'OPEN: nobody to release');
        self::assertFalse(AbsenceMissionProtectionPolicy::canAutoRelease($this->mission(MissionStatus::ASSIGNED, '2026-11-10 08:00:00'), self::now()), 'Already started');

        $encoded = $this->mission(MissionStatus::ASSIGNED);
        $encoded->getInterventions()->add((new MissionIntervention())->setMission($encoded)->setCode('X')->setLabel('X'));
        self::assertFalse(AbsenceMissionProtectionPolicy::canAutoRelease($encoded, self::now()), 'Encoded');
    }
}
