<?php

namespace App\Tests\Unit\Service;

use App\Entity\FinancialCalculation;
use App\Entity\Mission;
use App\Entity\MissionAssignment;
use App\Entity\User;
use App\Enum\FinancialCalculationStatus;
use App\Enum\MissionAssignmentEndReason;
use App\Enum\MissionAssignmentSource;
use App\Enum\MissionStatus;
use App\Exception\AssignmentRefusalException;
use App\Service\MissionAssignmentRefusalPolicy;
use PHPUnit\Framework\TestCase;

/**
 * D-151 — the single rule deciding whether a surgeon may refuse the current affectation now:
 * voluntary acceptance only, until min(assignedAt + 24 h, start), never less than 12 h before
 * the start, never once encoding or financial data exists.
 */
final class MissionAssignmentRefusalPolicyTest extends TestCase
{
    private \DateTimeImmutable $now;
    private User $instrumentist;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-14 10:00:00', new \DateTimeZone('UTC'));
        $this->instrumentist = new User();
        (new \ReflectionProperty($this->instrumentist, 'id'))->setValue($this->instrumentist, 7);
    }

    private function mission(string $startsIn = '+5 days', MissionStatus $status = MissionStatus::ASSIGNED): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setInstrumentist($this->instrumentist);
        $m->setStartAt($this->now->modify($startsIn));
        $m->setEndAt($this->now->modify($startsIn)->modify('+4 hours'));

        return $m;
    }

    private function assignment(Mission $m, MissionAssignmentSource $source = MissionAssignmentSource::POOL_CLAIM, string $ago = '-1 hour'): MissionAssignment
    {
        return new MissionAssignment($m, $this->instrumentist, $source, $this->instrumentist, $this->now->modify($ago));
    }

    public function test_a_fresh_voluntary_acceptance_is_refusable_until_24_hours_after_it(): void
    {
        $m = $this->mission();
        $a = $this->assignment($m);

        self::assertNull(MissionAssignmentRefusalPolicy::blockedReason($m, $a, $this->now));
        self::assertEquals($this->now->modify('-1 hour')->modify('+24 hours'), MissionAssignmentRefusalPolicy::deadline($a, $m));
        self::assertNull(MissionAssignmentRefusalPolicy::blockedReason($m, $this->assignment($m, MissionAssignmentSource::OFFER_ACCEPTED), $this->now));
    }

    public function test_the_window_ends_at_the_mission_start_when_it_comes_first(): void
    {
        $m = $this->mission('+20 hours');
        $a = $this->assignment($m);

        self::assertEquals($m->getStartAt(), MissionAssignmentRefusalPolicy::deadline($a, $m));
    }

    public function test_after_24_hours_the_refusal_is_over(): void
    {
        $m = $this->mission();

        self::assertSame(AssignmentRefusalException::WINDOW_EXPIRED,
            MissionAssignmentRefusalPolicy::blockedReason($m, $this->assignment($m, ago: '-25 hours'), $this->now));
    }

    public function test_a_started_mission_is_past_the_deadline(): void
    {
        $m = $this->mission('-1 hour', MissionStatus::IN_PROGRESS);

        self::assertSame(AssignmentRefusalException::WINDOW_EXPIRED,
            MissionAssignmentRefusalPolicy::blockedReason($m, $this->assignment($m, ago: '-2 hours'), $this->now));
    }

    public function test_less_than_12_hours_before_the_start_goes_through_the_manager(): void
    {
        $m = $this->mission('+11 hours');

        self::assertSame(AssignmentRefusalException::REQUIRES_MANAGER,
            MissionAssignmentRefusalPolicy::blockedReason($m, $this->assignment($m), $this->now));
        self::assertNull(MissionAssignmentRefusalPolicy::blockedReason($this->mission('+13 hours'), $this->assignment($this->mission('+13 hours')), $this->now));
    }

    public function test_a_manager_or_planning_affectation_is_never_refusable(): void
    {
        foreach ([MissionAssignmentSource::DIRECT_ASSIGNMENT, MissionAssignmentSource::MANAGER_ASSIGNMENT,
                  MissionAssignmentSource::PLANNING_DEPLOYMENT, MissionAssignmentSource::LEGACY] as $source) {
            $m = $this->mission();
            self::assertSame(AssignmentRefusalException::NOT_REFUSABLE,
                MissionAssignmentRefusalPolicy::blockedReason($m, $this->assignment($m, $source), $this->now), $source->value);
        }
    }

    public function test_encoding_or_financial_data_sends_the_refusal_to_the_manager(): void
    {
        $m = $this->mission();
        $m->setEncodingStartedAt($this->now);
        self::assertSame(AssignmentRefusalException::REQUIRES_MANAGER,
            MissionAssignmentRefusalPolicy::blockedReason($m, $this->assignment($m), $this->now));

        $m = $this->mission();
        $calculation = (new FinancialCalculation())->setStatus(FinancialCalculationStatus::CALCULATED);
        $m->getFinancialCalculations()->add($calculation);
        self::assertSame(AssignmentRefusalException::REQUIRES_MANAGER,
            MissionAssignmentRefusalPolicy::blockedReason($m, $this->assignment($m), $this->now));

        $calculation->setStatus(FinancialCalculationStatus::SUPERSEDED);
        self::assertNull(MissionAssignmentRefusalPolicy::blockedReason($m, $this->assignment($m), $this->now), 'a superseded calculation no longer counts');
    }

    public function test_an_ended_or_missing_affectation_cannot_be_refused(): void
    {
        $m = $this->mission();
        $a = $this->assignment($m);
        $a->end(MissionAssignmentEndReason::RELEASED, null, $this->now);

        self::assertSame(AssignmentRefusalException::ASSIGNMENT_ALREADY_ENDED, MissionAssignmentRefusalPolicy::blockedReason($m, $a, $this->now));
        self::assertSame(AssignmentRefusalException::ASSIGNMENT_ALREADY_ENDED, MissionAssignmentRefusalPolicy::blockedReason($m, null, $this->now));
    }
}
