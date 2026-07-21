<?php

namespace App\Tests\Unit\Entity;

use App\Entity\InterventionTypeRequest;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Entity\User;
use App\Enum\MaterialLineBillingState;
use App\Exception\InvalidDraftResolutionStateException;
use PHPUnit\Framework\TestCase;

/**
 * EPIC Revue instrumentiste, Lot 3 — MissionInterventionDraft en tant que
 * MaterialAttachmentTarget, pour chacun de ses 4 statuts (revue de conception,
 * docs/decisions.md).
 */
final class MissionInterventionDraftTest extends TestCase
{
    private function setId(object $entity, int $id): void
    {
        $rp = new \ReflectionProperty($entity, 'id');
        $rp->setAccessible(true);
        $rp->setValue($entity, $id);
    }

    private function makeMission(): Mission
    {
        $mission = new Mission();
        $this->setId($mission, 1);
        return $mission;
    }

    private function makeUser(): User
    {
        $user = new User();
        $this->setId($user, 99);
        return $user;
    }

    private function makeResolvedIntervention(Mission $mission): MissionIntervention
    {
        $intervention = new MissionIntervention();
        $intervention->setMission($mission)->setCode('GEN01')->setLabel('Test')->setOrderIndex(0);
        $this->setId($intervention, 77);
        return $intervention;
    }

    private function makeDraft(Mission $mission, string $status, ?MissionIntervention $resolved = null): MissionInterventionDraft
    {
        $user = $this->makeUser();

        $request = new InterventionTypeRequest();
        $request->setMission($mission)->setLabel('Prothèse X')->setCreatedBy($user);
        $this->setId($request, 500);

        $draft = new MissionInterventionDraft();
        $draft
            ->setMission($mission)
            ->setInterventionTypeRequest($request)
            ->setLabel('Prothèse X')
            ->setOrderIndex(0)
            ->setStatus($status)
            ->setCreatedBy($user);
        $this->setId($draft, 42);

        if ($resolved !== null) {
            $draft->setResolvedMissionIntervention($resolved);
        }

        return $draft;
    }

    // ── OPEN ─────────────────────────────────────────────────────────

    public function testOpenAcceptsMaterial(): void
    {
        $mission = $this->makeMission();
        self::assertTrue($this->makeDraft($mission, MissionInterventionDraft::STATUS_OPEN)->acceptsNewMaterial());
    }

    public function testOpenHasNoRedirect(): void
    {
        $mission = $this->makeMission();
        self::assertNull($this->makeDraft($mission, MissionInterventionDraft::STATUS_OPEN)->redirectTarget());
    }

    public function testOpenBillingEligibilityIsRequestPending(): void
    {
        $mission = $this->makeMission();
        self::assertSame(
            MaterialLineBillingState::REQUEST_PENDING,
            $this->makeDraft($mission, MissionInterventionDraft::STATUS_OPEN)->billingEligibility(),
        );
    }

    // ── CONVERTED ────────────────────────────────────────────────────

    public function testConvertedDoesNotAcceptMaterial(): void
    {
        $mission = $this->makeMission();
        $resolved = $this->makeResolvedIntervention($mission);
        self::assertFalse($this->makeDraft($mission, MissionInterventionDraft::STATUS_CONVERTED, $resolved)->acceptsNewMaterial());
    }

    public function testConvertedRedirectsToResolvedIntervention(): void
    {
        $mission = $this->makeMission();
        $resolved = $this->makeResolvedIntervention($mission);
        $draft = $this->makeDraft($mission, MissionInterventionDraft::STATUS_CONVERTED, $resolved);
        self::assertSame($resolved, $draft->redirectTarget());
    }

    public function testConvertedBillingEligibilityIsNeverCatalogued(): void
    {
        $mission = $this->makeMission();
        $resolved = $this->makeResolvedIntervention($mission);
        $draft = $this->makeDraft($mission, MissionInterventionDraft::STATUS_CONVERTED, $resolved);
        self::assertSame(MaterialLineBillingState::REQUEST_PENDING, $draft->billingEligibility());
        self::assertNotSame(MaterialLineBillingState::CATALOGUED, $draft->billingEligibility());
    }

    public function testConvertedWithoutResolvedInterventionIsAnInvalidState(): void
    {
        $mission = $this->makeMission();
        $draft = $this->makeDraft($mission, MissionInterventionDraft::STATUS_CONVERTED);
        $this->expectException(InvalidDraftResolutionStateException::class);
        $draft->redirectTarget();
    }

    // ── MATERIAL_REASSIGNED ──────────────────────────────────────────

    public function testMaterialReassignedDoesNotAcceptMaterial(): void
    {
        $mission = $this->makeMission();
        $resolved = $this->makeResolvedIntervention($mission);
        self::assertFalse($this->makeDraft($mission, MissionInterventionDraft::STATUS_MATERIAL_REASSIGNED, $resolved)->acceptsNewMaterial());
    }

    public function testMaterialReassignedRedirectsToTarget(): void
    {
        $mission = $this->makeMission();
        $resolved = $this->makeResolvedIntervention($mission);
        $draft = $this->makeDraft($mission, MissionInterventionDraft::STATUS_MATERIAL_REASSIGNED, $resolved);
        self::assertSame($resolved, $draft->redirectTarget());
    }

    public function testMaterialReassignedBillingEligibilityIsNeverCatalogued(): void
    {
        $mission = $this->makeMission();
        $resolved = $this->makeResolvedIntervention($mission);
        $draft = $this->makeDraft($mission, MissionInterventionDraft::STATUS_MATERIAL_REASSIGNED, $resolved);
        self::assertNotSame(MaterialLineBillingState::CATALOGUED, $draft->billingEligibility());
    }

    public function testMaterialReassignedWithoutResolvedInterventionIsAnInvalidState(): void
    {
        $mission = $this->makeMission();
        $draft = $this->makeDraft($mission, MissionInterventionDraft::STATUS_MATERIAL_REASSIGNED);
        $this->expectException(InvalidDraftResolutionStateException::class);
        $draft->redirectTarget();
    }

    // ── KEPT_AS_HISTORY ──────────────────────────────────────────────

    public function testKeptAsHistoryDoesNotAcceptMaterial(): void
    {
        $mission = $this->makeMission();
        self::assertFalse($this->makeDraft($mission, MissionInterventionDraft::STATUS_KEPT_AS_HISTORY)->acceptsNewMaterial());
    }

    public function testKeptAsHistoryHasNoRedirect(): void
    {
        $mission = $this->makeMission();
        self::assertNull($this->makeDraft($mission, MissionInterventionDraft::STATUS_KEPT_AS_HISTORY)->redirectTarget());
    }

    public function testKeptAsHistoryBillingEligibilityIsHistoryOnly(): void
    {
        $mission = $this->makeMission();
        self::assertSame(
            MaterialLineBillingState::HISTORY_ONLY,
            $this->makeDraft($mission, MissionInterventionDraft::STATUS_KEPT_AS_HISTORY)->billingEligibility(),
        );
    }

    // ── getId()/getMission()/getOrderIndex() ──────────────────────────

    public function testGetIdThrowsBeforePersist(): void
    {
        $draft = new MissionInterventionDraft();
        $this->expectException(\LogicException::class);
        $draft->getId();
    }

    public function testGetMissionThrowsBeforeSet(): void
    {
        $draft = new MissionInterventionDraft();
        $this->expectException(\LogicException::class);
        $draft->getMission();
    }

    public function testGetOrderIndexIsNeverNull(): void
    {
        $draft = new MissionInterventionDraft();
        self::assertSame(0, $draft->getOrderIndex());
    }
}
