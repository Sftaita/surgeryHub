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
     * Pure diff computation (no DB access) — directly testable.
     *
     * @param Mission[] $oldMissions Missions from the previous (ACTIVE/ARCHIVED) version
     * @param Mission[] $newMissions Missions from the new DRAFT version
     * @return array{added: array<array>, removed: array<array>, modified: array<array>}
     */
    public function computeDiff(array $oldMissions, array $newMissions): array
    {
        $oldIndex = $this->buildIndex($oldMissions);
        $newIndex = $this->buildIndex($newMissions);

        $added    = [];
        $removed  = [];
        $modified = [];

        foreach ($newIndex as $key => $newMission) {
            if (!isset($oldIndex[$key])) {
                $added[] = $this->serializeMission($newMission);
            } else {
                $changes = $this->detectChanges($oldIndex[$key], $newMission);
                if (!empty($changes)) {
                    $modified[] = [
                        'mission' => $this->serializeMission($newMission),
                        'changes' => $changes,
                    ];
                }
            }
        }

        foreach ($oldIndex as $key => $oldMission) {
            if (!isset($newIndex[$key])) {
                $removed[] = $this->serializeMission($oldMission);
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
     */
    private function buildKey(Mission $m): string
    {
        $date   = $m->getStartAt()?->format('Y-m-d') ?? '0000-00-00';
        $start  = $m->getStartAt() !== null ? $this->roundedTime($m->getStartAt()) : '00:00';
        $siteId = $m->getSite()?->getId() ?? 0;
        $surgId = $m->getSurgeon()?->getId() ?? 0;
        $type   = $m->getType()?->value ?? '';

        return sprintf('%d_%d_%s_%s_%s', $siteId, $surgId, $type, $date, $start);
    }

    /**
     * Rounds a datetime to the nearest N-minute slot, returning HH:MM string.
     * Example: 08:07 → 08:00, 08:08 → 08:15 (with 15-min slots).
     */
    private function roundedTime(\DateTimeImmutable $dt): string
    {
        $totalMins = (int) $dt->format('G') * 60 + (int) $dt->format('i');
        $rounded   = (int) round($totalMins / self::ROUND_TO_MINUTES) * self::ROUND_TO_MINUTES;
        return sprintf('%02d:%02d', intdiv($rounded, 60) % 24, $rounded % 60);
    }

    /**
     * Indexes missions by composite key.
     * Collision (identical key): append _1, _2… suffix.
     * Note: cross-version matching of collisions is order-dependent (V1 limit).
     *
     * @param Mission[] $missions
     * @return array<string, Mission>
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
     * Compare two missions for the same slot and return only planning-visible changes.
     * Excluded: status, notes, metadata, financial fields, timestamps.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function detectChanges(Mission $old, Mission $new): array
    {
        $changes = [];

        // Horaire — exact comparison (not rounded); we want to show genuine time changes
        $oldStart = $old->getStartAt()?->format('H:i');
        $oldEnd   = $old->getEndAt()?->format('H:i');
        $newStart = $new->getStartAt()?->format('H:i');
        $newEnd   = $new->getEndAt()?->format('H:i');

        if ($oldStart !== $newStart || $oldEnd !== $newEnd) {
            $changes['schedule'] = [
                'from' => ['startAt' => $oldStart, 'endAt' => $oldEnd],
                'to'   => ['startAt' => $newStart, 'endAt' => $newEnd],
            ];
        }

        // Instrumentiste
        if ($old->getInstrumentist()?->getId() !== $new->getInstrumentist()?->getId()) {
            $changes['instrumentist'] = [
                'from' => $this->serializeUser($old->getInstrumentist()),
                'to'   => $this->serializeUser($new->getInstrumentist()),
            ];
        }

        // Chirurgien (rare — key change would normally create add+remove, but guard anyway)
        if ($old->getSurgeon()?->getId() !== $new->getSurgeon()?->getId()) {
            $changes['surgeon'] = [
                'from' => $this->serializeUser($old->getSurgeon()),
                'to'   => $this->serializeUser($new->getSurgeon()),
            ];
        }

        // Site
        if ($old->getSite()?->getId() !== $new->getSite()?->getId()) {
            $changes['site'] = [
                'from' => $old->getSite()?->getName(),
                'to'   => $new->getSite()?->getName(),
            ];
        }

        return $changes;
    }

    // ── Private — serialization ───────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function serializeMission(Mission $m): array
    {
        return [
            'date'              => $m->getStartAt()?->format('Y-m-d'),
            'period'            => ((int) $m->getStartAt()?->format('G')) < 12 ? 'AM' : 'PM',
            'startAt'           => $m->getStartAt()?->format('H:i'),
            'endAt'             => $m->getEndAt()?->format('H:i'),
            'missionType'       => $m->getType()?->value,
            'surgeonId'         => $m->getSurgeon()?->getId(),
            'surgeonName'       => $this->displayName($m->getSurgeon()),
            'instrumentistId'   => $m->getInstrumentist()?->getId(),
            'instrumentistName' => $m->getInstrumentist() !== null ? $this->displayName($m->getInstrumentist()) : null,
            'siteName'          => $m->getSite()?->getName(),
        ];
    }

    /** @return array{id: int|null, name: string|null}|null */
    private function serializeUser(?User $u): ?array
    {
        if ($u === null) {
            return null;
        }
        return ['id' => $u->getId(), 'name' => $this->displayName($u)];
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
