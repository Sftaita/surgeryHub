<?php

namespace App\Service;

use App\Entity\Mission;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-156 — links missions generated before the stable post ↔ mission identity existed
 * (Mission::$schedulePost / $scheduleOccurrenceDate) to their post occurrence.
 *
 * The association is the one the generator ALREADY makes today for those missions (its
 * historical matching by times, PlanningGeneratorServiceV2::lastUnlinkedClaims()), read month
 * by month and site by site through preview() — read-only. Each match is classified:
 *  - CLEAR          : a single closest mission → linked by apply();
 *  - INTERCHANGEABLE: several equally close missions that nothing distinguishes (same
 *                     instrumentist, times, status, no encoded data) → linked as matched,
 *                     the choice has no visible effect;
 *  - TO_REVIEW      : equally close missions that differ → never linked, listed for the
 *                     manager (the historical matching keeps applying to them, unchanged).
 * Only the two link columns are written (never a status, an instrumentist, a time).
 */
final class MissionScheduleLinkBackfillService
{
    public const CLEAR = 'CLEAR';
    public const INTERCHANGEABLE = 'INTERCHANGEABLE';
    public const TO_REVIEW = 'TO_REVIEW';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanningGeneratorServiceV2 $generator,
    ) {}

    /**
     * @param list<string>   $months  "YYYY-MM"
     * @param list<int>|null $siteIds null = every site having a schedule post
     * @return list<array{missionId: int, postId: int, occurrenceDate: string, status: string, missionStatus: string, start: string, end: string, instrumentistId: ?int, siteId: int, ambiguousWith: list<int>}>
     */
    public function plan(array $months, ?array $siteIds = null): array
    {
        $siteIds ??= array_map('intval', array_column($this->em->getConnection()->fetchAllAssociative(
            'SELECT DISTINCT site_id FROM surgeon_schedule_post WHERE site_id IS NOT NULL ORDER BY site_id',
        ), 'site_id'));

        $claims = [];
        foreach ($months as $month) {
            foreach ($siteIds as $siteId) {
                $this->em->clear();
                $this->generator->preview($month, $siteId, null, null);
                foreach ($this->generator->lastUnlinkedClaims() as $claim) {
                    $claims[$claim['missionId']] = $claim + ['siteId' => $siteId];
                }
            }
        }
        if ($claims === []) {
            return [];
        }

        $missionIds = array_keys($claims);
        foreach ($claims as $claim) {
            array_push($missionIds, ...$claim['ambiguousWith']);
        }
        $facts = $this->facts(array_values(array_unique($missionIds)));

        // A match is ambiguous when equally close missions existed; its alternatives are
        // ambiguous with it too (the second room of a pair is "clear" only because the first
        // one took the other mission). One class per set of interchangeable candidates.
        $parent = [];
        $find = function (int $id) use (&$parent, &$find): int {
            return isset($parent[$id]) && $parent[$id] !== $id ? $parent[$id] = $find($parent[$id]) : $id;
        };
        foreach ($claims as $claim) {
            foreach ($claim['ambiguousWith'] as $other) {
                $parent[$find($other)] = $find($claim['missionId']);
            }
        }
        $sets = [];
        foreach (array_keys($facts) as $id) {
            $sets[$find($id)][] = $id;
        }
        $setClass = [];
        foreach ($sets as $root => $ids) {
            if (count($ids) < 2) {
                $setClass[$root] = self::CLEAR;
                continue;
            }
            $signatures = array_unique(array_map(static fn (int $id) => $facts[$id]['signature'], $ids));
            $setClass[$root] = count($signatures) === 1 && !in_array(true, array_map(static fn (int $id) => $facts[$id]['hasEncodedData'], $ids), true)
                ? self::INTERCHANGEABLE
                : self::TO_REVIEW;
        }

        $plan = [];
        foreach ($claims as $missionId => $claim) {
            $f = $facts[$missionId];
            $plan[] = [
                'missionId'       => $missionId,
                'postId'          => $claim['postId'],
                'occurrenceDate'  => $claim['occurrenceDate'],
                'status'          => $setClass[$find($missionId)],
                'missionStatus'   => $f['status'],
                'start'           => $f['start'],
                'end'             => $f['end'],
                'instrumentistId' => $f['instrumentistId'],
                'siteId'          => $claim['siteId'],
                'ambiguousWith'   => array_values(array_diff($sets[$find($missionId)], [$missionId])),
            ];
        }
        usort($plan, static fn (array $a, array $b) => [$a['occurrenceDate'], $a['missionId']] <=> [$b['occurrenceDate'], $b['missionId']]);

        return $plan;
    }

    /**
     * Writes the CLEAR and INTERCHANGEABLE links of $plan in one transaction, only on missions
     * still without a link. Returns the number of missions linked.
     *
     * @param list<array{missionId: int, postId: int, occurrenceDate: string, status: string}> $plan
     */
    public function apply(array $plan): int
    {
        $connection = $this->em->getConnection();

        return (int) $connection->transactional(static function () use ($connection, $plan): int {
            $linked = 0;
            foreach ($plan as $row) {
                if ($row['status'] === self::TO_REVIEW) {
                    continue;
                }
                $linked += (int) $connection->executeStatement(
                    'UPDATE mission SET schedule_post_id = ?, schedule_occurrence_date = ? WHERE id = ? AND schedule_post_id IS NULL',
                    [$row['postId'], $row['occurrenceDate'], $row['missionId']],
                );
            }

            return $linked;
        });
    }

    /**
     * @param list<int> $ids
     * @return array<int, array{status: string, start: string, end: string, instrumentistId: ?int, hasEncodedData: bool, signature: string}>
     */
    private function facts(array $ids): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT m.id, m.status, m.start_at, m.end_at, m.instrumentist_id,
                    (SELECT COUNT(*) FROM mission_intervention i WHERE i.mission_id = m.id)
                  + (SELECT COUNT(*) FROM material_line l WHERE l.mission_id = m.id)
                  + (SELECT COUNT(*) FROM mission_execution e WHERE e.mission_id = m.id
                        AND (e.actual_start_at IS NOT NULL OR e.actual_end_at IS NOT NULL OR e.actual_duration_minutes IS NOT NULL OR e.presence_id IS NOT NULL)) AS encoded
             FROM mission m WHERE m.id IN (?)',
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
        $facts = [];
        foreach ($rows as $r) {
            $facts[(int) $r['id']] = [
                'status'          => (string) $r['status'],
                'start'           => (string) $r['start_at'],
                'end'             => (string) $r['end_at'],
                'instrumentistId' => $r['instrumentist_id'] !== null ? (int) $r['instrumentist_id'] : null,
                'hasEncodedData'  => (int) $r['encoded'] > 0,
                'signature'       => implode('|', [$r['status'], $r['start_at'], $r['end_at'], $r['instrumentist_id'] ?? '-']),
            ];
        }

        return $facts;
    }
}
