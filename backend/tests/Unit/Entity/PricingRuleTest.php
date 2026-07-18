<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Firm;
use App\Entity\PricingRule;
use App\Enum\PricingRuleType;
use PHPUnit\Framework\TestCase;

final class PricingRuleTest extends TestCase
{
    private function makeRule(?string $from, ?string $to): PricingRule
    {
        $rule = new PricingRule();
        $rule->setFirm(new Firm());
        $rule->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $rule->setUnitPrice('100.00');
        $rule->setValidFrom($from !== null ? new \DateTimeImmutable($from) : null);
        $rule->setValidTo($to !== null ? new \DateTimeImmutable($to) : null);
        return $rule;
    }

    // ── coversDate ──────────────────────────────────────────────────

    public function testCoversDateOpenEndedBothSides(): void
    {
        $rule = $this->makeRule(null, null);
        self::assertTrue($rule->coversDate(new \DateTimeImmutable('2020-01-01')));
        self::assertTrue($rule->coversDate(new \DateTimeImmutable('2099-01-01')));
    }

    public function testCoversDateRespectsValidFromBoundary(): void
    {
        $rule = $this->makeRule('2027-01-01', null);
        self::assertFalse($rule->coversDate(new \DateTimeImmutable('2026-12-31')));
        self::assertTrue($rule->coversDate(new \DateTimeImmutable('2027-01-01')));
    }

    public function testCoversDateRespectsValidToBoundary(): void
    {
        // D-072 : validTo EXCLUSIF — le jour validTo lui-même n'est déjà plus couvert.
        $rule = $this->makeRule(null, '2026-12-31');
        self::assertTrue($rule->coversDate(new \DateTimeImmutable('2026-12-30')));
        self::assertFalse($rule->coversDate(new \DateTimeImmutable('2026-12-31')));
        self::assertFalse($rule->coversDate(new \DateTimeImmutable('2027-01-01')));
    }

    public function testCoversDateExactBoundaries(): void
    {
        // D-072 : validFrom INCLUSIF, validTo EXCLUSIF.
        $rule = $this->makeRule('2026-01-01', '2026-12-31');
        self::assertTrue($rule->coversDate(new \DateTimeImmutable('2026-01-01')));
        self::assertTrue($rule->coversDate(new \DateTimeImmutable('2026-12-30')));
        self::assertFalse($rule->coversDate(new \DateTimeImmutable('2026-12-31')));
        self::assertFalse($rule->coversDate(new \DateTimeImmutable('2025-12-31')));
    }

    // ── overlapsWith ────────────────────────────────────────────────

    public function testNonOverlappingConsecutivePeriods(): void
    {
        $a = $this->makeRule(null, '2026-12-31');
        $b = $this->makeRule('2027-01-01', null);
        self::assertFalse($a->overlapsWith($b));
        self::assertFalse($b->overlapsWith($a));
    }

    public function testOverlappingPeriodsDetected(): void
    {
        $a = $this->makeRule(null, '2026-12-31');
        $b = $this->makeRule('2026-12-15', null);
        self::assertTrue($a->overlapsWith($b));
        self::assertTrue($b->overlapsWith($a));
    }

    public function testTwoFullyOpenPeriodsAlwaysOverlap(): void
    {
        $a = $this->makeRule(null, null);
        $b = $this->makeRule(null, null);
        self::assertTrue($a->overlapsWith($b));
    }

    public function testAdjacentPeriodsTouchingBoundaryDoNotOverlap(): void
    {
        // D-072 : validTo EXCLUSIF — deux périodes qui se touchent exactement à la même
        // date (a.validTo === b.validFrom) ne se chevauchent jamais ; c'est exactement
        // le résultat produit par PricingRuleVersioningService::replaceCurrentRuleFrom().
        $a = $this->makeRule(null, '2026-12-31');
        $b = $this->makeRule('2026-12-31', null);
        self::assertFalse($a->overlapsWith($b));
        self::assertFalse($b->overlapsWith($a));
    }
}
