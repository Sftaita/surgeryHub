<?php

namespace App\Tests\Unit\Service;

use App\Dto\CoverageSummary;
use App\Entity\PlanningVersion;
use App\Enum\MissionStatus;
use App\Service\PlanningCoverageService;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PlanningCoverageServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PlanningCoverageService $service;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->service = new PlanningCoverageService($this->em);
    }

    // ── Version not found ─────────────────────────────────────────────────────

    public function test_returns_null_when_version_not_found(): void
    {
        $this->em->method('find')->willReturn(null);

        $result = $this->service->computeForVersion(999);

        self::assertNull($result);
    }

    // ── CANCELLED excluded from total ─────────────────────────────────────────

    public function test_cancelled_excluded_from_total(): void
    {
        $this->mockVersion([
            ['status' => MissionStatus::OPEN,     'cnt' => 2],
            ['status' => MissionStatus::CANCELLED, 'cnt' => 5],
        ]);

        $summary = $this->service->computeForVersion(1);

        self::assertNotNull($summary);
        self::assertSame(2, $summary->total);
        self::assertSame(5, $summary->cancelled);
    }

    // ── Covered statuses ──────────────────────────────────────────────────────

    public function test_all_covered_statuses_contribute_to_covered_count(): void
    {
        $this->mockVersion([
            ['status' => MissionStatus::ASSIGNED,    'cnt' => 3],
            ['status' => MissionStatus::SUBMITTED,   'cnt' => 2],
            ['status' => MissionStatus::VALIDATED,   'cnt' => 1],
            ['status' => MissionStatus::CLOSED,      'cnt' => 4],
            ['status' => MissionStatus::IN_PROGRESS, 'cnt' => 1],
        ]);

        $summary = $this->service->computeForVersion(1);

        self::assertNotNull($summary);
        self::assertSame(11, $summary->total);
        self::assertSame(11, $summary->covered);
    }

    // ── OPEN reduces coverage ─────────────────────────────────────────────────

    public function test_open_missions_count_in_total_but_not_in_covered(): void
    {
        $this->mockVersion([
            ['status' => MissionStatus::OPEN,     'cnt' => 4],
            ['status' => MissionStatus::ASSIGNED, 'cnt' => 6],
        ]);

        $summary = $this->service->computeForVersion(1);

        self::assertNotNull($summary);
        self::assertSame(10,   $summary->total);
        self::assertSame(6,    $summary->covered);
        self::assertSame(4,    $summary->open);
        self::assertSame(60.0, $summary->coveragePercent);
    }

    // ── coveragePercent null when total = 0 ───────────────────────────────────

    public function test_coverage_percent_is_null_when_total_is_zero(): void
    {
        $this->mockVersion([]);

        $summary = $this->service->computeForVersion(1);

        self::assertNotNull($summary);
        self::assertSame(0,    $summary->total);
        self::assertNull($summary->coveragePercent);
    }

    // ── coveragePercent rounding ──────────────────────────────────────────────

    public function test_coverage_percent_rounded_to_one_decimal(): void
    {
        $this->mockVersion([
            ['status' => MissionStatus::OPEN,     'cnt' => 2],
            ['status' => MissionStatus::ASSIGNED, 'cnt' => 1],
        ]);

        $summary = $this->service->computeForVersion(1);

        self::assertNotNull($summary);
        // 1/3 * 100 = 33.333... → rounds to 33.3
        self::assertSame(33.3, $summary->coveragePercent);
    }

    // ── CoverageSummary DTO ───────────────────────────────────────────────────

    public function test_returns_coverage_summary_dto(): void
    {
        $this->mockVersion([
            ['status' => MissionStatus::ASSIGNED, 'cnt' => 5],
        ]);

        $result = $this->service->computeForVersion(1);

        self::assertInstanceOf(CoverageSummary::class, $result);
        self::assertSame(1, $result->versionId);
    }

    // ── Status value as string (Doctrine enum hydration may vary) ─────────────

    public function test_handles_status_as_string_value(): void
    {
        $this->mockVersion([
            ['status' => 'OPEN',     'cnt' => 3],
            ['status' => 'ASSIGNED', 'cnt' => 7],
        ]);

        $summary = $this->service->computeForVersion(1);

        self::assertNotNull($summary);
        self::assertSame(10, $summary->total);
        self::assertSame(7,  $summary->covered);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param array<array{status: MissionStatus|string, cnt: int}> $rows */
    private function mockVersion(array $rows): void
    {
        $version = $this->createMock(PlanningVersion::class);
        $this->em->method('find')->willReturn($version);

        $this->em->method('createQuery')->willReturnCallback(function () use ($rows): AbstractQuery {
            $q = $this->createMock(Query::class);
            $q->method('setParameter')->willReturnSelf();
            $q->method('getArrayResult')->willReturn($rows);
            return $q;
        });
    }
}
