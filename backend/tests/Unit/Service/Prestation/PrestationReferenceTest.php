<?php

namespace App\Tests\Unit\Service\Prestation;

use App\Entity\MaterialItemRequest;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Service\Prestation\PrestationReference;
use PHPUnit\Framework\TestCase;

/**
 * Complément D-156 — la mission de référence d'une prestation multi-salles (fonction pure).
 */
final class PrestationReferenceTest extends TestCase
{
    public function test_without_content_the_lowest_id_is_the_reference_whatever_the_order(): void
    {
        $a = $this->mission(12);
        $b = $this->mission(7);
        $c = $this->mission(30);

        self::assertSame(7, PrestationReference::of([$a, $b, $c])->getId());
        self::assertSame([], PrestationReference::otherEncodedMissionIds([$a, $b, $c]));
    }

    public function test_the_only_encoded_mission_is_the_reference_even_with_a_higher_id(): void
    {
        $low = $this->mission(5);
        $high = $this->mission(9);
        $high->getMaterialLines()->add(new MaterialLine());

        self::assertSame(9, PrestationReference::of([$low, $high])->getId());
        self::assertSame([], PrestationReference::otherEncodedMissionIds([$low, $high]));
    }

    public function test_several_encoded_missions_the_lowest_encoded_id_wins_and_the_others_are_listed(): void
    {
        $free = $this->mission(3);
        $b = $this->mission(8);
        $c = $this->mission(6);
        $b->getInterventions()->add(new MissionIntervention());
        $c->getMissionInterventionDrafts()->add(new MissionInterventionDraft());

        self::assertSame(6, PrestationReference::of([$free, $b, $c])->getId());
        self::assertSame([8], PrestationReference::otherEncodedMissionIds([$free, $b, $c]));
    }

    public function test_every_kind_of_content_counts(): void
    {
        $m = $this->mission(1);
        self::assertFalse(PrestationReference::carriesContent($m));
        $m->getMaterialItemRequests()->add(new MaterialItemRequest());
        self::assertTrue(PrestationReference::carriesContent($m));
    }

    private function mission(int $id): Mission
    {
        $m = new Mission();
        (new \ReflectionProperty(Mission::class, 'id'))->setValue($m, $id);

        return $m;
    }
}
