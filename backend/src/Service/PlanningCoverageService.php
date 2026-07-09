<?php

namespace App\Service;

use App\Dto\CoverageSummary;
use App\Entity\PlanningVersion;
use App\Enum\MissionStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Read-only coverage computation for a deployed PlanningVersion.
 * Never calls EntityManager::flush() or ::persist() — D-036 / Batch 15F.
 *
 * Coverage semantics:
 *   total    = missions in OPEN + ASSIGNED + SUBMITTED + VALIDATED + CLOSED + IN_PROGRESS
 *   covered  = missions in ASSIGNED + SUBMITTED + VALIDATED + CLOSED + IN_PROGRESS
 *   open     = missions in OPEN (pool, waiting for claim)
 *   cancelled = missions in CANCELLED (informational only, excluded from total)
 *   coveragePercent = covered / total * 100, rounded to 1 decimal; null when total = 0
 */
class PlanningCoverageService
{
    private const LIVE_STATUSES = [
        MissionStatus::OPEN,
        MissionStatus::ASSIGNED,
        MissionStatus::SUBMITTED,
        MissionStatus::VALIDATED,
        MissionStatus::CLOSED,
        MissionStatus::IN_PROGRESS,
    ];

    private const COVERED_STATUSES = [
        MissionStatus::ASSIGNED,
        MissionStatus::SUBMITTED,
        MissionStatus::VALIDATED,
        MissionStatus::CLOSED,
        MissionStatus::IN_PROGRESS,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Computes coverage for the given PlanningVersion.
     * Returns null if the version does not exist.
     * Executes exactly 1 GROUP BY query — no flush, no persist.
     */
    public function computeForVersion(int $versionId): ?CoverageSummary
    {
        $version = $this->em->find(PlanningVersion::class, $versionId);
        if ($version === null) {
            return null;
        }

        $rows = $this->em->createQuery(
            'SELECT m.status AS status, COUNT(m.id) AS cnt
             FROM App\Entity\Mission m
             WHERE m.planningVersion = :version
             GROUP BY m.status'
        )
            ->setParameter('version', $version)
            ->getArrayResult();

        $liveValues    = array_map(static fn (MissionStatus $s) => $s->value, self::LIVE_STATUSES);
        $coveredValues = array_map(static fn (MissionStatus $s) => $s->value, self::COVERED_STATUSES);

        $total = $covered = $open = $cancelled = 0;

        foreach ($rows as $row) {
            $statusVal = $row['status'] instanceof MissionStatus
                ? $row['status']->value
                : (string) $row['status'];
            $cnt = (int) $row['cnt'];

            if (in_array($statusVal, $liveValues, true)) {
                $total += $cnt;
            }
            if (in_array($statusVal, $coveredValues, true)) {
                $covered += $cnt;
            }
            if ($statusVal === MissionStatus::OPEN->value) {
                $open += $cnt;
            }
            if ($statusVal === MissionStatus::CANCELLED->value) {
                $cancelled += $cnt;
            }
        }

        return new CoverageSummary(
            versionId: $versionId,
            total:     $total,
            covered:   $covered,
            open:      $open,
            cancelled: $cancelled,
        );
    }
}
