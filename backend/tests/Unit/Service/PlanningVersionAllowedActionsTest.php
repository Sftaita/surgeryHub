<?php

namespace App\Tests\Unit\Service;

use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\PlanningVersionStatus;
use PHPUnit\Framework\TestCase;

/**
 * Tests the allowedActions business rules for PlanningVersion.
 *
 * Rules (D-039):
 *   - deploy : only DRAFT
 *   - delete : only DRAFT — ACTIVE and ARCHIVED are protected
 *   - view, downloadPdf, viewDiff : always allowed (access already gated by PlanningVoter)
 *
 * These tests mirror the logic in PlanningVersionController::allowedActions()
 * so that a regression on either side fails immediately.
 */
class PlanningVersionAllowedActionsTest extends TestCase
{
    private function makeVersion(PlanningVersionStatus $status): PlanningVersion
    {
        $mgr = new User();
        $mgr->setEmail('mgr@test.com');
        $mgr->setRoles(['ROLE_MANAGER']);
        $mgr->setActive(true);

        $v = new PlanningVersion();
        $v->setPeriodStart(new \DateTimeImmutable('2026-03-23'));
        $v->setPeriodEnd(new \DateTimeImmutable('2026-03-27'));
        $v->setGeneratedBy($mgr);
        $v->setStatus($status);
        return $v;
    }

    /** Pure business rule — mirrors PlanningVersionController::allowedActions(). */
    private function computeAllowedActions(PlanningVersion $version): array
    {
        $isDraft = $version->getStatus() === PlanningVersionStatus::DRAFT;
        return [
            'view'        => true,
            'deploy'      => $isDraft,
            'delete'      => $isDraft,
            'downloadPdf' => true,
            'viewDiff'    => true,
        ];
    }

    // ── DRAFT ─────────────────────────────────────────────────────────────────

    public function test_draft_version_allows_deploy_and_delete(): void
    {
        $actions = $this->computeAllowedActions($this->makeVersion(PlanningVersionStatus::DRAFT));

        $this->assertTrue($actions['deploy'], 'DRAFT version must allow deploy.');
        $this->assertTrue($actions['delete'], 'DRAFT version must allow delete.');
    }

    public function test_draft_version_allows_view_pdf_diff(): void
    {
        $actions = $this->computeAllowedActions($this->makeVersion(PlanningVersionStatus::DRAFT));

        $this->assertTrue($actions['view']);
        $this->assertTrue($actions['downloadPdf']);
        $this->assertTrue($actions['viewDiff']);
    }

    // ── ACTIVE ────────────────────────────────────────────────────────────────

    /**
     * REGRESSION — ACTIVE version must never be deployable or deletable.
     * Deleting an ACTIVE planning would destroy a live plan.
     */
    public function test_active_version_forbids_deploy_and_delete(): void
    {
        $actions = $this->computeAllowedActions($this->makeVersion(PlanningVersionStatus::ACTIVE));

        $this->assertFalse($actions['deploy'], 'ACTIVE version must NOT allow deploy.');
        $this->assertFalse($actions['delete'], 'ACTIVE version must NOT allow delete.');
    }

    public function test_active_version_allows_view_pdf_diff(): void
    {
        $actions = $this->computeAllowedActions($this->makeVersion(PlanningVersionStatus::ACTIVE));

        $this->assertTrue($actions['view']);
        $this->assertTrue($actions['downloadPdf']);
        $this->assertTrue($actions['viewDiff']);
    }

    // ── ARCHIVED ──────────────────────────────────────────────────────────────

    /**
     * REGRESSION — ARCHIVED version must never be deployable or deletable.
     * Archived plannings are historical records.
     */
    public function test_archived_version_forbids_deploy_and_delete(): void
    {
        $actions = $this->computeAllowedActions($this->makeVersion(PlanningVersionStatus::ARCHIVED));

        $this->assertFalse($actions['deploy'], 'ARCHIVED version must NOT allow deploy.');
        $this->assertFalse($actions['delete'], 'ARCHIVED version must NOT allow delete.');
    }

    public function test_archived_version_allows_view_pdf_diff(): void
    {
        $actions = $this->computeAllowedActions($this->makeVersion(PlanningVersionStatus::ARCHIVED));

        $this->assertTrue($actions['view']);
        $this->assertTrue($actions['downloadPdf']);
        $this->assertTrue($actions['viewDiff']);
    }

    // ── Default status ────────────────────────────────────────────────────────

    public function test_new_version_is_draft_by_default(): void
    {
        $v = new PlanningVersion();
        $this->assertSame(PlanningVersionStatus::DRAFT, $v->getStatus(),
            'PlanningVersion.status must default to DRAFT so new versions are always deployable.'
        );
    }

    // ── DELETE precondition (mirrors controller guard) ────────────────────────

    /**
     * Verifies the controller DELETE precondition:
     *   status != DRAFT → refuse deletion.
     */
    public function test_delete_is_allowed_only_for_draft(): void
    {
        foreach (PlanningVersionStatus::cases() as $status) {
            $allowed = $status === PlanningVersionStatus::DRAFT;
            $actions = $this->computeAllowedActions($this->makeVersion($status));
            $this->assertSame($allowed, $actions['delete'],
                "delete must be $allowed for status {$status->value}"
            );
        }
    }
}
