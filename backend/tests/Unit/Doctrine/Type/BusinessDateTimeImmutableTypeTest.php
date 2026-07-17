<?php

namespace App\Tests\Unit\Doctrine\Type;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Types\ConversionException;
use PHPUnit\Framework\TestCase;

/**
 * D-066 — see BusinessDateTimeImmutableType's own docblock for the full story. These
 * are pure unit tests against the Type class's two conversion methods directly (no DB),
 * with a real MySQL80Platform (its getDateTimeFormatString() is 'Y-m-d H:i:s', which is
 * all that matters here — no DB connection needed to construct it).
 */
final class BusinessDateTimeImmutableTypeTest extends TestCase
{
    private BusinessDateTimeImmutableType $type;
    private AbstractPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new BusinessDateTimeImmutableType();
        $this->platform = new MySQL80Platform();
    }

    // ── convertToPHPValue() — reading from the DB ────────────────────────────────

    public function test_read_summer_value_gets_the_true_dst_offset(): void
    {
        $result = $this->type->convertToPHPValue('2026-07-15 10:11:00', $this->platform);

        self::assertSame('2026-07-15T10:11:00+02:00', $result->format(\DateTimeInterface::ATOM));
    }

    public function test_read_winter_value_gets_the_true_standard_offset(): void
    {
        $result = $this->type->convertToPHPValue('2026-01-15 10:11:00', $this->platform);

        self::assertSame('2026-01-15T10:11:00+01:00', $result->format(\DateTimeInterface::ATOM));
    }

    public function test_read_never_shifts_the_wall_clock_digits(): void
    {
        $result = $this->type->convertToPHPValue('2026-07-15 10:11:00', $this->platform);

        // The point of the whole fix: the hour/minute reading itself must never change,
        // only its timezone label. A regression to the built-in type (UTC mislabeling)
        // wouldn't fail THIS assertion (10:11 stays 10:11 either way) — it's the ATOM
        // offset assertions above that catch that regression.
        self::assertSame('2026-07-15 10:11:00', $result->format('Y-m-d H:i:s'));
    }

    public function test_read_null_passes_through(): void
    {
        self::assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    public function test_read_throws_on_unparsable_value(): void
    {
        $this->expectException(ConversionException::class);

        $this->type->convertToPHPValue('not-a-date', $this->platform);
    }

    // ── convertToDatabaseValue() — writing to the DB ─────────────────────────────

    public function test_write_a_value_already_expressed_in_brussels_dst_offset(): void
    {
        $input = new \DateTimeImmutable('2026-07-15T10:11:00+02:00');

        $stored = $this->type->convertToDatabaseValue($input, $this->platform);

        self::assertSame('2026-07-15 10:11:00', $stored);
    }

    public function test_write_a_value_expressed_in_utc_representing_the_same_real_instant(): void
    {
        // 08:11 UTC and 10:11+02:00 (Brussels DST) are the SAME real-world instant.
        $input = new \DateTimeImmutable('2026-07-15T08:11:00+00:00');

        $stored = $this->type->convertToDatabaseValue($input, $this->platform);

        self::assertSame(
            '2026-07-15 10:11:00',
            $stored,
            'Must store the true Brussels wall-clock equivalent, not the raw UTC digits.',
        );
    }

    public function test_write_a_winter_value_expressed_in_utc(): void
    {
        // 09:11 UTC and 10:11+01:00 (Brussels standard time) are the same real instant.
        $input = new \DateTimeImmutable('2026-01-15T09:11:00+00:00');

        $stored = $this->type->convertToDatabaseValue($input, $this->platform);

        self::assertSame('2026-01-15 10:11:00', $stored);
    }

    public function test_write_null_passes_through(): void
    {
        self::assertNull($this->type->convertToDatabaseValue(null, $this->platform));
    }

    public function test_write_rejects_non_datetime_value(): void
    {
        $this->expectException(ConversionException::class);

        $this->type->convertToDatabaseValue('2026-07-15 10:11:00', $this->platform);
    }

    // ── Round-trip / idempotence ──────────────────────────────────────────────────

    public function test_round_trip_write_then_read_is_stable(): void
    {
        $original = new \DateTimeImmutable('2026-07-15T10:11:00+02:00');

        $stored = $this->type->convertToDatabaseValue($original, $this->platform);
        $reread = $this->type->convertToPHPValue($stored, $this->platform);

        self::assertSame('2026-07-15T10:11:00+02:00', $reread->format(\DateTimeInterface::ATOM));
    }

    public function test_round_trip_is_idempotent_over_several_write_read_cycles(): void
    {
        $value = new \DateTimeImmutable('2026-07-15T10:11:00+02:00');

        for ($i = 0; $i < 5; $i++) {
            $stored = $this->type->convertToDatabaseValue($value, $this->platform);
            self::assertSame('2026-07-15 10:11:00', $stored, "Drifted on cycle {$i}");
            $value = $this->type->convertToPHPValue($stored, $this->platform);
            self::assertSame('2026-07-15T10:11:00+02:00', $value->format(\DateTimeInterface::ATOM), "Drifted on cycle {$i}");
        }
    }

    public function test_round_trip_across_the_dst_boundary_does_not_drift(): void
    {
        // A mission that starts just before the spring-forward transition and one that
        // starts just after — both must round-trip exactly.
        foreach (['2026-03-29 01:30:00', '2026-03-29 03:30:00'] as $wallClock) {
            $read = $this->type->convertToPHPValue($wallClock, $this->platform);
            $writtenBack = $this->type->convertToDatabaseValue($read, $this->platform);
            self::assertSame($wallClock, $writtenBack);
        }
    }

    // ── DST edge cases: documented behavior for non-existent / ambiguous local times ──
    //
    // Europe/Brussels spring-forward: last Sunday of March, 02:00 -> 03:00 CEST — the
    // wall-clock hour [02:00, 03:00) does not exist. Fall-back: last Sunday of October,
    // 03:00 -> 02:00 CET — the wall-clock hour [02:00, 03:00) happens TWICE (once at
    // +02:00, once at +01:00). Hospital missions realistically never start at 2:30am,
    // so this is a documented-behavior concern, not a "must fix differently" one — PHP's
    // own DateTimeImmutable/DateTimeZone resolution is deterministic in both cases,
    // verified empirically below and locked in as regression tests.

    public function test_dst_documented_behavior_spring_forward_gap_rolls_forward_by_the_gap_duration(): void
    {
        // 2026-03-29 02:30:00 does not exist in Europe/Brussels (clocks jump 02:00->03:00).
        // PHP resolves it by adding the gap duration (1h), landing on the first valid
        // instant past the transition: 03:30:00+02:00 (DST), not an error, not 02:30 CET.
        $result = $this->type->convertToPHPValue('2026-03-29 02:30:00', $this->platform);

        self::assertSame('2026-03-29T03:30:00+02:00', $result->format(\DateTimeInterface::ATOM));
    }

    public function test_dst_documented_behavior_fall_back_ambiguous_hour_resolves_to_standard_time(): void
    {
        // 2026-10-25 02:30:00 happens twice in Europe/Brussels (once at +02:00 DST, once
        // at +01:00 standard, as clocks fall back 03:00->02:00). PHP resolves the
        // ambiguous reading to the LATER (standard time, +01:00) occurrence.
        $result = $this->type->convertToPHPValue('2026-10-25 02:30:00', $this->platform);

        self::assertSame('2026-10-25T02:30:00+01:00', $result->format(\DateTimeInterface::ATOM));
    }

    public function test_dst_transition_boundaries_resolve_correctly_just_outside_the_gap(): void
    {
        $beforeSpringForward = $this->type->convertToPHPValue('2026-03-29 01:30:00', $this->platform);
        $afterSpringForward = $this->type->convertToPHPValue('2026-03-29 03:30:00', $this->platform);
        $beforeFallBack = $this->type->convertToPHPValue('2026-10-25 01:30:00', $this->platform);
        $afterFallBack = $this->type->convertToPHPValue('2026-10-25 03:30:00', $this->platform);

        self::assertSame('2026-03-29T01:30:00+01:00', $beforeSpringForward->format(\DateTimeInterface::ATOM));
        self::assertSame('2026-03-29T03:30:00+02:00', $afterSpringForward->format(\DateTimeInterface::ATOM));
        self::assertSame('2026-10-25T01:30:00+02:00', $beforeFallBack->format(\DateTimeInterface::ATOM));
        self::assertSame('2026-10-25T03:30:00+01:00', $afterFallBack->format(\DateTimeInterface::ATOM));
    }

    // ── SQL declaration — must be identical to the built-in type (no migration) ──────

    public function test_sql_declaration_is_identical_to_the_built_in_datetime_immutable_type(): void
    {
        $builtin = new \Doctrine\DBAL\Types\DateTimeImmutableType();

        self::assertSame(
            $builtin->getSQLDeclaration([], $this->platform),
            $this->type->getSQLDeclaration([], $this->platform),
            'Must stay DATETIME — any difference here would require a schema migration.',
        );
    }

    public function test_type_name(): void
    {
        self::assertSame('business_datetime_immutable', $this->type->getName());
    }
}
