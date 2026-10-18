<?php

namespace App\Tests\Unit\Service\Prestation;

use App\Service\Prestation\PresenceAllocator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * D-156 — ventilation « le segment partagé revient à la mission qui termine le plus tôt ».
 */
final class PresenceAllocatorTest extends TestCase
{
    private static function t(string $hhmm): int
    {
        return (new \DateTimeImmutable('2026-11-04 ' . $hhmm, new \DateTimeZone('Europe/Brussels')))->getTimestamp();
    }

    /** @return array{id:int,start:int,end:int} */
    private static function m(int $id, string $from, string $to): array
    {
        return ['id' => $id, 'start' => self::t($from), 'end' => self::t($to)];
    }

    public function test_sophie_example_nested_rooms_5h15_and_5h00_total_10h15(): void
    {
        $shares = PresenceAllocator::allocateMinutes([self::m(1, '07:45', '13:00'), self::m(2, '07:45', '18:00')], self::t('07:45'), self::t('18:00'));

        self::assertSame([1 => 315, 2 => 300], $shares);
        self::assertSame(615, array_sum($shares));
    }

    public function test_input_order_never_changes_the_result(): void
    {
        $a = PresenceAllocator::allocateMinutes([self::m(1, '07:45', '13:00'), self::m(2, '07:45', '18:00')], self::t('07:45'), self::t('18:00'));
        $b = PresenceAllocator::allocateMinutes([self::m(2, '07:45', '18:00'), self::m(1, '07:45', '13:00')], self::t('07:45'), self::t('18:00'));

        ksort($b);
        self::assertSame($a, $b);
    }

    /** @return iterable<string, array{0: list<array{id:int,start:int,end:int}>, 1: string, 2: string, 3: array<int,int>}> */
    public static function cases(): iterable
    {
        yield 'partial overlap' => [[self::m(1, '08:00', '12:00'), self::m(2, '10:00', '16:00')], '08:00', '16:00', [1 => 240, 2 => 240]];
        yield 'early arrival goes to the first segment owner' => [[self::m(1, '07:45', '13:00'), self::m(2, '07:45', '18:00')], '07:40', '18:00', [1 => 320, 2 => 300]];
        yield 'overrun goes to the last segment owner' => [[self::m(1, '07:45', '13:00'), self::m(2, '07:45', '18:00')], '07:45', '18:20', [1 => 315, 2 => 320]];
        yield 'early departure: the later room gets nothing' => [[self::m(1, '07:45', '13:00'), self::m(2, '07:45', '18:00')], '07:45', '12:00', [1 => 255, 2 => 0]];
        yield 'equal end: earliest start, then smallest id' => [[self::m(5, '08:00', '12:00'), self::m(3, '08:00', '12:00')], '08:00', '12:00', [5 => 0, 3 => 240]];
        yield 'three missions, transitive overlaps' => [[self::m(1, '08:00', '12:00'), self::m(2, '09:00', '17:00'), self::m(3, '10:00', '11:00')], '08:00', '17:00', [1 => 180, 2 => 300, 3 => 60]];
        yield 'real time inside an unplanned break goes to the segment before it' => [[self::m(1, '08:00', '12:00'), self::m(2, '13:00', '17:00')], '08:00', '17:00', [1 => 300, 2 => 240]];
        yield 'presence entirely outside the plan still fully imputed' => [[self::m(1, '08:00', '12:00'), self::m(2, '09:00', '16:00')], '16:30', '18:00', [1 => 0, 2 => 90]];
    }

    /**
     * @param list<array{id:int,start:int,end:int}> $members
     * @param array<int,int> $expected
     */
    #[DataProvider('cases')]
    public function test_allocation(array $members, string $from, string $to, array $expected): void
    {
        $shares = PresenceAllocator::allocateMinutes($members, self::t($from), self::t($to));

        self::assertSame($expected, $shares);
        self::assertSame((int) round((self::t($to) - self::t($from)) / 60), array_sum($shares), 'No minute lost, none invented');
    }

    public function test_seconds_are_rounded_without_losing_or_inventing_a_minute(): void
    {
        $members = [self::m(1, '08:00', '12:00'), self::m(2, '08:00', '16:00')];
        $start = self::t('08:00') + 20;   // 08:00:20
        $end   = self::t('16:00') + 50;   // 16:00:50

        $shares = PresenceAllocator::allocateMinutes($members, $start, $end);

        self::assertSame((int) round(($end - $start) / 60), array_sum($shares));
    }

    public function test_cents_are_reconciled_exactly_without_one_cent_gap(): void
    {
        // 615 min at 37.77 €/h = 387.14 € for the presence, computed ONCE.
        // Exact shares: 38714 × 315/615 = 19828.54 and 38714 × 300/615 = 18884.39 —
        // floors leave 2 cents, given to the largest remainders: 19829 + 18885 = 38714.
        $totalCents = (int) round(615 / 60 * 37.77 * 100);
        self::assertSame(38714, $totalCents);
        $cents = PresenceAllocator::allocateCents($totalCents, [1 => 315, 2 => 300], [1, 2]);

        self::assertSame($totalCents, array_sum($cents));
        self::assertSame([1 => 19829, 2 => 18885], $cents);
    }

    public function test_cents_with_zero_minutes_all_go_to_the_first_in_order(): void
    {
        self::assertSame([1 => 500, 2 => 0], PresenceAllocator::allocateCents(500, [1 => 0, 2 => 0], [1, 2]));
    }

    public function test_union_is_not_the_raw_sum_nor_first_start_to_last_end(): void
    {
        self::assertSame(615, PresenceAllocator::unionMinutes([
            ['start' => self::t('07:45'), 'end' => self::t('13:00')],
            ['start' => self::t('07:45'), 'end' => self::t('18:00')],
        ]));
        self::assertSame(480, PresenceAllocator::unionMinutes([
            ['start' => self::t('08:00'), 'end' => self::t('12:00')],
            ['start' => self::t('13:00'), 'end' => self::t('17:00')],
        ]), 'An interruption is not counted');
    }
}
