<?php

namespace App\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningOccurrenceException;
use App\Entity\PlanningVersion;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\OccurrenceExceptionType;
use App\Enum\PlanningVersionStatus;
use App\Enum\RecurrenceFrequency;
use App\Enum\SchedulePrecision;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Planning V2 generator: same preview()/generate() contract and status semantics as
 * PlanningGeneratorService (V1), but reads SurgeonSchedulePost + RecurrenceRule +
 * ShiftPeriodConfig instead of PlanningTemplate/PlanningSlot. V1 is untouched — this
 * is a parallel, independently-selectable implementation (no shared code, no shared
 * state) so legacy PAIR/IMPAIR/TOUTES sites keep working unmodified.
 *
 * Reuses PlanningVersion/Mission exactly as V1 does: generate() wraps created/updated
 * missions in a PlanningVersion DRAFT, with zero changes to either entity.
 */
class PlanningGeneratorServiceV2
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Preview: returns array of preview lines WITHOUT persisting anything.
     * Exactly one of $siteId / $siteGroupId must be provided.
     *
     * @return array<int, array{
     *   date: string, postId: int, surgeonId: int, surgeonName: string,
     *   missionType: string, startTime: string, endTime: string,
     *   siteId: int|null, siteName: string|null,
     *   instrumentistId: int|null, instrumentistName: string|null,
     *   status: string, existingMissionId: int|null,
     *   existingInstrumentistId: int|null, existingInstrumentistName: string|null,
     *   freedFrom: bool
     * }>
     */
    public function preview(string $month, ?int $siteId, ?int $siteGroupId, ?int $surgeonId): array
    {
        [$start, $end] = $this->monthRange($month);
        $siteIds        = $this->resolveSiteIds($siteId, $siteGroupId);
        $from           = $start->format('Y-m-d');
        $to             = $end->format('Y-m-d');

        // ── Fixed, constant query budget (D-036 carried over to V2) ──────────
        // Query 1: all active posts overlapping the month, for the resolved sites
        $allPosts = $this->loadActivePosts($siteIds, $from, $to, $surgeonId);
        // Query 2: all absences overlapping the month → [userId => [[start,end], ...]]
        $absencesByUser = $this->loadAbsencesMap($from, $to);
        // Query 3: all existing missions in the period/sites → claim pool + instrumentist index
        $existingMissionsPool    = $this->loadExistingMissionsPool($from, $to, $siteIds);
        $missionsByInstrumentist = $this->buildInstrumentistIndex($existingMissionsPool);
        // Query 4: shift-period hour configs for the resolved sites
        $shiftConfigs = $this->loadShiftPeriodConfigs($siteIds);
        // Query 5: one-off occurrence exceptions for these posts (CANCELLED/MOVED/TIME_OVERRIDE/INSTRUMENTIST_OVERRIDE)
        $postIds              = array_map(static fn (SurgeonSchedulePost $p) => $p->getId(), $allPosts);
        [$exceptionsByOriginal, $exceptionsByTargetDate] = $this->loadOccurrenceExceptions($postIds);
        // (+1 query already spent above, inside resolveSiteIds, only when siteGroupId was given)

        $claimedMissionIds  = [];
        $previewAssignments = []; // [instId => [[dateStr, startMins, endMins], ...]]
        $lines              = [];

        $current = $start;
        while ($current <= $end) {
            $isoDay = (int) $current->format('N');
            $dayStr = $current->format('Y-m-d');

            foreach ($allPosts as $post) {
                $naturallyActive = $this->isOccurrenceActive($post, $current, $isoDay);
                $exception       = $exceptionsByOriginal[$post->getId() . '_' . $dayStr] ?? null;

                if ($naturallyActive && $exception === null) {
                    $this->emitOccurrenceLine(
                        $post, $current, $dayStr, $post->getInstrumentist(), null, null,
                        $shiftConfigs, $absencesByUser, $existingMissionsPool, $claimedMissionIds,
                        $previewAssignments, $missionsByInstrumentist, $lines,
                    );
                    continue;
                }

                if (!$naturallyActive || $exception === null) {
                    continue;
                }

                // An exception exists for this post's natural occurrence on this date.
                // CANCELLED / MOVED both suppress the natural occurrence entirely — the
                // recurring rule and every other occurrence are untouched either way.
                if ($exception->getType() === OccurrenceExceptionType::CANCELLED
                    || $exception->getType() === OccurrenceExceptionType::MOVED) {
                    continue;
                }

                if ($exception->getType() === OccurrenceExceptionType::TIME_OVERRIDE) {
                    $this->emitOccurrenceLine(
                        $post, $current, $dayStr, $post->getInstrumentist(),
                        $exception->getOverrideStartTime(), $exception->getOverrideEndTime(),
                        $shiftConfigs, $absencesByUser, $existingMissionsPool, $claimedMissionIds,
                        $previewAssignments, $missionsByInstrumentist, $lines,
                    );
                    continue;
                }

                // INSTRUMENTIST_OVERRIDE
                $this->emitOccurrenceLine(
                    $post, $current, $dayStr, $exception->getOverrideInstrumentist(), null, null,
                    $shiftConfigs, $absencesByUser, $existingMissionsPool, $claimedMissionIds,
                    $previewAssignments, $missionsByInstrumentist, $lines,
                );
            }

            // Occurrences MOVED into this date from elsewhere — the post's own recurrence
            // rule would not naturally fire today, but the exception relocates it here.
            foreach ($exceptionsByTargetDate[$dayStr] ?? [] as $exception) {
                $post = $exception->getPost();
                $this->emitOccurrenceLine(
                    $post, $current, $dayStr, $post->getInstrumentist(),
                    $exception->getOverrideStartTime(), $exception->getOverrideEndTime(),
                    $shiftConfigs, $absencesByUser, $existingMissionsPool, $claimedMissionIds,
                    $previewAssignments, $missionsByInstrumentist, $lines,
                );
            }

            $current = $current->modify('+1 day');
        }

        return $this->resolveFreedInstrumentists($lines);
    }

    /**
     * Builds a single preview line for one post occurring on one date, applying any
     * per-occurrence instrumentist/time override, then appends it to $lines. Shared by
     * natural occurrences, TIME_OVERRIDE/INSTRUMENTIST_OVERRIDE occurrences, and
     * MOVED-in occurrences — they all become the same kind of line once their effective
     * instrumentist/time is resolved.
     */
    private function emitOccurrenceLine(
        SurgeonSchedulePost $post,
        \DateTimeImmutable $current,
        string $dayStr,
        ?User $effectiveInstrumentist,
        ?\DateTimeImmutable $overrideStartTime,
        ?\DateTimeImmutable $overrideEndTime,
        array $shiftConfigs,
        array $absencesByUser,
        array &$existingMissionsPool,
        array &$claimedMissionIds,
        array &$previewAssignments,
        array $missionsByInstrumentist,
        array &$lines,
    ): void {
        $site = $post->getSite();

        if ($overrideStartTime !== null && $overrideEndTime !== null) {
            $startTime = $overrideStartTime;
            $endTime   = $overrideEndTime;
        } else {
            $configKey = $site->getId() . '_' . $post->getPeriod()->value;
            if (!isset($shiftConfigs[$configKey])) {
                throw new \DomainException(sprintf(
                    'Aucune configuration ShiftPeriodConfig pour le site %d / période %s — impossible de générer le poste %d.',
                    $site->getId(),
                    $post->getPeriod()->value,
                    $post->getId(),
                ));
            }
            [$startTime, $endTime] = $shiftConfigs[$configKey];
        }

        $surgeon     = $post->getSurgeon();
        $surgeonName = $this->displayName($surgeon);

        $surgeonAbsent = $this->isAbsentFast($surgeon->getId(), $dayStr, $absencesByUser);
        if ($surgeonAbsent) {
            $lines[] = $this->buildLine(
                $current, $post, $site, $surgeonName, $startTime, $endTime,
                $effectiveInstrumentist, 'SKIPPED', null,
            );
            return;
        }

        $instrumentistAbsent = $effectiveInstrumentist !== null
            && $this->isAbsentFast($effectiveInstrumentist->getId(), $dayStr, $absencesByUser);
        $slotInstrumentist = $instrumentistAbsent ? null : $effectiveInstrumentist;

        $existingMission = $this->claimMission(
            $existingMissionsPool, $claimedMissionIds,
            $surgeon, $site, $current, $startTime, $slotInstrumentist,
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
        } elseif ($slotInstrumentist !== null) {
            $instId        = $slotInstrumentist->getId();
            $slotStartMins = $this->hhmm2mins($startTime->format('H:i'));
            $slotEndMins   = $this->hhmm2mins($endTime->format('H:i'));

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
                $startAt = $current->setTime((int) $startTime->format('H'), (int) $startTime->format('i'));
                $endAt   = $current->setTime((int) $endTime->format('H'), (int) $endTime->format('i'));
                $hasConflict = $this->hasConflictFast($instId, $startAt, $endAt, $missionsByInstrumentist);

                if ($hasConflict) {
                    $status = 'CONFLICT';
                } else {
                    $status = 'COVERED';
                    $previewAssignments[$instId][] = [$dayStr, $slotStartMins, $slotEndMins];
                }
            }
        } else {
            $status = 'UNCOVERED';
        }

        $lines[] = $this->buildLine(
            $current, $post, $site, $surgeonName, $startTime, $endTime,
            $slotInstrumentist, $status, $existingMission?->getId(),
            $status === 'MODIFIED' ? $existingMissionInstrumentist : null,
        );
    }

    /**
     * Deterministic SHA-256 fingerprint of the planning inputs for the scope.
     * Runs 3 queries independently of preview(). Used as previewVersion token.
     */
    public function computePreviewVersion(string $month, ?int $siteId, ?int $siteGroupId): string
    {
        [$periodStart, $periodEnd] = $this->monthRange($month);
        $siteIds = $this->resolveSiteIds($siteId, $siteGroupId);
        $from    = $periodStart->format('Y-m-d');
        $to      = $periodEnd->format('Y-m-d');

        $allPosts       = $this->loadActivePosts($siteIds, $from, $to, null);
        $absencesByUser = $this->loadAbsencesMap($from, $to);
        $shiftConfigs   = $this->loadShiftPeriodConfigs($siteIds);

        return $this->hashPreviewInputs($allPosts, $absencesByUser, $shiftConfigs);
    }

    private function hashPreviewInputs(array $allPosts, array $absencesByUser, array $shiftConfigs): string
    {
        $postData = [];
        foreach ($allPosts as $post) {
            $rule       = $post->getRecurrence();
            $weekdays   = $rule->getWeekdays();
            sort($weekdays);
            $monthWeeks = $rule->getMonthWeeks();
            sort($monthWeeks);
            $postData[] = [
                'id'         => $post->getId(),
                'surgeonId'  => $post->getSurgeon()->getId(),
                'siteId'     => $post->getSite()->getId(),
                'startDate'  => $post->getStartDate()->format('Y-m-d'),
                'endDate'    => $post->getEndDate()?->format('Y-m-d'),
                'frequency'  => $rule->getFrequency()->value,
                'interval'   => $rule->getInterval(),
                'anchorDate' => $rule->getAnchorDate()->format('Y-m-d'),
                'weekdays'   => $weekdays,
                'monthWeeks' => $monthWeeks,
            ];
        }
        usort($postData, static fn (array $a, array $b) => $a['id'] <=> $b['id']);

        $absenceData = [];
        foreach ($absencesByUser as $userId => $ranges) {
            foreach ($ranges as [$s, $e]) {
                $absenceData[] = ['u' => $userId, 's' => $s, 'e' => $e];
            }
        }
        usort($absenceData, static fn (array $a, array $b) => $a['u'] <=> $b['u'] ?: strcmp($a['s'], $b['s']));

        $shiftData = [];
        foreach ($shiftConfigs as $key => [$tStart, $tEnd]) {
            $shiftData[$key] = [
                's' => $tStart instanceof \DateTimeInterface ? $tStart->format('H:i') : (string) $tStart,
                'e' => $tEnd instanceof \DateTimeInterface ? $tEnd->format('H:i') : (string) $tEnd,
            ];
        }
        ksort($shiftData);

        return hash('sha256', json_encode([$postData, $absenceData, $shiftData], \JSON_THROW_ON_ERROR));
    }

    /**
     * Generate: create/update missions and wrap them in a PlanningVersion DRAFT.
     * Reuses PlanningVersion and Mission exactly as V1's generate() does.
     *
     * When $overrideLines is provided (Alternative A — Preview Editor), the caller
     * supplies the final edited lines directly; the internal preview() call is skipped.
     * R-01 (never touch non-DRAFT missions) is enforced in both modes.
     *
     * @return array{versionId: int, created: int, updated: int, skipped: int}
     */
    public function generate(string $month, ?int $siteId, ?int $siteGroupId, ?int $surgeonId, User $generatedBy, ?array $overrideLines = null): array
    {
        if ($overrideLines !== null) {
            $lines = $overrideLines;
        } else {
            $lines = $this->preview($month, $siteId, $siteGroupId, $surgeonId);
        }
        [$start, $end] = $this->monthRange($month);

        $version = new PlanningVersion();
        $version->setPeriodStart($start);
        $version->setPeriodEnd($end);
        $version->setGeneratedBy($generatedBy);
        $version->setStatus(PlanningVersionStatus::DRAFT);

        // PlanningVersion has no siteGroup column (kept unchanged, see Batch 2 deviations) —
        // a group-wide version stores site=null, same as V1's "no site filter" case.
        if ($siteId !== null) {
            $version->setSite($this->em->find(Hospital::class, $siteId));
        }

        $version->setVersionNumber($this->nextVersionNumber($siteId, $start, $end));

        $this->em->persist($version);

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($lines as $line) {
            if ($line['status'] === 'SKIPPED') {
                $skipped++;
                continue;
            }

            // Override mode: the editor's explicit decision — always sync instrumentist, enforce R-01.
            if ($overrideLines !== null && ($line['existingMissionId'] ?? null) !== null) {
                $mission = $this->em->find(Mission::class, $line['existingMissionId']);
                if ($mission === null || $mission->getStatus() !== MissionStatus::DRAFT) {
                    $skipped++;
                    continue;
                }
                $mission->setPlanningVersion($version);
                $newInstrumentist = ($line['instrumentistId'] ?? null) !== null
                    ? $this->em->find(User::class, $line['instrumentistId'])
                    : null;
                $mission->setInstrumentist($newInstrumentist);
                $updated++;
                continue;
            }

            if ($line['existingMissionId'] !== null && $line['status'] === 'MODIFIED') {
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

            $post = $this->em->find(SurgeonSchedulePost::class, $line['postId']);
            if ($post === null) { $skipped++; continue; }

            $surgeon = $this->em->find(User::class, $line['surgeonId']);
            if ($surgeon === null) { $skipped++; continue; }

            $site = $line['siteId'] !== null ? $this->em->find(Hospital::class, $line['siteId']) : null;
            if ($site === null) { $skipped++; continue; }

            $instrumentist = $line['instrumentistId'] !== null
                ? $this->em->find(User::class, $line['instrumentistId'])
                : null;

            $day = new \DateTimeImmutable($line['date']);
            [$h1, $m1] = explode(':', $line['startTime']);
            [$h2, $m2] = explode(':', $line['endTime']);

            $mission = new Mission();
            $mission->setStatus(MissionStatus::DRAFT);
            $mission->setType($post->getType());
            $mission->setSurgeon($surgeon);
            $mission->setInstrumentist($instrumentist);
            $mission->setSite($site);
            $mission->setStartAt($day->setTime((int) $h1, (int) $m1));
            $mission->setEndAt($day->setTime((int) $h2, (int) $m2));
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

    // ── Recurrence expansion (in-memory, no DB hits) ────────────────────────

    private function isOccurrenceActive(SurgeonSchedulePost $post, \DateTimeImmutable $date, int $isoDay): bool
    {
        if ($date < $post->getStartDate()) {
            return false;
        }
        if ($post->getEndDate() !== null && $date > $post->getEndDate()) {
            return false;
        }

        $rule = $post->getRecurrence();

        if ($rule->getFrequency() === RecurrenceFrequency::WEEKLY) {
            if (!in_array($isoDay, $rule->getWeekdays(), true)) {
                return false;
            }
            return $this->weekPhaseMatches($date, $rule->getAnchorDate(), $rule->getInterval());
        }

        // MONTHLY
        $interval   = max(1, $rule->getInterval());
        $anchor     = $rule->getAnchorDate();
        $monthsDiff = ((int) $date->format('Y') - (int) $anchor->format('Y')) * 12
            + ((int) $date->format('n') - (int) $anchor->format('n'));
        $mod = $monthsDiff % $interval;
        if ($mod < 0) {
            $mod += $interval;
        }
        if ($mod !== 0) {
            return false;
        }

        if (!in_array($isoDay, $rule->getWeekdays(), true)) {
            return false;
        }

        // nth-occurrence-of-this-weekday-in-the-month: occurrences of a given weekday are
        // always exactly 7 days apart, so ceil(dayOfMonth / 7) is the correct 1-5 index
        // regardless of what weekday the month starts on (this is NOT "calendar week").
        $nth = (int) ceil(((int) $date->format('j')) / 7);
        return in_array($nth, $rule->getMonthWeeks(), true);
    }

    private function weekPhaseMatches(\DateTimeImmutable $date, \DateTimeImmutable $anchor, int $interval): bool
    {
        if ($interval <= 1) {
            return true;
        }

        $dateMonday   = $this->mondayOf($date);
        $anchorMonday = $this->mondayOf($anchor);
        $diff         = $dateMonday->diff($anchorMonday);
        $days         = $diff->days * ($diff->invert ? -1 : 1);
        $weeks        = intdiv($days, 7);

        $mod = $weeks % $interval;
        if ($mod < 0) {
            $mod += $interval;
        }
        return $mod === 0;
    }

    private function mondayOf(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $isoDay = (int) $date->format('N');
        return $isoDay === 1 ? $date : $date->modify('-' . ($isoDay - 1) . ' days');
    }

    private function monthRange(string $month): array
    {
        $start = new \DateTimeImmutable($month . '-01');
        $end   = $start->modify('last day of this month');
        return [$start, $end];
    }

    /** @return int[] */
    private function resolveSiteIds(?int $siteId, ?int $siteGroupId): array
    {
        if ($siteId !== null && $siteGroupId !== null) {
            throw new \InvalidArgumentException('Fournir siteId OU siteGroupId, pas les deux.');
        }
        if ($siteId === null && $siteGroupId === null) {
            throw new \InvalidArgumentException('siteId ou siteGroupId est requis.');
        }

        if ($siteId !== null) {
            return [$siteId];
        }

        // +1 query only in the site-group case.
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(m.site) as siteId
             FROM App\Entity\SiteGroupMembership m
             WHERE m.group = :groupMembersOf'
        )
            ->setParameter('groupMembersOf', $siteGroupId)
            ->getArrayResult();

        return array_map(static fn (array $r) => (int) $r['siteId'], $rows);
    }

    private function hhmm2mins(string $hhmm): int
    {
        [$h, $m] = explode(':', $hhmm);
        return (int) $h * 60 + (int) $m;
    }

    private function displayName(?User $user): string
    {
        if ($user === null) {
            return '';
        }
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }

    /** Returns the next version number for a given (site, period) tuple. */
    private function nextVersionNumber(?int $siteId, \DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('MAX(v.versionNumber)')
            ->from(PlanningVersion::class, 'v')
            ->where('v.periodStart = :from')
            ->andWhere('v.periodEnd = :to')
            ->setParameter('from', $start)
            ->setParameter('to', $end);

        if ($siteId !== null) {
            $qb->andWhere('v.site = :siteId')->setParameter('siteId', $siteId);
        } else {
            $qb->andWhere('v.site IS NULL');
        }

        $max = $qb->getQuery()->getSingleScalarResult();

        return $max !== null ? ((int) $max + 1) : 1;
    }

    // ── Pre-loaders (constant query budget, D-036) ──────────────────────────

    /** @return SurgeonSchedulePost[] */
    private function loadActivePosts(array $siteIds, string $from, string $to, ?int $surgeonId): array
    {
        $dql = 'SELECT p FROM App\Entity\SurgeonSchedulePost p
                WHERE p.active = true
                  AND p.startDate <= :postsTo
                  AND (p.endDate IS NULL OR p.endDate >= :postsFrom)
                  AND p.site IN (:siteIds)';

        if ($surgeonId !== null) {
            $dql .= ' AND p.surgeon = :surgeonId';
        }

        $q = $this->em->createQuery($dql)
            ->setParameter('postsFrom', $from)
            ->setParameter('postsTo', $to)
            ->setParameter('siteIds', $siteIds);

        if ($surgeonId !== null) {
            $q->setParameter('surgeonId', $surgeonId);
        }

        return $q->getResult();
    }

    /** @return array<string, array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> keyed "siteId_period" */
    private function loadShiftPeriodConfigs(array $siteIds): array
    {
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(c.site) as siteId, c.period as period, c.startTime as startTime, c.endTime as endTime
             FROM App\Entity\ShiftPeriodConfig c
             WHERE c.site IN (:shiftConfigSites) AND c.active = true'
        )
            ->setParameter('shiftConfigSites', $siteIds)
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $period = $row['period'] instanceof \App\Enum\ShiftPeriod ? $row['period']->value : $row['period'];
            $start  = $row['startTime'] instanceof \DateTimeInterface ? $row['startTime'] : new \DateTimeImmutable($row['startTime']);
            $end    = $row['endTime'] instanceof \DateTimeInterface ? $row['endTime'] : new \DateTimeImmutable($row['endTime']);
            $map[$row['siteId'] . '_' . $period] = [$start, $end];
        }

        return $map;
    }

    /**
     * Loads every occurrence exception for the given posts — one query regardless of
     * how many exceptions exist. Returns two in-memory indexes:
     *   - byOriginal:   "postId_occurrenceDate" => PlanningOccurrenceException
     *                   (covers CANCELLED/MOVED suppression and TIME_OVERRIDE/INSTRUMENTIST_OVERRIDE)
     *   - byTargetDate: "Y-m-d" => PlanningOccurrenceException[] (MOVED occurrences landing on that date)
     *
     * @param int[] $postIds
     * @return array{0: array<string, PlanningOccurrenceException>, 1: array<string, PlanningOccurrenceException[]>}
     */
    private function loadOccurrenceExceptions(array $postIds): array
    {
        if ($postIds === []) {
            return [[], []];
        }

        $exceptions = $this->em->createQuery(
            'SELECT e FROM App\Entity\PlanningOccurrenceException e
             WHERE e.post IN (:exceptionPostIds)'
        )
            ->setParameter('exceptionPostIds', $postIds)
            ->getResult();

        $byOriginal   = [];
        $byTargetDate = [];

        foreach ($exceptions as $exception) {
            /** @var PlanningOccurrenceException $exception */
            $byOriginal[$exception->getPost()->getId() . '_' . $exception->getOccurrenceDate()->format('Y-m-d')] = $exception;

            if ($exception->getType() === OccurrenceExceptionType::MOVED && $exception->getOverrideDate() !== null) {
                $byTargetDate[$exception->getOverrideDate()->format('Y-m-d')][] = $exception;
            }
        }

        return [$byOriginal, $byTargetDate];
    }

    /** @return array<int, list<array{0: string, 1: string}>> */
    private function loadAbsencesMap(string $from, string $to): array
    {
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(a.user) as userId, a.dateStart as dateStart, a.dateEnd as dateEnd
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
            $start  = $row['dateStart'] instanceof \DateTimeInterface ? $row['dateStart']->format('Y-m-d') : (string) $row['dateStart'];
            $end    = $row['dateEnd'] instanceof \DateTimeInterface ? $row['dateEnd']->format('Y-m-d') : (string) $row['dateEnd'];
            $map[$userId][] = [$start, $end];
        }

        return $map;
    }

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

    /** @return array<int, Mission[]> [instrumentistId => Mission[]] */
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

    private function hasConflictFast(int $instrumentistId, \DateTimeImmutable $slotStart, \DateTimeImmutable $slotEnd, array $missionsByInstrumentist): bool
    {
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

    /** @return array<string, Mission[]> keyed "{surgeonId}_{siteId}_{YYYY-MM-DD}" */
    private function loadExistingMissionsPool(string $from, string $to, array $siteIds): array
    {
        $fromDt = (new \DateTimeImmutable($from))->setTime(0, 0, 0);
        $toDt   = (new \DateTimeImmutable($to))->setTime(23, 59, 59);

        $q = $this->em->createQuery(
            'SELECT m FROM App\Entity\Mission m
             WHERE m.startAt >= :poolFrom
               AND m.startAt <= :poolTo
               AND m.status NOT IN (:excluded)
               AND m.site IN (:poolSiteIds)'
        )
            ->setParameter('poolFrom', $fromDt, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setParameter('poolTo', $toDt, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setParameter('excluded', [MissionStatus::REJECTED])
            ->setParameter('poolSiteIds', $siteIds);

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
     * Same multi-room claim priority as V1 (D-035): exact instrumentist match first,
     * then any unclaimed mission within ±30 minutes of the post's start time.
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

        $nearby = array_filter($candidates, function (Mission $m) use ($slotStartMins) {
            $mMins = (int) $m->getStartAt()->format('H') * 60 + (int) $m->getStartAt()->format('i');
            return abs($mMins - $slotStartMins) <= 30;
        });

        foreach ($nearby as $mission) {
            if (isset($claimedIds[$mission->getId()])) {
                continue;
            }
            if ($mission->getInstrumentist()?->getId() === $slotInstId) {
                $claimedIds[$mission->getId()] = true;
                return $mission;
            }
        }

        foreach ($nearby as $mission) {
            if (isset($claimedIds[$mission->getId()])) {
                continue;
            }
            $claimedIds[$mission->getId()] = true;
            return $mission;
        }

        return null;
    }

    // ── Second pass: freed-instrumentist auto-assignment (D-034, unchanged conceptually) ──

    private function resolveFreedInstrumentists(array $lines): array
    {
        $freedByDate = [];
        foreach ($lines as $line) {
            if ($line['status'] === 'SKIPPED' && $line['instrumentistId'] !== null) {
                $freedByDate[$line['date']][$line['instrumentistId']] = $line['instrumentistName'];
            }
        }
        foreach ($lines as $line) {
            if ($line['status'] !== 'SKIPPED' && $line['instrumentistId'] !== null) {
                unset($freedByDate[$line['date']][$line['instrumentistId']]);
            }
        }
        if (empty($freedByDate)) {
            return $lines;
        }

        $secondPassAssignments = [];

        foreach ($lines as &$line) {
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
                $hasOverlap = false;
                foreach ($lines as $other) {
                    if (
                        $other['date'] === $date
                        && $other['instrumentistId'] === $instId
                        && $other['status'] !== 'SKIPPED'
                        && $this->hhmm2mins($other['startTime']) < $lineEnd
                        && $this->hhmm2mins($other['endTime']) > $lineStart
                    ) {
                        $hasOverlap = true;
                        break;
                    }
                }

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
                    $secondPassAssignments[$instId][] = [$date, $lineStart, $lineEnd];
                    break;
                }
            }
        }
        unset($line);

        return $lines;
    }

    /**
     * @return array{
     *   date: string, postId: int, surgeonId: int, surgeonName: string,
     *   missionType: string, startTime: string, endTime: string,
     *   siteId: int|null, siteName: string|null,
     *   instrumentistId: int|null, instrumentistName: string|null,
     *   status: string, existingMissionId: int|null,
     *   existingInstrumentistId: int|null, existingInstrumentistName: string|null,
     *   freedFrom: bool
     * }
     */
    private function buildLine(
        \DateTimeImmutable $day,
        SurgeonSchedulePost $post,
        ?Hospital $site,
        string $surgeonName,
        \DateTimeImmutable $startTime,
        \DateTimeImmutable $endTime,
        ?User $instrumentist,
        string $status,
        ?int $existingMissionId,
        ?User $existingInstrumentist = null,
    ): array {
        return [
            'date'                      => $day->format('Y-m-d'),
            'postId'                    => $post->getId(),
            'surgeonId'                 => $post->getSurgeon()->getId(),
            'surgeonName'               => $surgeonName,
            'missionType'               => $post->getType()->value,
            'startTime'                 => $startTime->format('H:i'),
            'endTime'                   => $endTime->format('H:i'),
            'siteId'                    => $site?->getId(),
            'siteName'                  => $site?->getName(),
            'instrumentistId'           => $instrumentist?->getId(),
            'instrumentistName'         => $this->displayName($instrumentist),
            'status'                    => $status,
            'existingMissionId'         => $existingMissionId,
            'existingInstrumentistId'   => $existingInstrumentist?->getId(),
            'existingInstrumentistName' => $existingInstrumentist !== null ? $this->displayName($existingInstrumentist) : null,
            'freedFrom'                 => false,
        ];
    }
}
