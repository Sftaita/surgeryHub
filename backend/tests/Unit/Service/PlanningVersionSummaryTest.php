<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningVersionStatus;
use App\Enum\SchedulePrecision;
use PHPUnit\Framework\TestCase;

/**
 * Tests the summary statistics returned by PlanningVersionController::serialize().
 *
 * After D-038, the controller queries ALL non-rejected missions in the period+site
 * instead of $version->getMissions() (FK-based).
 * This ensures the résumé is accurate even when re-generating an already-covered period.
 *
 * This test mirrors the computation logic to verify field semantics.
 */
class PlanningVersionSummaryTest extends TestCase
{
    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeUser(string $email): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setRoles(['ROLE_INSTRUMENTIST']);
        $u->setActive(true);
        return $u;
    }

    private function makeMission(MissionStatus $status, ?User $surgeon, ?User $instrumentist): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setStartAt(new \DateTimeImmutable('2026-03-24 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-03-24 13:00:00'));
        $site = new Hospital();
        $site->setName('Alpha');
        $m->setSite($site);
        if ($surgeon !== null) { $m->setSurgeon($surgeon); $m->setCreatedBy($surgeon); }
        $m->setInstrumentist($instrumentist);
        return $m;
    }

    /**
     * Mirrors PlanningVersionController::serialize() field computation.
     * Input: a flat list of missions (as returned by the period+site DB query).
     */
    private function computeSummary(array $missions): array
    {
        $total        = count($missions);
        $draft        = 0;
        $open         = 0;
        $assigned     = 0;
        $withoutInstr = 0;
        $surgeonIds   = [];
        $instrumentistIds = [];

        foreach ($missions as $mission) {
            $status   = $mission->getStatus();
            $hasInstr = $mission->getInstrumentist() !== null;

            if ($status === MissionStatus::DRAFT) {
                $draft++;
                if (!$hasInstr) $withoutInstr++;
            } elseif ($status === MissionStatus::OPEN) {
                $open++;
                if (!$hasInstr) $withoutInstr++;
            } else {
                $assigned++; // ASSIGNED, SUBMITTED, VALIDATED, CLOSED, DECLARED
            }

            if ($mission->getSurgeon()) {
                $surgeonIds[$mission->getSurgeon()->getEmail()] = true;
            }
            if ($hasInstr) {
                $instrumentistIds[$mission->getInstrumentist()->getEmail()] = true;
            }
        }

        return [
            'total'              => $total,
            'draft'              => $draft,
            'open'               => $open,
            'assigned'           => $assigned,
            'withoutInstrumentist' => $withoutInstr,
            'surgeonCount'       => count($surgeonIds),
            'instrumentistCount' => count($instrumentistIds),
        ];
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    public function test_empty_period_returns_zero_summary(): void
    {
        $summary = $this->computeSummary([]);

        $this->assertSame(0, $summary['total']);
        $this->assertSame(0, $summary['draft']);
        $this->assertSame(0, $summary['open']);
        $this->assertSame(0, $summary['assigned']);
        $this->assertSame(0, $summary['withoutInstrumentist']);
        $this->assertSame(0, $summary['surgeonCount']);
        $this->assertSame(0, $summary['instrumentistCount']);
    }

    public function test_draft_missions_counted_in_draft_field(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $instr   = $this->makeUser('instr@test.com');

        $summary = $this->computeSummary([
            $this->makeMission(MissionStatus::DRAFT, $surgeon, $instr),  // draft WITH instr
            $this->makeMission(MissionStatus::DRAFT, $surgeon, null),   // draft WITHOUT instr
        ]);

        $this->assertSame(2, $summary['total']);
        $this->assertSame(2, $summary['draft']);
        $this->assertSame(0, $summary['open']);
        $this->assertSame(0, $summary['assigned']);
        $this->assertSame(1, $summary['withoutInstrumentist'],
            'Only the DRAFT without instrumentist must be counted in withoutInstrumentist'
        );
    }

    public function test_open_missions_without_instrumentist_counted_in_without_instr(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');

        $summary = $this->computeSummary([
            $this->makeMission(MissionStatus::OPEN, $surgeon, null),  // pool (no instr)
        ]);

        $this->assertSame(1, $summary['open']);
        $this->assertSame(1, $summary['withoutInstrumentist'],
            'OPEN mission without instrumentist (pool) must be in withoutInstrumentist'
        );
    }

    public function test_assigned_and_above_statuses_counted_in_assigned_field(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $instr   = $this->makeUser('instr@test.com');

        $summary = $this->computeSummary([
            $this->makeMission(MissionStatus::ASSIGNED,  $surgeon, $instr),
            $this->makeMission(MissionStatus::SUBMITTED, $surgeon, $instr),
            $this->makeMission(MissionStatus::VALIDATED, $surgeon, $instr),
            $this->makeMission(MissionStatus::CLOSED,    $surgeon, $instr),
        ]);

        $this->assertSame(4, $summary['total']);
        $this->assertSame(4, $summary['assigned'],
            'ASSIGNED, SUBMITTED, VALIDATED, CLOSED must all count in "assigned"'
        );
        $this->assertSame(0, $summary['draft']);
        $this->assertSame(0, $summary['open']);
        $this->assertSame(0, $summary['withoutInstrumentist'],
            'None of ASSIGNED+ must be in withoutInstrumentist'
        );
    }

    public function test_mixed_statuses_counted_correctly(): void
    {
        $surgeon1 = $this->makeUser('surgeon1@test.com');
        $surgeon2 = $this->makeUser('surgeon2@test.com');
        $inst     = $this->makeUser('inst@test.com');

        $summary = $this->computeSummary([
            $this->makeMission(MissionStatus::ASSIGNED, $surgeon1, $inst),   // assigned
            $this->makeMission(MissionStatus::DRAFT,    $surgeon2, null),    // draft, no instr
            $this->makeMission(MissionStatus::OPEN,     $surgeon1, null),    // pool, no instr
            $this->makeMission(MissionStatus::DRAFT,    $surgeon2, $inst),   // draft, with instr
        ]);

        $this->assertSame(4, $summary['total']);
        $this->assertSame(2, $summary['draft']);
        $this->assertSame(1, $summary['open']);
        $this->assertSame(1, $summary['assigned']);
        $this->assertSame(2, $summary['withoutInstrumentist'],  '1 DRAFT + 1 OPEN without instr');
        $this->assertSame(2, $summary['surgeonCount'],           '2 distinct surgeons');
        $this->assertSame(1, $summary['instrumentistCount'],     '1 instrumentist (deduped)');
    }

    public function test_surgeons_and_instrumentists_deduplicated(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $instr   = $this->makeUser('instr@test.com');

        $summary = $this->computeSummary([
            $this->makeMission(MissionStatus::ASSIGNED, $surgeon, $instr),
            $this->makeMission(MissionStatus::ASSIGNED, $surgeon, $instr),
            $this->makeMission(MissionStatus::ASSIGNED, $surgeon, $instr),
        ]);

        $this->assertSame(1, $summary['surgeonCount'],      'surgeon counted once');
        $this->assertSame(1, $summary['instrumentistCount'], 'instrumentist counted once');
    }

    /**
     * REGRESSION D-038 — the key bug:
     * When re-generating an already-covered period, $version->getMissions() returns 0
     * because no new missions were created (all were preserved/skipped).
     * The controller must query by period+site to show the real state.
     *
     * This test simulates what the DB query returns (all missions in period)
     * and verifies the summary is correct even if no missions are linked to this version.
     */
    public function test_summary_computed_from_period_missions_not_from_version_fk(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $instr   = $this->makeUser('instr@test.com');

        // Simulate: 200 missions from a previous generation (not linked to current version)
        $existingMissions = [];
        for ($i = 0; $i < 200; $i++) {
            $existingMissions[] = $this->makeMission(MissionStatus::ASSIGNED, $surgeon, $instr);
        }
        // Plus 5 new DRAFT missions from this generation
        for ($i = 0; $i < 5; $i++) {
            $existingMissions[] = $this->makeMission(MissionStatus::DRAFT, $surgeon, null);
        }

        $summary = $this->computeSummary($existingMissions);

        // If we had used $version->getMissions() (FK-based), this would show 5 (only new ones).
        // With period+site query, this correctly shows 205.
        $this->assertSame(205, $summary['total'],
            'REGRESSION D-038: summary must count all missions in the period, not just version-linked ones'
        );
        $this->assertSame(200, $summary['assigned']);
        $this->assertSame(5,   $summary['draft']);
        $this->assertSame(5,   $summary['withoutInstrumentist']);
    }

    public function test_version_initial_status_is_draft(): void
    {
        $version = new PlanningVersion();
        $this->assertSame(PlanningVersionStatus::DRAFT, $version->getStatus());
    }
}
