<?php

namespace App\Tests\Unit\Entity;

use App\Entity\InstrumentistRate;
use App\Entity\User;
use App\Enum\InstrumentistRateType;
use PHPUnit\Framework\TestCase;

/** EPIC Exécution & Valorisation, Lot 2 (D-072) — miroir de PricingRuleTest. */
final class InstrumentistRateTest extends TestCase
{
    private function makeRate(string $from, ?string $to): InstrumentistRate
    {
        $rate = new InstrumentistRate();
        $rate->setInstrumentist(new User());
        $rate->setRateType(InstrumentistRateType::HOURLY_RATE);
        $rate->setAmount('45.00');
        $rate->setValidFrom(new \DateTimeImmutable($from));
        $rate->setValidTo($to !== null ? new \DateTimeImmutable($to) : null);
        return $rate;
    }

    public function testCoversDateOpenEnded(): void
    {
        $rate = $this->makeRate('2026-01-01', null);
        self::assertTrue($rate->coversDate(new \DateTimeImmutable('2099-01-01')));
        self::assertFalse($rate->coversDate(new \DateTimeImmutable('2025-12-31')));
    }

    public function testCoversDateValidToIsExclusive(): void
    {
        $rate = $this->makeRate('2026-01-01', '2026-07-01');
        self::assertTrue($rate->coversDate(new \DateTimeImmutable('2026-06-30')));
        self::assertFalse($rate->coversDate(new \DateTimeImmutable('2026-07-01')));
    }

    public function testAdjacentPeriodsTouchingBoundaryDoNotOverlap(): void
    {
        $a = $this->makeRate('2026-01-01', '2026-07-01');
        $b = $this->makeRate('2026-07-01', null);
        self::assertFalse($a->overlapsWith($b));
        self::assertFalse($b->overlapsWith($a));
    }

    public function testOverlappingPeriodsDetected(): void
    {
        $a = $this->makeRate('2026-01-01', null);
        $b = $this->makeRate('2026-06-01', null);
        self::assertTrue($a->overlapsWith($b));
        self::assertTrue($b->overlapsWith($a));
    }
}
