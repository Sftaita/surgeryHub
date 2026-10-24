<?php

namespace App\Tests\Unit\Service;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Service\EncodingReminderPolicy;
use App\Service\EncodingReminderService;
use App\Service\MissionHoursReminderPolicy;
use PHPUnit\Framework\TestCase;

/**
 * D-166 — aucune relance d'encodage (manuelle, automatique, rappel des heures) sans
 * instrumentiste affectée ; une affectation ultérieure rend la mission de nouveau éligible.
 */
final class EncodingReminderPolicyTest extends TestCase
{
    private function mission(MissionStatus $status, bool $withInstrumentist): Mission
    {
        $tz = new \DateTimeZone('Europe/Brussels');
        $m = (new Mission())
            ->setStatus($status)
            ->setStartAt(new \DateTimeImmutable('2026-10-06 08:00:00', $tz))
            ->setEndAt(new \DateTimeImmutable('2026-10-06 17:00:00', $tz));
        if ($withInstrumentist) {
            $m->setInstrumentist((new User())->setEmail('instr@test.local')->setRoles(['ROLE_INSTRUMENTIST']));
        }

        return $m;
    }

    public function test_no_reminder_path_is_open_without_an_assigned_instrumentist(): void
    {
        $now = new \DateTimeImmutable('2026-10-07 10:00:00', new \DateTimeZone('Europe/Brussels'));
        foreach (EncodingReminderPolicy::REMINDABLE_STATUSES as $status) {
            $mission = $this->mission($status, false);
            self::assertFalse(EncodingReminderPolicy::hasReminderRecipient($mission));
            self::assertFalse(EncodingReminderPolicy::canSendManualReminder($mission), $status->value);
            self::assertFalse(EncodingReminderService::isEncodingPending($mission), $status->value);
            self::assertFalse(MissionHoursReminderPolicy::isReminderRelevant($mission, $now), $status->value);
        }
    }

    public function test_assigned_instrumentist_with_pending_encoding_can_be_reminded(): void
    {
        foreach (EncodingReminderPolicy::REMINDABLE_STATUSES as $status) {
            $mission = $this->mission($status, true);
            self::assertTrue(EncodingReminderPolicy::canSendManualReminder($mission), $status->value);
            self::assertTrue(EncodingReminderService::isEncodingPending($mission), $status->value);
        }
    }

    public function test_status_outside_the_encoding_window_is_never_reminded(): void
    {
        foreach ([MissionStatus::DRAFT, MissionStatus::OPEN, MissionStatus::SUBMITTED, MissionStatus::VALIDATED, MissionStatus::REJECTED] as $status) {
            self::assertFalse(EncodingReminderPolicy::canSendManualReminder($this->mission($status, true)), $status->value);
        }
    }

    public function test_later_assignment_makes_the_mission_eligible_again(): void
    {
        $mission = $this->mission(MissionStatus::IN_PROGRESS, false);
        self::assertFalse(EncodingReminderPolicy::canSendManualReminder($mission));

        $mission->setInstrumentist((new User())->setEmail('late@test.local')->setRoles(['ROLE_INSTRUMENTIST']));
        self::assertTrue(EncodingReminderPolicy::canSendManualReminder($mission));

        $mission->setInstrumentist(null);
        self::assertFalse(EncodingReminderPolicy::canSendManualReminder($mission), 'retirée à nouveau : plus de relance');
    }
}
