<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Enum\MaterialLineBillingState;
use PHPUnit\Framework\TestCase;

/**
 * EPIC Revue instrumentiste, Lot 3 — MissionIntervention en tant que
 * MaterialAttachmentTarget : la cible "réelle", toujours ouverte, jamais de
 * redirection, toujours CATALOGUED (voir MissionInterventionDraftTest pour l'autre
 * implémentation).
 */
final class MissionInterventionTest extends TestCase
{
    private function setId(object $entity, int $id): void
    {
        $rp = new \ReflectionProperty($entity, 'id');
        $rp->setAccessible(true);
        $rp->setValue($entity, $id);
    }

    private function makeIntervention(): MissionIntervention
    {
        $mission = new Mission();
        $this->setId($mission, 1);

        $intervention = new MissionIntervention();
        $intervention->setMission($mission)->setCode('GEN01')->setLabel('Test')->setOrderIndex(0);
        $this->setId($intervention, 10);

        return $intervention;
    }

    public function testAlwaysAcceptsNewMaterial(): void
    {
        self::assertTrue($this->makeIntervention()->acceptsNewMaterial());
    }

    public function testNeverRedirects(): void
    {
        self::assertNull($this->makeIntervention()->redirectTarget());
    }

    public function testBillingEligibilityIsAlwaysCatalogued(): void
    {
        self::assertSame(MaterialLineBillingState::CATALOGUED, $this->makeIntervention()->billingEligibility());
    }

    public function testGetIdThrowsBeforePersist(): void
    {
        $intervention = new MissionIntervention();
        $this->expectException(\LogicException::class);
        $intervention->getId();
    }

    public function testGetMissionThrowsBeforeSet(): void
    {
        $intervention = new MissionIntervention();
        $this->expectException(\LogicException::class);
        $intervention->getMission();
    }

    public function testGetOrderIndexIsNeverNull(): void
    {
        $intervention = new MissionIntervention();
        self::assertSame(0, $intervention->getOrderIndex());
    }
}
