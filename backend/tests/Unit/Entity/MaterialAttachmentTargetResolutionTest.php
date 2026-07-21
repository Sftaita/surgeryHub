<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Firm;
use App\Entity\InterventionTypeRequest;
use App\Entity\MaterialItem;
use App\Entity\MaterialItemRequest;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Entity\User;
use App\Exception\ConflictingAttachmentTargetsException;
use PHPUnit\Framework\TestCase;

/**
 * EPIC Revue instrumentiste, Lot 3 — MaterialLine::attachmentTarget() et
 * MaterialItemRequest::attachmentTarget() sont le seul point d'accès à la cible
 * d'attachement (missionIntervention ou interventionDraft) — voir MaterialAttachmentTarget.
 */
final class MaterialAttachmentTargetResolutionTest extends TestCase
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

    private function makeIntervention(Mission $mission): MissionIntervention
    {
        $intervention = new MissionIntervention();
        $intervention->setMission($mission)->setCode('GEN01')->setLabel('Test')->setOrderIndex(0);
        $this->setId($intervention, 10);
        return $intervention;
    }

    private function makeDraft(Mission $mission): MissionInterventionDraft
    {
        $user = new User();
        $this->setId($user, 99);

        $request = new InterventionTypeRequest();
        $request->setMission($mission)->setLabel('Prothèse X')->setCreatedBy($user);
        $this->setId($request, 500);

        $draft = new MissionInterventionDraft();
        $draft
            ->setMission($mission)
            ->setInterventionTypeRequest($request)
            ->setLabel('Prothèse X')
            ->setOrderIndex(0)
            ->setStatus(MissionInterventionDraft::STATUS_OPEN)
            ->setCreatedBy($user);
        $this->setId($draft, 42);
        return $draft;
    }

    private function makeMaterialItem(): MaterialItem
    {
        $firm = new Firm();
        $this->setId($firm, 5);

        $item = new MaterialItem();
        $item->setFirm($firm)->setLabel('Vis')->setUnit('pièce')->setReferenceCode('V1');
        $this->setId($item, 20);
        return $item;
    }

    private function makeUser(): User
    {
        $user = new User();
        $this->setId($user, 99);
        return $user;
    }

    // ── MaterialLine ─────────────────────────────────────────────────

    public function testMaterialLineAttachmentTargetReturnsRealIntervention(): void
    {
        $mission = $this->makeMission();
        $intervention = $this->makeIntervention($mission);

        $line = new MaterialLine();
        $line->setMission($mission)->setItem($this->makeMaterialItem())->setCreatedBy($this->makeUser());
        $line->setMissionIntervention($intervention);

        self::assertSame($intervention, $line->attachmentTarget());
    }

    public function testMaterialLineAttachmentTargetReturnsDraft(): void
    {
        $mission = $this->makeMission();
        $draft = $this->makeDraft($mission);

        $line = new MaterialLine();
        $line->setMission($mission)->setItem($this->makeMaterialItem())->setCreatedBy($this->makeUser());
        $line->setInterventionDraft($draft);

        self::assertSame($draft, $line->attachmentTarget());
    }

    public function testMaterialLineAttachmentTargetReturnsNullWhenNeitherSet(): void
    {
        $mission = $this->makeMission();

        $line = new MaterialLine();
        $line->setMission($mission)->setItem($this->makeMaterialItem())->setCreatedBy($this->makeUser());

        self::assertNull($line->attachmentTarget());
    }

    public function testMaterialLineAttachmentTargetThrowsWhenBothTargetsSet(): void
    {
        $mission = $this->makeMission();
        $intervention = $this->makeIntervention($mission);
        $draft = $this->makeDraft($mission);

        $line = new MaterialLine();
        $line->setMission($mission)->setItem($this->makeMaterialItem())->setCreatedBy($this->makeUser());
        $line->setMissionIntervention($intervention);
        $line->setInterventionDraft($draft);

        $this->expectException(ConflictingAttachmentTargetsException::class);
        $line->attachmentTarget();
    }

    // ── MaterialItemRequest ──────────────────────────────────────────

    public function testMaterialItemRequestAttachmentTargetReturnsRealIntervention(): void
    {
        $mission = $this->makeMission();
        $intervention = $this->makeIntervention($mission);

        $request = new MaterialItemRequest();
        $request->setMission($mission)->setLabel('Ancre')->setCreatedBy($this->makeUser());
        $request->setMissionIntervention($intervention);

        self::assertSame($intervention, $request->attachmentTarget());
    }

    public function testMaterialItemRequestAttachmentTargetReturnsDraft(): void
    {
        $mission = $this->makeMission();
        $draft = $this->makeDraft($mission);

        $request = new MaterialItemRequest();
        $request->setMission($mission)->setLabel('Ancre')->setCreatedBy($this->makeUser());
        $request->setInterventionDraft($draft);

        self::assertSame($draft, $request->attachmentTarget());
    }

    public function testMaterialItemRequestAttachmentTargetReturnsNullWhenNeitherSet(): void
    {
        $mission = $this->makeMission();

        $request = new MaterialItemRequest();
        $request->setMission($mission)->setLabel('Ancre')->setCreatedBy($this->makeUser());

        self::assertNull($request->attachmentTarget());
    }

    public function testMaterialItemRequestAttachmentTargetThrowsWhenBothTargetsSet(): void
    {
        $mission = $this->makeMission();
        $intervention = $this->makeIntervention($mission);
        $draft = $this->makeDraft($mission);

        $request = new MaterialItemRequest();
        $request->setMission($mission)->setLabel('Ancre')->setCreatedBy($this->makeUser());
        $request->setMissionIntervention($intervention);
        $request->setInterventionDraft($draft);

        $this->expectException(ConflictingAttachmentTargetsException::class);
        $request->attachmentTarget();
    }
}
