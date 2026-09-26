<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\PlanningVersion;
use App\Enum\MissionStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * D-125 — « planning vivant » : distinguishes a PlanningVersion's PROVENANCE (the Missions
 * its generate() created/adopted, `Mission.planningVersion = V`) from the OPERATIONAL
 * REALITY of the period it covers (every live Mission of that period on its sites,
 * whatever created it: generation, manual creation before or after generation, an
 * accepted SurgeonMissionRequest, a room take-over, a post-deploy addition).
 *
 * Before D-125 every "calendar of a generated month" read (Mode Modification's mission
 * list, the coverage KPI, apply-modifications) filtered on provenance only, so a
 * legitimate Mission that generate() never created — or deliberately never touched,
 * because it was already published (R-01) — was invisible in the manager's calendar and
 * silently skipped by apply-modifications. PlanningVersion itself is unchanged: it stays
 * the provenance/history of one generation, never re-parented onto foreign Missions.
 *
 * Operational scope of version V =
 *   Missions with planningVersion = V (any status except REJECTED, as before)
 *   OR Missions with a planning-relevant status (see self::FOREIGN_EXCLUDED_STATUSES)
 *      whose startAt falls within V's period and whose site belongs to V's sites.
 *
 * Foreign DRAFT Missions are excluded (unpublished — a draft of ANOTHER version, or a
 * manual draft not dispatched yet, is not operational reality), as are DECLARED (an
 * instrumentist's declaration awaiting approval, handled by its own flow) and REJECTED.
 */
class PlanningVersionOperationalScope
{
    private const FOREIGN_EXCLUDED_STATUSES = [
        MissionStatus::DRAFT,
        MissionStatus::DECLARED,
        MissionStatus::REJECTED,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Sites the version covers. Single-site → [site]; group-scoped → its frozen
     * `scopeSiteIds` snapshot (D-115bis, never the SiteGroup's current membership);
     * legacy version without either → the distinct sites of its own Missions (read-only,
     * never persisted back — contrast PlanningDraftService::resolveGroupScopeSiteIds(),
     * which is a DRAFT-only self-healing path). Empty → provenance only.
     *
     * @return list<int>
     */
    public function siteIds(PlanningVersion $version): array
    {
        if ($version->getSite() !== null) {
            return [(int) $version->getSite()->getId()];
        }

        $snapshot = $version->getScopeSiteIds();
        if ($snapshot !== null && $snapshot !== []) {
            return array_values(array_map('intval', $snapshot));
        }

        $rows = $this->em->createQuery(
            'SELECT DISTINCT IDENTITY(m.site) AS siteId
             FROM App\Entity\Mission m
             WHERE m.planningVersion = :scopeLegacyVersion AND m.site IS NOT NULL'
        )
            ->setParameter('scopeLegacyVersion', $version)
            ->getArrayResult();

        return array_values(array_map(static fn (array $r) => (int) $r['siteId'], $rows));
    }

    /**
     * Restricts $qb (rooted on Mission alias $alias) to V's operational scope. Parameter
     * names are prefixed to never collide with the caller's own parameters.
     */
    public function restrict(QueryBuilder $qb, string $alias, PlanningVersion $version): QueryBuilder
    {
        $qb->setParameter('opScopeVersion', $version);

        $siteIds = $this->siteIds($version);
        if ($siteIds === []) {
            return $qb->andWhere(sprintf('%s.planningVersion = :opScopeVersion', $alias));
        }

        [$from, $to] = $this->periodBounds($version);

        return $qb
            ->andWhere(sprintf(
                '(%1$s.planningVersion = :opScopeVersion OR (%1$s.status NOT IN (:opScopeExcluded)'
                . ' AND %1$s.startAt >= :opScopeFrom AND %1$s.startAt <= :opScopeTo AND %1$s.site IN (:opScopeSiteIds)))',
                $alias,
            ))
            ->setParameter('opScopeExcluded', self::FOREIGN_EXCLUDED_STATUSES)
            ->setParameter('opScopeFrom', $from, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setParameter('opScopeTo', $to, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setParameter('opScopeSiteIds', $siteIds);
    }

    /**
     * Every non-REJECTED Mission in V's operational scope, fresh from the DB.
     *
     * @return Mission[]
     */
    public function missions(PlanningVersion $version): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Mission::class, 'm')
            ->andWhere('m.status != :opScopeRejected')
            ->setParameter('opScopeRejected', MissionStatus::REJECTED);

        return $this->restrict($qb, 'm', $version)->getQuery()->getResult();
    }

    /**
     * Status histogram of V's operational scope (one GROUP BY query) — the coverage KPI's
     * input, so "covered/open" always counts the same Missions the calendar shows.
     *
     * @return list<array{status: MissionStatus|string, cnt: int|string}>
     */
    public function countByStatus(PlanningVersion $version): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('m.status AS status, COUNT(m.id) AS cnt')
            ->from(Mission::class, 'm')
            ->groupBy('m.status');

        return $this->restrict($qb, 'm', $version)->getQuery()->getArrayResult();
    }

    /** Whether $mission belongs to V's operational scope — same rule as restrict(), in memory. */
    public function contains(PlanningVersion $version, Mission $mission): bool
    {
        if ($mission->getPlanningVersion()?->getId() === $version->getId()) {
            return true;
        }
        if (in_array($mission->getStatus(), self::FOREIGN_EXCLUDED_STATUSES, true)) {
            return false;
        }
        $siteId  = $mission->getSite()?->getId();
        $startAt = $mission->getStartAt();
        if ($siteId === null || $startAt === null || !in_array($siteId, $this->siteIds($version), true)) {
            return false;
        }

        $day = $startAt->format('Y-m-d');

        return $day >= $version->getPeriodStart()->format('Y-m-d')
            && $day <= $version->getPeriodEnd()->format('Y-m-d');
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} same naive-bounds convention as PlanningGeneratorServiceV2::loadExistingMissionsPool() */
    private function periodBounds(PlanningVersion $version): array
    {
        return [
            new \DateTimeImmutable($version->getPeriodStart()->format('Y-m-d') . ' 00:00:00'),
            new \DateTimeImmutable($version->getPeriodEnd()->format('Y-m-d') . ' 23:59:59'),
        ];
    }
}
