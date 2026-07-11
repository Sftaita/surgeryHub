<?php

namespace App\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\PlanningVersionStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Computes a "planning visible" diff between two PlanningVersion mission sets.
 *
 * Matching key: siteId_surgeonId_missionType_date_startAtRounded(15min)
 * No templateSlotId field exists on Mission, so this composite key is the best
 * available proxy. Two missions are "the same slot" when all five dimensions match.
 *
 * Collision handling: when two missions share the exact same key (e.g. a surgeon
 * with two consultations at the exact same rounded start time on the same site),
 * the second entry is stored under key_1, key_2, etc. Cross-version matching in
 * such collisions is order-dependent and may not be perfectly stable — document
 * this limit rather than over-engineering a V1 solution.
 *
 * Fields compared (planning-visible only):
 *   startAt, endAt, surgeon, site, instrumentist
 *
 * Fields excluded (never shown in diff):
 *   status, notes, metadata, financial fields, timestamps
 */
class PlanningDiffService
{
    private const ROUND_TO_MINUTES = 15;

    public function __construct(private readonly EntityManagerInterface $em) {}

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Find the ACTIVE version for the same site+period as $draft (for pre-deploy diff preview).
     * Falls back to the most recently ARCHIVED version if no ACTIVE exists.
     * Returns null on first-ever deploy.
     */
    public function findPreviousVersion(PlanningVersion $draft): ?PlanningVersion
    {
        $siteId = $draft->getSite()?->getId();

        $baseParams = [
            'draftId'     => $draft->getId(),
            'periodStart' => $draft->getPeriodStart(),
            'periodEnd'   => $draft->getPeriodEnd(),
        ];

        // Priority 1 — current ACTIVE version for the same site/period
        $active = $this->queryPreviousVersion(
            $siteId,
            $baseParams,
            PlanningVersionStatus::ACTIVE,
            orderByArchivedAt: false,
        );
        if ($active !== null) {
            return $active;
        }

        // Priority 2 — most recently archived version
        return $this->queryPreviousVersion(
            $siteId,
            $baseParams,
            PlanningVersionStatus::ARCHIVED,
            orderByArchivedAt: true,
        );
    }

    /**
     * Full diff: resolves the previous version automatically, then delegates to computeDiff().
     *
     * @return array{added: array<array>, removed: array<array>, modified: array<array>}
     */
    public function diff(PlanningVersion $draft): array
    {
        $previous    = $this->findPreviousVersion($draft);
        $oldMissions = $previous !== null ? $this->loadMissions($previous) : [];
        $newMissions = $this->loadMissions($draft);

        return $this->computeDiff($oldMissions, $newMissions);
    }

    /**
     * Pure diff computation (no DB access) — directly testable. Entity-based signature kept
     * exactly as-is (existing contract, existing tests) — serializes then delegates to
     * computeDiffFromSnapshots().
     *
     * @param Mission[] $oldMissions Missions from the previous (ACTIVE/ARCHIVED) version
     * @param Mission[] $newMissions Missions from the new DRAFT version
     * @return array{added: array<array>, removed: array<array>, modified: array<array>}
     */
    public function computeDiff(array $oldMissions, array $newMissions): array
    {
        return $this->computeDiffFromSnapshots(
            array_map($this->serializeMission(...), $oldMissions),
            array_map($this->serializeMission(...), $newMissions),
        );
    }

