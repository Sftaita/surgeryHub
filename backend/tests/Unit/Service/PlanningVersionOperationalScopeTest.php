<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningVersion;
use App\Enum\MissionStatus;
use App\Service\PlanningVersionOperationalScope;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * D-125 — the in-memory half of the operational-scope rule (contains()), which
 * PlanningModificationService::apply() uses to decide whether an edited line may touch a
 * Mission. The DQL half (restrict()) is exercised end-to-end by
 * LivingPlanningOperationalScopeTest.
 */
final class PlanningVersionOperationalScopeTest extends TestCase
{
    private PlanningVersionOperationalScope $scope;
    private PlanningVersion $version;
    private Hospital $site;

    protected function setUp(): void
    {
        $this->scope = new PlanningVersionOperationalScope($this->createMock(EntityManagerInterface::class));
        $this->site  = $this->withId(new Hospital(), 10);
        $this->version = $this->withId(new PlanningVersion(), 7);
        $this->version->setSite($this->site);
        $this->version->setPeriodStart(new \DateTimeImmutable('2026-10-01'));
        $this->version->setPeriodEnd(new \DateTimeImmutable('2026-10-31'));
    }

    private function withId(object $entity, int $id): object
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
        return $entity;
    }

    private function mission(MissionStatus $status, string $startAt, ?Hospital $site = null, ?PlanningVersion $version = null): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setSite($site ?? $this->site);
        $m->setStartAt(new \DateTimeImmutable($startAt, new \DateTimeZone('Europe/Brussels')));
        $m->setPlanningVersion($version);
        return $m;
    }

    /** @return iterable<string, array{MissionStatus, string, bool}> */
    public static function foreignMissions(): iterable
    {
        yield 'manual ASSIGNED in period'        => [MissionStatus::ASSIGNED, '2026-10-12 08:00', true];
        yield 'accepted request OPEN in period'  => [MissionStatus::OPEN, '2026-10-01 00:00', true];
        yield 'CANCELLED in period (displayed)'  => [MissionStatus::CANCELLED, '2026-10-31 23:30', true];
        yield 'VALIDATED in period'              => [MissionStatus::VALIDATED, '2026-10-15 13:00', true];
        yield 'foreign DRAFT never operational'  => [MissionStatus::DRAFT, '2026-10-12 08:00', false];
        yield 'DECLARED never operational'       => [MissionStatus::DECLARED, '2026-10-12 08:00', false];
        yield 'REJECTED never operational'       => [MissionStatus::REJECTED, '2026-10-12 08:00', false];
        yield 'day before period'                => [MissionStatus::ASSIGNED, '2026-09-30 23:59', false];
        yield 'day after period'                 => [MissionStatus::ASSIGNED, '2026-11-01 00:00', false];
    }

    #[DataProvider('foreignMissions')]
    public function test_a_mission_not_generated_by_the_version_is_in_scope_by_period_site_and_status(MissionStatus $status, string $startAt, bool $expected): void
    {
        self::assertSame($expected, $this->scope->contains($this->version, $this->mission($status, $startAt)));
    }

    public function test_another_site_is_never_in_scope(): void
    {
        $other = $this->withId(new Hospital(), 11);
        self::assertFalse($this->scope->contains($this->version, $this->mission(MissionStatus::ASSIGNED, '2026-10-12 08:00', $other)));
    }

    public function test_the_version_own_missions_are_always_in_scope_whatever_their_status(): void
    {
        self::assertTrue($this->scope->contains($this->version, $this->mission(MissionStatus::DRAFT, '2026-10-12 08:00', null, $this->version)));
    }

    public function test_group_version_uses_its_frozen_site_snapshot(): void
    {
        $group = $this->withId(new PlanningVersion(), 8);
        $group->setPeriodStart(new \DateTimeImmutable('2026-10-01'));
        $group->setPeriodEnd(new \DateTimeImmutable('2026-10-31'));
        $group->setScopeSiteIds([10, 12]);

        self::assertSame([10, 12], $this->scope->siteIds($group));
        self::assertTrue($this->scope->contains($group, $this->mission(MissionStatus::OPEN, '2026-10-05 08:00')));
    }
}
