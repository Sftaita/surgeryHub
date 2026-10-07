<?php

namespace App\Tests\Unit\Service;

use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Service\MissionHoursReminderPolicy;
use PHPUnit\Framework\TestCase;

/**
 * D-136 — le rappel des heures n'est pertinent que si la mission est passée, qu'un
 * instrumentiste est affecté, qu'aucune heure réelle n'existe et qu'il peut encore la saisir.
 */
final class MissionHoursReminderPolicyTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-07 10:00:00', new \DateTimeZone('Europe/Brussels'));
    }

    private function makeMission(
        MissionStatus $status = MissionStatus::ASSIGNED,
        bool $withInstrumentist = true,
        string $endAt = '2026-10-06 17:00:00',
    ): Mission {
        $tz = new \DateTimeZone('Europe/Brussels');
        $m = (new Mission())
            ->setStatus($status)
            ->setStartAt(new \DateTimeImmutable('2026-10-06 08:00:00', $tz))
            ->setEndAt(new \DateTimeImmutable($endAt, $tz));
        if ($withInstrumentist) {
            $m->setInstrumentist((new User())->setEmail('instr@test.local')->setRoles(['ROLE_INSTRUMENTIST']));
        }
        return $m;
    }

    public function test_relevant_for_past_assigned_mission_without_real_hours(): void
    {
        self::assertTrue(MissionHoursReminderPolicy::isReminderRelevant($this->makeMission(), $this->now));
    }

    public function test_relevant_on_every_status_where_the_instrumentist_can_still_edit_hours(): void
    {
        foreach ([MissionStatus::ASSIGNED, MissionStatus::IN_PROGRESS, MissionStatus::ENCODING_IN_PROGRESS, MissionStatus::DECLARED] as $status) {
            self::assertTrue(
                MissionHoursReminderPolicy::isReminderRelevant($this->makeMission($status), $this->now),
                "expected relevant for {$status->value}",
            );
        }
    }

    public function test_not_relevant_once_hours_are_no_longer_editable(): void
    {
        foreach ([MissionStatus::SUBMITTED, MissionStatus::VALIDATED, MissionStatus::CLOSED, MissionStatus::REJECTED, MissionStatus::CANCELLED, MissionStatus::OPEN, MissionStatus::DRAFT] as $status) {
            self::assertFalse(
                MissionHoursReminderPolicy::isReminderRelevant($this->makeMission($status), $this->now),
                "expected not relevant for {$status->value}",
            );
        }
    }

    public function test_not_relevant_without_instrumentist(): void
    {
        self::assertFalse(MissionHoursReminderPolicy::isReminderRelevant($this->makeMission(withInstrumentist: false), $this->now));
    }

    public function test_not_relevant_before_the_mission_has_ended(): void
    {
        self::assertFalse(MissionHoursReminderPolicy::isReminderRelevant($this->makeMission(endAt: '2026-10-07 12:00:00'), $this->now));
    }

    public function test_not_relevant_when_actual_start_and_end_are_recorded(): void
    {
        $mission = $this->makeMission();
        $mission->setExecution((new MissionExecution())
            ->setActualStartAt(new \DateTimeImmutable('2026-10-06 08:10:00'))
            ->setActualEndAt(new \DateTimeImmutable('2026-10-06 16:40:00')));

        self::assertFalse(MissionHoursReminderPolicy::isReminderRelevant($mission, $this->now));
    }

    public function test_not_relevant_when_an_explicit_actual_duration_is_recorded(): void
    {
        $mission = $this->makeMission();
        $mission->setExecution((new MissionExecution())->setActualDurationMinutes(420));

        self::assertFalse(MissionHoursReminderPolicy::isReminderRelevant($mission, $this->now));
    }

    public function test_still_relevant_when_only_a_partial_actual_time_exists(): void
    {
        // Début seul = pas de durée réelle calculable : resolveDuration() retombe sur le planifié.
        $mission = $this->makeMission();
        $mission->setExecution((new MissionExecution())->setActualStartAt(new \DateTimeImmutable('2026-10-06 08:10:00')));

        self::assertTrue(MissionHoursReminderPolicy::isReminderRelevant($mission, $this->now));
    }

    public function test_not_relevant_once_encoding_is_locked_or_invoiced(): void
    {
        $locked = $this->makeMission()->setEncodingLockedAt(new \DateTimeImmutable('2026-10-06 18:00:00'));
        self::assertFalse(MissionHoursReminderPolicy::isReminderRelevant($locked, $this->now));

        $invoiced = $this->makeMission()->setInvoiceGeneratedAt(new \DateTimeImmutable('2026-10-06 18:00:00'));
        self::assertFalse(MissionHoursReminderPolicy::isReminderRelevant($invoiced, $this->now));
    }
}
