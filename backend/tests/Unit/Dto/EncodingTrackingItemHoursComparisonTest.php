<?php

namespace App\Tests\Unit\Dto;

use App\Dto\EncodingTrackingItem;
use App\Enum\EffectiveDurationSource;
use App\Enum\EncodingFinancialState;
use App\Enum\EncodingState;
use App\Enum\MissionStatus;
use PHPUnit\Framework\TestCase;

/**
 * D-133 — hours.comparison : base du code couleur du cockpit, calculée côté backend.
 */
final class EncodingTrackingItemHoursComparisonTest extends TestCase
{
    private function item(int $planned, int $effective, EffectiveDurationSource $source): EncodingTrackingItem
    {
        return new EncodingTrackingItem(
            missionId: 1, startAt: null, endAt: null, missionType: null,
            missionStatus: MissionStatus::ASSIGNED, encodingState: EncodingState::IN_PROGRESS,
            instrumentistId: null, instrumentistName: null, instrumentistPhotoPath: null,
            surgeonId: null, surgeonName: null, surgeonPhotoPath: null, siteId: null, siteName: null,
            plannedMinutes: $planned, effectiveMinutes: $effective, effectiveSource: $source,
            interventionCount: 0, encodedInterventionCount: 0, materialLineCount: 0,
            submittedWithoutMaterial: false, hasNoMaterialJustification: false, isStale: false,
            financialState: EncodingFinancialState::NOT_CALCULABLE,
        );
    }

    public function test_planned_fallback_is_never_reported_as_within_plan(): void
    {
        // effectif == planifié par construction du repli : ne doit jamais devenir "vert".
        self::assertSame('NO_REAL_HOURS', $this->item(240, 240, EffectiveDurationSource::PLANNED)->hoursComparison());
    }

    public function test_real_hours_below_or_equal_to_planned_are_within_plan(): void
    {
        self::assertSame('WITHIN_PLAN', $this->item(240, 200, EffectiveDurationSource::ACTUAL_TIMES)->hoursComparison());
        self::assertSame('WITHIN_PLAN', $this->item(240, 240, EffectiveDurationSource::ACTUAL_EXPLICIT)->hoursComparison());
    }

    public function test_real_hours_above_planned_are_over_plan(): void
    {
        self::assertSame('OVER_PLAN', $this->item(240, 241, EffectiveDurationSource::ACTUAL_TIMES)->hoursComparison());
    }
}