    /**
     * Pure diff computation over already-serialized mission arrays (see serializeMission()) —
     * no entity/DB access, so this is reusable for before/after-edit-session diffing (Planning
     * V2 Modification mode's apply-modifications endpoint, which snapshots the same shape
     * before and after applying a batch of mutations to the *same* version — not two different
     * versions, so it can't reuse the entity-based computeDiff() above: by the time you'd want
     * to diff, "old" and "new" would be the same, already-mutated Doctrine entity instances).
     *
     * @param array<int,array<string,mixed>> $oldMissions Serialized snapshot before
     * @param array<int,array<string,mixed>> $newMissions Serialized snapshot after
     * @return array{added: array<array>, removed: array<array>, modified: array<array>}
     */
    public function computeDiffFromSnapshots(array $oldMissions, array $newMissions): array
    {
        $oldIndex = $this->buildIndex($oldMissions);
        $newIndex = $this->buildIndex($newMissions);

        $added    = [];
        $removed  = [];
        $modified = [];

        foreach ($newIndex as $key => $newMission) {
            if (!isset($oldIndex[$key])) {
                $added[] = $newMission;
            } else {
                $changes = $this->detectChanges($oldIndex[$key], $newMission);
                if (!empty($changes)) {
                    $modified[] = [
                        'mission' => $newMission,
                        'changes' => $changes,
                    ];
                }
            }
        }

        foreach ($oldIndex as $key => $oldMission) {
            if (!isset($newIndex[$key])) {
                $removed[] = $oldMission;
            }
        }

        return ['added' => $added, 'removed' => $removed, 'modified' => $modified];
    }

    // ── Private — DB loading ──────────────────────────────────────────────────

    /** @return Mission[] */
    private function loadMissions(PlanningVersion $version): array
    {
        return $this->em->createQueryBuilder()
            ->select('m')
            ->from(Mission::class, 'm')
            ->where('m.planningVersion = :v')
            ->andWhere('m.status != :rejected')
            ->setParameter('v',        $version)
            ->setParameter('rejected', MissionStatus::REJECTED)
            ->getQuery()
            ->getResult();
    }

