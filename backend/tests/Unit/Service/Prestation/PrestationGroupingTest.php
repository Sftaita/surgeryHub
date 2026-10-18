<?php

namespace App\Tests\Unit\Service\Prestation;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use App\Service\Prestation\PrestationGrouping;
use PHPUnit\Framework\TestCase;

/**
 * D-156 — règle de regroupement : même instrumentiste, chirurgien, site, date locale,
 * BLOC, chevauchement strict, composantes transitives.
 */
final class PrestationGroupingTest extends TestCase
{
    private static int $seq = 1000;
    private User $sophie;
    private User $salve;
    private User $deltour;
    private User $other;
    private Hospital $delta;
    private Hospital $beta;

    protected function setUp(): void
    {
        $this->sophie  = $this->user();
        $this->salve   = $this->user();
        $this->deltour = $this->user();
        $this->other   = $this->user();
        $this->delta   = $this->site();
        $this->beta    = $this->site();
    }

    public function test_two_overlapping_missions_same_context_form_one_prestation(): void
    {
        $a = $this->mission('07:45', '13:00');
        $b = $this->mission('07:45', '18:00');

        $groups = PrestationGrouping::components([$a, $b]);

        self::assertCount(1, $groups);
        self::assertSame([$a, $b], $groups[0]);
        self::assertTrue(PrestationGrouping::isMultiRoomPair($a, $b));
        self::assertSame(615, PrestationGrouping::plannedUnionMinutes($groups[0]));
    }

    public function test_three_missions_with_transitive_overlaps_form_one_group(): void
    {
        $a = $this->mission('08:00', '10:00');
        $b = $this->mission('09:30', '12:00');
        $c = $this->mission('11:30', '14:00');   // overlaps B only

        $groups = PrestationGrouping::components([$c, $a, $b]);

        self::assertCount(1, $groups);
        self::assertSame([$a, $b, $c], $groups[0]);
        self::assertFalse(PrestationGrouping::isMultiRoomPair($a, $c), 'pairwise rule stays strict');
    }

    public function test_touching_at_a_boundary_or_separated_by_a_break_is_not_overlapping(): void
    {
        $morning   = $this->mission('08:00', '12:00');
        $afternoon = $this->mission('12:00', '17:00');
        $evening   = $this->mission('18:00', '20:00');

        $groups = PrestationGrouping::components([$morning, $afternoon, $evening]);

        self::assertCount(3, $groups);
        self::assertFalse(PrestationGrouping::isMultiRoomPair($morning, $afternoon));
    }

    public function test_different_surgeon_site_instrumentist_or_type_never_group(): void
    {
        $base = $this->mission('08:00', '13:00');
        $otherSurgeon = $this->mission('08:00', '13:00', surgeon: $this->other);
        $otherSite = $this->mission('08:00', '13:00', site: $this->beta);
        $salve = $this->mission('08:00', '13:00', instrumentist: $this->salve);
        $consult = $this->mission('08:00', '13:00', type: MissionType::CONSULTATION);

        foreach ([$otherSurgeon, $otherSite, $salve, $consult] as $m) {
            self::assertFalse(PrestationGrouping::isMultiRoomPair($base, $m));
            self::assertCount(2, PrestationGrouping::components([$base, $m]));
        }
    }

    public function test_cancelled_open_or_draft_missions_are_never_grouped(): void
    {
        $base = $this->mission('08:00', '13:00');
        foreach ([MissionStatus::CANCELLED, MissionStatus::OPEN, MissionStatus::DRAFT, MissionStatus::REJECTED] as $status) {
            $m = $this->mission('08:00', '13:00', status: $status);
            self::assertCount(2, PrestationGrouping::components([$base, $m]), $status->value);
        }
        $unassigned = $this->mission('08:00', '13:00', instrumentist: null, status: MissionStatus::ASSIGNED);
        self::assertCount(2, PrestationGrouping::components([$base, $unassigned]));
    }

    public function test_different_local_dates_never_group_even_across_midnight(): void
    {
        $night = $this->mission('22:00', '23:59', day: '2026-11-03');
        $night->setEndAt(new \DateTimeImmutable('2026-11-04 02:00', new \DateTimeZone('Europe/Brussels')));
        $early = $this->mission('01:00', '05:00', day: '2026-11-04');

        self::assertTrue(PrestationGrouping::overlapStrictly($night, $early));
        self::assertFalse(PrestationGrouping::isMultiRoomPair($night, $early), 'local start dates differ');
        self::assertCount(2, PrestationGrouping::components([$night, $early]));
    }

    public function test_reassigning_one_room_splits_only_that_day_and_reassigning_back_regroups(): void
    {
        // Four Wednesdays, two rooms each, Sophie on both.
        $days = ['2026-11-04', '2026-11-11', '2026-11-18', '2026-11-25'];
        $missions = [];
        foreach ($days as $d) {
            $missions[$d] = [$this->mission('07:45', '13:00', day: $d), $this->mission('07:45', '18:00', day: $d)];
        }
        $all = static fn () => array_merge(...array_values($missions));
        self::assertCount(4, PrestationGrouping::components($all()));

        // Salve takes room B on the 11th only.
        $missions['2026-11-11'][1]->setInstrumentist($this->salve);
        $groups = PrestationGrouping::components($all());
        self::assertCount(5, $groups);
        $sizes = array_map('count', $groups);
        sort($sizes);
        self::assertSame([1, 1, 2, 2, 2], $sizes);

        // Back to Sophie: four prestations again, no duplicate.
        $missions['2026-11-11'][1]->setInstrumentist($this->sophie);
        self::assertCount(4, PrestationGrouping::components($all()));
    }

    public function test_key_is_derived_from_composition(): void
    {
        $a = $this->mission('07:45', '13:00');
        $b = $this->mission('07:45', '18:00');

        self::assertSame('p' . min($a->getId(), $b->getId()) . '-' . max($a->getId(), $b->getId()), PrestationGrouping::key([$b, $a]));
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function user(): User
    {
        $u = new User();
        $u->setEmail('u' . (++self::$seq) . '@test.local');
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, self::$seq);
        return $u;
    }

    private function site(): Hospital
    {
        $h = new Hospital();
        $h->setName('Site ' . (++self::$seq));
        (new \ReflectionProperty(Hospital::class, 'id'))->setValue($h, self::$seq);
        return $h;
    }

    private function mission(
        string $from,
        string $to,
        string $day = '2026-11-04',
        ?User $surgeon = null,
        ?Hospital $site = null,
        ?User $instrumentist = null,
        MissionType $type = MissionType::BLOCK,
        MissionStatus $status = MissionStatus::ASSIGNED,
    ): Mission {
        $tz = new \DateTimeZone('Europe/Brussels');
        $m = new Mission();
        $m->setStatus($status);
        $m->setType($type);
        $m->setSurgeon($surgeon ?? $this->deltour);
        $m->setInstrumentist(func_num_args() >= 6 ? $instrumentist : $this->sophie);
        $m->setSite($site ?? $this->delta);
        $m->setStartAt(new \DateTimeImmutable("$day $from", $tz));
        $m->setEndAt(new \DateTimeImmutable("$day $to", $tz));
        $m->setCreatedBy($this->deltour);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        (new \ReflectionProperty(Mission::class, 'id'))->setValue($m, ++self::$seq);
        return $m;
    }
}
