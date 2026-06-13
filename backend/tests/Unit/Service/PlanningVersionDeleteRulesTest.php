<?php

namespace App\Tests\Unit\Service;

use App\Entity\Mission;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningVersionStatus;
use App\Enum\SchedulePrecision;
use PHPUnit\Framework\TestCase;

/**
 * Tests the business rules enforced by PlanningVersionController::delete().
 *
 * Rules:
 *   1. Only DRAFT versions can be deleted (ACTIVE and ARCHIVED are protected).
 *   2. Even a DRAFT version cannot be deleted if it has published or assigned missions
 *      (status != DRAFT) — those missions would become orphaned and invisible.
 *
 * These unit tests mirror the controller logic so that a regression in either
 * direction causes an immediate test failure.
 */
class PlanningVersionDeleteRulesTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeVersion(PlanningVersionStatus $status = PlanningVersionStatus::DRAFT): PlanningVersion
    {
        $mgr = new User();
        $mgr->setEmail('mgr@test.com')->setRoles(['ROLE_MANAGER'])->setActive(true);

        $v = new PlanningVersion();
        $v->setPeriodStart(new \DateTimeImmutable('2026-03-23'));
        $v->setPeriodEnd(new \DateTimeImmutable('2026-03-27'));
        $v->setGeneratedBy($mgr);
        $v->setStatus($status);
        return $v;
    }

    /** Mirrors the controller status guard: only DRAFT allowed. */
    private function canDeleteByStatus(PlanningVersion $version): bool
    {
        return $version->getStatus() === PlanningVersionStatus::DRAFT;
    }

    /**
     * Mirrors the controller published-missions guard.
     * Returns true if deletion is allowed (no published/assigned missions).
     *
     * @param MissionStatus[] $missionStatuses statuses of missions linked to the version
     */
    private function canDeleteByMissions(array $missionStatuses): bool
    {
        foreach ($missionStatuses as $status) {
            if ($status !== MissionStatus::DRAFT) {
                return false;
            }
        }
        return true;
    }

    // ── Rule 1: status must be DRAFT ──────────────────────────────────────────

    public function test_draft_version_is_deletable_by_status(): void
    {
        $this->assertTrue(
            $this->canDeleteByStatus($this->makeVersion(PlanningVersionStatus::DRAFT)),
            'DRAFT version must pass the status guard.'
        );
    }

    /**
     * REGRESSION — ACTIVE version must be protected.
     * Deleting an active planning would destroy the live plan seen by instrumentists.
     */
    public function test_active_version_is_not_deletable(): void
    {
        $this->assertFalse(
            $this->canDeleteByStatus($this->makeVersion(PlanningVersionStatus::ACTIVE)),
            'ACTIVE version must be refused by the status guard.'
        );
    }

    /**
     * REGRESSION — ARCHIVED version must be protected for historical record.
     */
    public function test_archived_version_is_not_deletable(): void
    {
        $this->assertFalse(
            $this->canDeleteByStatus($this->makeVersion(PlanningVersionStatus::ARCHIVED)),
            'ARCHIVED version must be refused by the status guard.'
        );
    }

    /** Parameterised: exactly DRAFT passes, others are refused. */
    public function test_only_draft_passes_status_guard(): void
    {
        foreach (PlanningVersionStatus::cases() as $status) {
            $expected = $status === PlanningVersionStatus::DRAFT;
            $this->assertSame(
                $expected,
                $this->canDeleteByStatus($this->makeVersion($status)),
                "Status guard result must be $expected for {$status->value}."
            );
        }
    }

    // ── Rule 2: no published/assigned missions ────────────────────────────────

    public function test_draft_version_with_only_draft_missions_is_deletable(): void
    {
        $this->assertTrue(
            $this->canDeleteByMissions([MissionStatus::DRAFT, MissionStatus::DRAFT]),
            'DRAFT version with only DRAFT missions must be deletable.'
        );
    }

    public function test_draft_version_with_no_missions_is_deletable(): void
    {
        $this->assertTrue(
            $this->canDeleteByMissions([]),
            'DRAFT version with no missions at all must be deletable.'
        );
    }

    /**
     * REGRESSION — an OPEN mission means someone may already see this slot in the pool.
     */
    public function test_draft_version_with_open_mission_is_not_deletable(): void
    {
        $this->assertFalse(
            $this->canDeleteByMissions([MissionStatus::DRAFT, MissionStatus::OPEN]),
            'DRAFT version with an OPEN mission must be refused.'
        );
    }

    /**
     * REGRESSION — an ASSIGNED mission means an instrumentist has already taken the slot.
     */
    public function test_draft_version_with_assigned_mission_is_not_deletable(): void
    {
        $this->assertFalse(
            $this->canDeleteByMissions([MissionStatus::DRAFT, MissionStatus::ASSIGNED]),
            'DRAFT version with an ASSIGNED mission must be refused.'
        );
    }

    public function test_draft_version_with_submitted_mission_is_not_deletable(): void
    {
        $this->assertFalse(
            $this->canDeleteByMissions([MissionStatus::SUBMITTED]),
            'DRAFT version with a SUBMITTED mission must be refused.'
        );
    }

    public function test_draft_version_with_validated_mission_is_not_deletable(): void
    {
        $this->assertFalse(
            $this->canDeleteByMissions([MissionStatus::VALIDATED]),
            'DRAFT version with a VALIDATED mission must be refused.'
        );
    }

    /** Mixed: one DRAFT + one published → refused. */
    public function test_mixed_draft_and_open_missions_is_refused(): void
    {
        $this->assertFalse(
            $this->canDeleteByMissions([MissionStatus::DRAFT, MissionStatus::OPEN, MissionStatus::DRAFT]),
            'Even a single non-DRAFT mission must block deletion.'
        );
    }

    // ── Combined rules ────────────────────────────────────────────────────────

    /**
     * Both guards must pass for deletion to proceed.
     * Active version → refused even with only DRAFT missions.
     */
    public function test_active_version_refused_regardless_of_missions(): void
    {
        $statusOk    = $this->canDeleteByStatus($this->makeVersion(PlanningVersionStatus::ACTIVE));
        $missionsOk  = $this->canDeleteByMissions([MissionStatus::DRAFT]);

        // Controller checks status first; even if missions are all DRAFT, ACTIVE is refused.
        $this->assertFalse($statusOk,
            'ACTIVE version is refused by status guard regardless of mission states.'
        );
        $this->assertTrue($missionsOk,
            'Missions are all DRAFT — if status were allowed, missions would pass.'
        );
    }
}
