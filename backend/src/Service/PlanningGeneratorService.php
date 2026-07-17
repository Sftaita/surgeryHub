<?php

namespace App\Service;

use App\Entity\Absence;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningSlot;
use App\Entity\PlanningTemplate;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\PlanningTemplateType;
use App\Enum\PlanningVersionStatus;
use App\Enum\SchedulePrecision;
use Doctrine\ORM\EntityManagerInterface;

class PlanningGeneratorService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanningScoreService $scoreService,
    ) {}

    /**
     * Preview: returns array of preview lines WITHOUT persisting anything.
     *
     * @return array<int, array{
     *   date: string,
     *   slotId: int,
     *   surgeonId: int,
     *   surgeonName: string,
     *   missionType: string,
     *   startTime: string,
     *   endTime: string,
     *   siteId: int|null,
     *   siteName: string|null,
     *   instrumentistId: int|null,
     *   instrumentistName: string|null,
     *   status: string,
     *   existingMissionId: int|null
     * }>
     */
    public function preview(string $from, string $to, ?int $siteId, ?int $surgeonId): array
    {
        $start = new \DateTimeImmutable($from);
        $end   = new \DateTimeImmutable($to);

        // ── 3 requêtes DB au total (au lieu de ~N_jours × N_slots) ───────────
        // 1. Tous les templates + leurs slots (filtrés par site si fourni)
        $allTemplates = $this->loadAllTemplates($siteId);

        // 2. Toutes les absences de la période → map [userId => [[dateStart, dateEnd], ...]]
        $absencesByUser = $this->loadAbsencesMap($from, $to);

        // 3. Toutes les missions existantes de la période
        //    → pool ["{surgeonId}_{siteId}_{date}" => Mission[]] pour claimMission()
        //    → index [instrumentistId => Mission[]] pour vérifier les conflits en mémoire
        $existingMissionsPool    = $this->loadExistingMissionsPool($from, $to, $siteId);
        $missionsByInstrumentist = $this->buildInstrumentistIndex($existingMissionsPool);
        $claimedMissionIds       = [];

        // Intra-preview conflict detection (same instrumentist, overlapping slots in this run)
        // [instrumentistId => [[dateStr, startMinutes, endMinutes], …]]
        $previewAssignments = [];

        $lines   = [];
        $current = $start;

        while ($current <= $end) {
            $isoWeek  = (int) $current->format('W');
            $weekType = ($isoWeek % 2 === 0) ? PlanningTemplateType::PAIR : PlanningTemplateType::IMPAIR;
            $isoDay   = (int) $current->format('N');
            $dayStr   = $current->format('Y-m-d');

            // Filter templates in memory — no DB query per day
            $templates = array_filter(
                $allTemplates,
                fn (PlanningTemplate $t) =>
                    $t->getType() === PlanningTemplateType::TOUTES || $t->getType() === $weekType
            );

            foreach ($templates as $template) {
                foreach ($template->getSlots() as $slot) {
                    if ($slot->getDayOfWeek() !== $isoDay) {
                        continue;
                    }
                    if ($surgeonId !== null && $slot->getSurgeon()?->getId() !== $surgeonId) {
                        continue;
                    }

                    $effectiveSite = $slot->getSite() ?? $template->getSite();
                    $surgeon       = $slot->getSurgeon();
                    $surgeonName   = $this->displayName($surgeon);
                    $instrumentist = $slot->getInstrumentist();

                    // Absence checks in memory — no DB query per slot
                    $surgeonAbsent = $this->isAbsentFast($surgeon?->getId(), $dayStr, $absencesByUser);
                    if ($surgeonAbsent) {
                        $lines[] = $this->buildLine(
                            $current, $slot, $effectiveSite, $surgeonName, $instrumentist, 'SKIPPED', null
                        );
                        continue;
                    }

                    $instrumentistAbsent = $instrumentist !== null
                        && $this->isAbsentFast($instrumentist->getId(), $dayStr, $absencesByUser);
                    $slotInstrumentist   = $instrumentistAbsent ? null : $instrumentist;

                    // Claim an existing mission from the pre-loaded pool (no DB query)
                    $existingMission = $this->claimMission(
                        $existingMissionsPool, $claimedMissionIds,
                        $surgeon, $effectiveSite, $current, $slot->getStartTime(),
                        $slotInstrumentist,
                    );

                    $status                       = 'UNCOVERED';
                    $existingMissionInstrumentist = null;

                    if ($existingMission !== null) {
                        $existingMissionInstrumentist = $existingMission->getInstrumentist();

                        if ($existingMissionInstrumentist === null && $slotInstrumentist === null) {
                            $status = 'COVERED';
                        } elseif ($existingMissionInstrumentist?->getId() === $slotInstrumentist?->getId()) {
                            $status = 'COVERED';
                        } else {
                            $status = 'MODIFIED';
                        }
                    } else {
                        if ($slotInstrumentist !== null) {
                            $instId        = $slotInstrumentist->getId();
                            $slotStartMins = (int) $slot->getStartTime()->format('H') * 60
                                           + (int) $slot->getStartTime()->format('i');
                            $slotEndMins   = (int) $slot->getEndTime()->format('H') * 60
                                           + (int) $slot->getEndTime()->format('i');

                            // 1. Intra-preview conflict (in memory)
                            $intraConflict = false;
                            foreach ($previewAssignments[$instId] ?? [] as [$aDate, $aStart, $aEnd]) {
                                if ($aDate === $dayStr && $slotStartMins < $aEnd && $slotEndMins > $aStart) {
                                    $intraConflict = true;
                                    break;
                                }
                            }

                            if ($intraConflict) {
                                $status = 'CONFLICT';
                            } else {
                                // 2. Conflict against existing DB missions (in memory via pool)
                                $startAt = $current->setTime(
                                    (int) $slot->getStartTime()->format('H'),
                                    (int) $slot->getStartTime()->format('i'),
                                );
                                $endAt = $current->setTime(
                                    (int) $slot->getEndTime()->format('H'),
                                    (int) $slot->getEndTime()->format('i'),
                                );
                                $hasConflict = $instId !== null && $this->hasConflictFast(
                                    $instId, $startAt, $endAt, $missionsByInstrumentist
                                );
                                if ($hasConflict) {
                                    $status = 'CONFLICT';
                                } else {
                                    $status = 'COVERED';
                                    if ($instId !== null) {
                                        $previewAssignments[$instId][] = [$dayStr, $slotStartMins, $slotEndMins];
                                    }
                                }
                            }
                        } else {
                            $status = 'UNCOVERED';
                        }
                    }

                    $lines[] = $this->buildLine(
                        $current, $slot, $effectiveSite, $surgeonName,
                        $slotInstrumentist,
                        $status,
                        $existingMission?->getId(),
                        $status === 'MODIFIED' ? $existingMissionInstrumentist : null,
                    );
                }
            }

            $current = $current->modify('+1 day');
        }

        // ── Second pass: auto-assign freed instrumentists to UNCOVERED slots ──
        // An instrumentist is "freed" if their slot was SKIPPED (surgeon absent).
        // They can fill an UNCOVERED slot on the same day if no other active slot overlaps.
        $lines = $this->resolveFreedInstrumentists($lines);

        return $lines;
    }

    /**
     * Second pass: redirect freed instrumentists (from SKIPPED slots) to UNCOVERED
     * slots on the same day when no time overlap prevents it.
     * Updates the $lines array in-place and returns it.
     */
    private function resolveFreedInstrumentists(array $lines): array
    {
        // Build pool: [date => [instId => name]]
        // An instrumentist is "freed" when ALL their slots on a day are SKIPPED.
        $freedByDate = [];
        foreach ($lines as $line) {
            if ($line['status'] === 'SKIPPED' && $line['instrumentistId'] !== null) {
                $freedByDate[$line['date']][$line['instrumentistId']] = $line['instrumentistName'];
            }
        }

        // Remove instrumentists who also have at least one non-SKIPPED slot on that day —
        // they are not truly freed (e.g. they work AM with another surgeon).
        foreach ($lines as $line) {
            if ($line['status'] !== 'SKIPPED' && $line['instrumentistId'] !== null) {
                unset($freedByDate[$line['date']][$line['instrumentistId']]);
            }
        }

        if (empty($freedByDate)) {
            return $lines;
        }

        // Track assignments made in this second pass so we can prevent double-booking
        // even if the by-reference $lines mutations are not immediately visible in the
        // inner loop (PHP array modification during foreach can be unreliable).
        // Structure: [instId => [[date, startMins, endMins], ...]]
        $secondPassAssignments = [];

        foreach ($lines as &$line) {
            // Target: UNCOVERED lines, OR COVERED lines that have no instrumentist yet
            // (existing mission in DB with no instrumentist assigned).
            $needsInstrumentist = $line['status'] === 'UNCOVERED'
                || ($line['status'] === 'COVERED' && $line['instrumentistId'] === null);

            if (!$needsInstrumentist) {
                continue;
            }

            $date      = $line['date'];
            $available = $freedByDate[$date] ?? [];
            if (empty($available)) {
                continue;
            }

            $lineStart = $this->hhmm2mins($line['startTime']);
            $lineEnd   = $this->hhmm2mins($line['endTime']);

            foreach ($available as $instId => $freedName) {
                // 1. Check first-pass assignments (from the original lines array — SKIPPED excluded)
                $hasOverlap = false;
                foreach ($lines as $other) {
                    if (
                        $other['date']             === $date
                        && $other['instrumentistId'] === $instId
                        && $other['status']          !== 'SKIPPED'
                        && $this->hhmm2mins($other['startTime']) < $lineEnd
                        && $this->hhmm2mins($other['endTime'])   > $lineStart
                    ) {
                        $hasOverlap = true;
                        break;
                    }
                }

                // 2. Also check assignments already made in THIS second pass to handle
                //    cases where the by-reference mutation isn't yet visible in the inner loop.
                if (!$hasOverlap) {
                    foreach ($secondPassAssignments[$instId] ?? [] as [$spDate, $spStart, $spEnd]) {
                        if ($spDate === $date && $spStart < $lineEnd && $spEnd > $lineStart) {
                            $hasOverlap = true;
                            break;
                        }
                    }
                }

                if (!$hasOverlap) {
                    $line['instrumentistId']   = $instId;
                    $line['instrumentistName'] = $freedName;
                    $line['status']            = 'COVERED';
                    $line['freedFrom']         = true;

                    // Record this assignment for subsequent overlap checks in this pass.
                    $secondPassAssignments[$instId][] = [$date, $lineStart, $lineEnd];
                    break;
                }
            }
        }
        unset($line);

        return $lines;
    }

    private function hhmm2mins(string $hhmm): int
    {
        [$h, $m] = explode(':', $hhmm);
        return (int) $h * 60 + (int) $m;
    }

    /**
     * Generate: create/update missions and wrap them in a PlanningVersion DRAFT.
     *
     * @return array{versionId: int, created: int, updated: int, skipped: int}
     */
    public function generate(string $from, string $to, ?int $siteId, ?int $surgeonId, User $generatedBy): array
    {
        $lines = $this->preview($from, $to, $siteId, $surgeonId);

        // Create PlanningVersion DRAFT
        $version = new PlanningVersion();
        $version->setPeriodStart(new \DateTimeImmutable($from));
        $version->setPeriodEnd(new \DateTimeImmutable($to));
        $version->setGeneratedBy($generatedBy);
        $version->setStatus(PlanningVersionStatus::DRAFT);

        if ($siteId !== null) {
            $site = $this->em->find(Hospital::class, $siteId);
            $version->setSite($site);
        }

        // Compute sequential version number for this (site, period)
        $version->setVersionNumber($this->nextVersionNumber($siteId, $from, $to));

        $this->em->persist($version);

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($lines as $line) {
            if ($line['status'] === 'SKIPPED') {
                $skipped++;
                continue;
            }

            if ($line['existingMissionId'] !== null && $line['status'] === 'MODIFIED') {
                // Update existing DRAFT mission's instrumentist. Preserve published missions.
                $mission = $this->em->find(Mission::class, $line['existingMissionId']);
                if ($mission !== null) {
                    if ($mission->getStatus() !== MissionStatus::DRAFT) {
                        $skipped++;
                        continue;
                    }
                    $newInstrumentist = $line['instrumentistId'] !== null
                        ? $this->em->find(User::class, $line['instrumentistId'])
                        : null;
                    $mission->setInstrumentist($newInstrumentist);
                    $mission->setPlanningVersion($version);
                    $updated++;
                }
                continue;
            }

            if ($line['existingMissionId'] !== null) {
                $mission = $this->em->find(Mission::class, $line['existingMissionId']);
                if ($mission !== null && $mission->getStatus() === MissionStatus::DRAFT) {
                    $mission->setPlanningVersion($version);

                    // Freed instrumentist auto-assigned to an existing mission with no instrumentist:
                    // apply the assignment so the mission gets an instrumentist after generate().
                    if (($line['freedFrom'] ?? false) && $line['instrumentistId'] !== null && $mission->getInstrumentist() === null) {
                        $freed = $this->em->find(User::class, $line['instrumentistId']);
                        if ($freed !== null) {
                            $mission->setInstrumentist($freed);
                            $updated++;
                            continue;
                        }
                    }
                }
                $skipped++;
                continue;
            }

            // Create new mission
            $slot = $this->em->find(PlanningSlot::class, $line['slotId']);
            if ($slot === null) { $skipped++; continue; }

            $surgeon = $this->em->find(User::class, $line['surgeonId']);
            if ($surgeon === null) { $skipped++; continue; }

            $site = $line['siteId'] !== null ? $this->em->find(Hospital::class, $line['siteId']) : null;
            if ($site === null) { $skipped++; continue; }

            $instrumentist = $line['instrumentistId'] !== null
                ? $this->em->find(User::class, $line['instrumentistId'])
                : null;

            // D-066: Mission.startAt/endAt are business_datetime_immutable — see the
            // matching comment in PlanningGeneratorServiceV2.php for why $day must be
            // explicitly Brussels-labeled here rather than naively constructed.
            $day       = new \DateTimeImmutable($line['date'], new \DateTimeZone(\App\Doctrine\Type\BusinessDateTimeImmutableType::BUSINESS_TIMEZONE));
            $startTime = $slot->getStartTime();
            $endTime   = $slot->getEndTime();

            $startAt = $day->setTime((int) $startTime->format('H'), (int) $startTime->format('i'), (int) $startTime->format('s'));
            $endAt   = $day->setTime((int) $endTime->format('H'),   (int) $endTime->format('i'),   (int) $endTime->format('s'));

            $mission = new Mission();
            $mission->setStatus(MissionStatus::DRAFT);
            $mission->setType($slot->getMissionType());
            $mission->setSurgeon($surgeon);
            $mission->setInstrumentist($instrumentist);
            $mission->setSite($site);
            $mission->setStartAt($startAt);
            $mission->setEndAt($endAt);
            $mission->setSchedulePrecision(SchedulePrecision::EXACT);
            $mission->setCreatedBy($generatedBy);
            $mission->setPlanningVersion($version);

            $this->em->persist($mission);
            $created++;
        }

        $this->em->flush();

        return [
            'versionId' => $version->getId(),
            'created'   => $created,
            'updated'   => $updated,
            'skipped'   => $skipped,
        ];
    }

    /** Returns the next version number for a given (site, period) tuple. */
    private function nextVersionNumber(?int $siteId, string $from, string $to): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('MAX(v.versionNumber)')
            ->from(PlanningVersion::class, 'v')
            ->where('v.periodStart = :from')
            ->andWhere('v.periodEnd = :to')
            ->setParameter('from', new \DateTimeImmutable($from))
            ->setParameter('to', new \DateTimeImmutable($to));

        if ($siteId !== null) {
            $qb->andWhere('v.site = :siteId')->setParameter('siteId', $siteId);
        } else {
            $qb->andWhere('v.site IS NULL');
        }

        $max = $qb->getQuery()->getSingleScalarResult();

        return $max !== null ? ((int) $max + 1) : 1;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Load ALL templates (with their slots) for the given site — ONE query.
     * Week-type filtering (PAIR/IMPAIR) is done in memory inside the while loop.
     *
     * @return PlanningTemplate[]
     */
    private function loadAllTemplates(?int $siteId): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('t', 's')
            ->from(PlanningTemplate::class, 't')
            ->leftJoin('t.slots', 's');

        if ($siteId !== null) {
            $qb->where('t.site = :siteId OR t.site IS NULL')
               ->setParameter('siteId', $siteId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Load ALL absences that overlap the preview period — ONE query.
     * Returns [userId => [[dateStart Y-m-d, dateEnd Y-m-d], ...]]
     *
     * Uses getArrayResult() + IDENTITY projection so mocks can return plain arrays
     * (no entity construction required in tests).
     * The "absencesFrom" token is distinctive for test mock routing.
     *
     * @return array<int, list<array{0: string, 1: string}>>
     */
    private function loadAbsencesMap(string $from, string $to): array
    {
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(a.user) as userId,
                    a.dateStart       as dateStart,
                    a.dateEnd         as dateEnd
             FROM App\Entity\Absence a
             WHERE a.dateEnd >= :absencesFrom AND a.dateStart <= :absencesTo'
        )
            ->setParameter('absencesFrom', $from)
            ->setParameter('absencesTo', $to)
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            if (!isset($row['userId'])) {
                continue;
            }
            $userId = (int) $row['userId'];
            $start  = $row['dateStart'] instanceof \DateTimeInterface
                ? $row['dateStart']->format('Y-m-d')
                : (string) $row['dateStart'];
            $end    = $row['dateEnd'] instanceof \DateTimeInterface
                ? $row['dateEnd']->format('Y-m-d')
                : (string) $row['dateEnd'];
            $map[$userId][] = [$start, $end];
        }

        return $map;
    }

    /** In-memory absence check — no DB query. */
    private function isAbsentFast(?int $userId, string $dayStr, array $absencesByUser): bool
    {
        if ($userId === null) {
            return false;
        }
        foreach ($absencesByUser[$userId] ?? [] as [$start, $end]) {
            if ($dayStr >= $start && $dayStr <= $end) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build a secondary index of existing missions by instrumentistId.
     * Used for in-memory conflict detection (replaces per-slot DB queries).
     *
     * @param array<string, Mission[]> $pool
     * @return array<int, Mission[]>   [instrumentistId => Mission[]]
     */
    private function buildInstrumentistIndex(array $pool): array
    {
        $index = [];
        foreach ($pool as $missions) {
            foreach ($missions as $mission) {
                $instId = $mission->getInstrumentist()?->getId();
                if ($instId !== null) {
                    $index[$instId][] = $mission;
                }
            }
        }
        return $index;
    }

    /** In-memory conflict check — no DB query. */
    private function hasConflictFast(
        int $instrumentistId,
        \DateTimeImmutable $slotStart,
        \DateTimeImmutable $slotEnd,
        array $missionsByInstrumentist,
    ): bool {
        foreach ($missionsByInstrumentist[$instrumentistId] ?? [] as $mission) {
            if ($mission->getStatus() === MissionStatus::REJECTED) {
                continue;
            }
            if ($mission->getStartAt() < $slotEnd && $mission->getEndAt() > $slotStart) {
                return true;
            }
        }
        return false;
    }

    /**
     * Pre-load all existing missions for the period into an in-memory pool.
     * Uses DQL (not QB) so the "poolFrom" token can be identified in test mocks.
     *
     * @return array<string, Mission[]>  Key: "{surgeonId}_{siteId}_{YYYY-MM-DD}"
     */
    private function loadExistingMissionsPool(string $from, string $to, ?int $siteId): array
    {
        $fromDt = (new \DateTimeImmutable($from))->setTime(0, 0, 0);
        $toDt   = (new \DateTimeImmutable($to))->setTime(23, 59, 59);

        // Build DQL dynamically — siteId filter added only when provided.
        // "poolFrom" token is distinctive for test mock routing.
        $dql = 'SELECT m FROM App\Entity\Mission m
                WHERE m.startAt >= :poolFrom
                  AND m.startAt <= :poolTo
                  AND m.status NOT IN (:excluded)';

        if ($siteId !== null) {
            $dql .= ' AND m.site = :siteId';
        }

        $q = $this->em->createQuery($dql)
            ->setParameter('poolFrom', $fromDt, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setParameter('poolTo',   $toDt,   \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setParameter('excluded', [MissionStatus::REJECTED]);

        if ($siteId !== null) {
            $site = $this->em->find(Hospital::class, $siteId);
            if ($site !== null) {
                $q->setParameter('siteId', $site);
            } else {
                return []; // site inconnue → pool vide
            }
        }

        /** @var Mission[] $missions */
        $missions = $q->getResult();

        $pool = [];
        foreach ($missions as $mission) {
            $surgeon = $mission->getSurgeon();
            $site    = $mission->getSite();
            if ($surgeon === null || $site === null) {
                continue;
            }
            $key          = $surgeon->getId() . '_' . $site->getId() . '_' . $mission->getStartAt()->format('Y-m-d');
            $pool[$key][] = $mission;
        }

        return $pool;
    }

    /**
     * Claim the best-matching mission from the pool for a given slot.
     *
     * Matching priority (multi-room support):
     *   1. Exact instrumentist match — slot and mission have the same instrumentist (or both null)
     *   2. Any unclaimed mission at the same surgeon+site+day+time (±30 min)
     *
     * A claimed mission is removed from consideration for other slots.
     * This prevents two template slots at the same time from being matched to the same mission.
     *
     * @param array<string, Mission[]> $pool
     * @param array<int, true>         $claimedIds
     */
    private function claimMission(
        array &$pool,
        array &$claimedIds,
        ?User $surgeon,
        ?Hospital $site,
        \DateTimeImmutable $day,
        \DateTimeImmutable $slotStart,
        ?User $slotInstrumentist,
    ): ?Mission {
        if ($surgeon === null || $site === null) {
            return null;
        }

        $key        = $surgeon->getId() . '_' . $site->getId() . '_' . $day->format('Y-m-d');
        $candidates = $pool[$key] ?? [];
        if (empty($candidates)) {
            return null;
        }

        $slotStartMins = $this->hhmm2mins($slotStart->format('H:i'));
        $slotInstId    = $slotInstrumentist?->getId();

        // Filter to missions within ±30 minutes of the slot start time
        $nearby = array_filter($candidates, function (Mission $m) use ($slotStartMins) {
            $mMins = (int) $m->getStartAt()->format('H') * 60 + (int) $m->getStartAt()->format('i');
            return abs($mMins - $slotStartMins) <= 30;
        });

        // 1. Prefer exact instrumentist match
        foreach ($nearby as $mission) {
            if (isset($claimedIds[$mission->getId()])) {
                continue;
            }
            $mInstId = $mission->getInstrumentist()?->getId();
            if ($mInstId === $slotInstId) {
                $claimedIds[$mission->getId()] = true;
                return $mission;
            }
        }

        // 2. Fall back to any unclaimed mission at the same time
        foreach ($nearby as $mission) {
            if (isset($claimedIds[$mission->getId()])) {
                continue;
            }
            $claimedIds[$mission->getId()] = true;
            return $mission;
        }

        return null;
    }

    private function displayName(?User $user): string
    {
        if ($user === null) {
            return '';
        }
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }

    /**
     * @return array{
     *   date: string,
     *   slotId: int,
     *   surgeonId: int,
     *   surgeonName: string,
     *   missionType: string,
     *   startTime: string,
     *   endTime: string,
     *   siteId: int|null,
     *   siteName: string|null,
     *   instrumentistId: int|null,
     *   instrumentistName: string|null,
     *   status: string,
     *   existingMissionId: int|null,
     *   existingInstrumentistId: int|null,
     *   existingInstrumentistName: string|null,
     *   freedFrom: bool
     * }
     */
    private function buildLine(
        \DateTimeImmutable $day,
        PlanningSlot $slot,
        ?Hospital $effectiveSite,
        string $surgeonName,
        ?User $instrumentist,
        string $status,
        ?int $existingMissionId,
        ?User $existingInstrumentist = null,
    ): array {
        return [
            'date'                     => $day->format('Y-m-d'),
            'slotId'                   => $slot->getId(),
            'surgeonId'                => $slot->getSurgeon()?->getId(),
            'surgeonName'              => $surgeonName,
            'missionType'              => $slot->getMissionType()->value,
            'startTime'                => $slot->getStartTime()->format('H:i'),
            'endTime'                  => $slot->getEndTime()->format('H:i'),
            'siteId'                   => $effectiveSite?->getId(),
            'siteName'                 => $effectiveSite?->getName(),
            'instrumentistId'          => $instrumentist?->getId(),
            'instrumentistName'        => $this->displayName($instrumentist),
            'status'                   => $status,
            'existingMissionId'        => $existingMissionId,
            // For MODIFIED: who is currently in the existing mission
            'existingInstrumentistId'  => $existingInstrumentist?->getId(),
            'existingInstrumentistName' => $existingInstrumentist !== null
                ? $this->displayName($existingInstrumentist)
                : null,
            // Populated by resolveFreedInstrumentists() second pass
            'freedFrom'                => false,
        ];
    }
}