    private function queryPreviousVersion(
        ?int $siteId,
        array $baseParams,
        PlanningVersionStatus $status,
        bool $orderByArchivedAt,
    ): ?PlanningVersion {
        $qb = $this->em->createQueryBuilder()
            ->select('v')
            ->from(PlanningVersion::class, 'v')
            ->where('v.id != :draftId')
            ->andWhere('v.periodStart <= :periodEnd')
            ->andWhere('v.periodEnd >= :periodStart')
            ->andWhere('v.status = :status')
            ->setParameter('status', $status)
            ->setMaxResults(1);

        foreach ($baseParams as $k => $v) {
            $qb->setParameter($k, $v);
        }

        if ($siteId !== null) {
            $qb->andWhere('v.site = :siteId')->setParameter('siteId', $siteId);
        } else {
            $qb->andWhere('v.site IS NULL');
        }

        if ($orderByArchivedAt) {
            $qb->orderBy('v.archivedAt', 'DESC');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    // ── Private — key building ────────────────────────────────────────────────

    /**
     * Composite matching key: siteId_surgeonId_missionType_date_startRounded15min
     *
     * Rounding startAt to 15-minute slots absorbs minor timing drift between versions
     * (e.g. 08:00 vs 08:05 are treated as the same slot) while preserving genuine
     * half-day granularity (08:00 vs 13:30 produce distinct keys).
     *
     * @param array<string,mixed> $m Serialized mission (see serializeMission())
     */
    private function buildKey(array $m): string
    {
        $date   = $m['date'] ?? '0000-00-00';
        $start  = $m['startAt'] !== null ? $this->roundedTime($m['startAt']) : '00:00';
        $siteId = $m['siteId'] ?? 0;
        $surgId = $m['surgeonId'] ?? 0;
        $type   = $m['missionType'] ?? '';

        return sprintf('%d_%d_%s_%s_%s', $siteId, $surgId, $type, $date, $start);
    }

    /**
     * Rounds an "H:i" time string to the nearest N-minute slot, returning HH:MM string.
     * Example: 08:07 → 08:00, 08:08 → 08:15 (with 15-min slots).
     */
    private function roundedTime(string $hhmm): string
    {
        [$h, $i]   = array_map('intval', explode(':', $hhmm) + [0, 0]);
        $totalMins = $h * 60 + $i;
        $rounded   = (int) round($totalMins / self::ROUND_TO_MINUTES) * self::ROUND_TO_MINUTES;
        return sprintf('%02d:%02d', intdiv($rounded, 60) % 24, $rounded % 60);
    }

    /**
     * Indexes serialized missions by composite key.
     * Collision (identical key): append _1, _2… suffix.
     * Note: cross-version matching of collisions is order-dependent (V1 limit).
     *
     * @param array<int,array<string,mixed>> $missions
     * @return array<string, array<string,mixed>>
     */
    private function buildIndex(array $missions): array
    {
        $index = [];
        foreach ($missions as $mission) {
            $base  = $this->buildKey($mission);
            $key   = $base;
            $n     = 1;
            while (isset($index[$key])) {
                $key = $base . '_' . $n++;
            }
            $index[$key] = $mission;
        }
        return $index;
    }

    // ── Private — change detection ────────────────────────────────────────────

    /**
     * Compare two serialized missions for the same slot and return only planning-visible
     * changes. Excluded: status, notes, metadata, financial fields, timestamps.
     *
     * @param array<string,mixed> $old
     * @param array<string,mixed> $new
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function detectChanges(array $old, array $new): array
    {
        $changes = [];

        // Horaire — exact comparison (not rounded); we want to show genuine time changes
        if ($old['startAt'] !== $new['startAt'] || $old['endAt'] !== $new['endAt']) {
            $changes['schedule'] = [
                'from' => ['startAt' => $old['startAt'], 'endAt' => $old['endAt']],
                'to'   => ['startAt' => $new['startAt'], 'endAt' => $new['endAt']],
            ];
        }

        // Instrumentiste
        if ($old['instrumentistId'] !== $new['instrumentistId']) {
            $changes['instrumentist'] = [
                'from' => $old['instrumentistId'] !== null
                    ? ['id' => $old['instrumentistId'], 'name' => $old['instrumentistName']]
                    : null,
                'to' => $new['instrumentistId'] !== null
                    ? ['id' => $new['instrumentistId'], 'name' => $new['instrumentistName']]
                    : null,
            ];
        }

        // Chirurgien (rare — key change would normally create add+remove, but guard anyway)
        if ($old['surgeonId'] !== $new['surgeonId']) {
            $changes['surgeon'] = [
                'from' => $old['surgeonId'] !== null
                    ? ['id' => $old['surgeonId'], 'name' => $old['surgeonName']]
                    : null,
                'to' => $new['surgeonId'] !== null
                    ? ['id' => $new['surgeonId'], 'name' => $new['surgeonName']]
                    : null,
            ];
        }

        // Site
        if ($old['siteId'] !== $new['siteId']) {
            $changes['site'] = [
                'from' => $old['siteName'],
                'to'   => $new['siteName'],
            ];
        }

        return $changes;
    }

    // ── Private — serialization ───────────────────────────────────────────────

    /**
     * Public: reused by callers that need to build their own before/after snapshots
     * (e.g. Planning V2 Modification mode's apply-modifications endpoint) to pass into
     * computeDiff() directly, without a DB round-trip through diff()/loadMissions().
     *
     * @return array<string, mixed>
     */
    public function serializeMission(Mission $m): array
    {
        return [
            'missionId'         => $m->getId(),
            'date'              => $m->getStartAt()?->format('Y-m-d'),
            'period'            => ((int) $m->getStartAt()?->format('G')) < 12 ? 'AM' : 'PM',
            'startAt'           => $m->getStartAt()?->format('H:i'),
            'endAt'             => $m->getEndAt()?->format('H:i'),
            'missionType'       => $m->getType()?->value,
            'surgeonId'         => $m->getSurgeon()?->getId(),
            'surgeonName'       => $this->displayName($m->getSurgeon()),
            'instrumentistId'   => $m->getInstrumentist()?->getId(),
            'instrumentistName' => $m->getInstrumentist() !== null ? $this->displayName($m->getInstrumentist()) : null,
            'siteId'            => $m->getSite()?->getId(),
            'siteName'          => $m->getSite()?->getName(),
        ];
    }

    private function displayName(?User $u): ?string
    {
        if ($u === null) {
            return null;
        }
        $name = trim(($u->getFirstname() ?? '') . ' ' . ($u->getLastname() ?? ''));
        return $name !== '' ? $name : $u->getEmail();
    }
}
