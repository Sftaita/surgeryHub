<?php

namespace App\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Planning V2 Modification mode — applies a batch of editor-staged line changes to an
 * already-deployed PlanningVersion in one request, then sends exactly one targeted,
 * diff-based "what changed" email per actually-affected person (never a global resend).
 *
 * Deliberately reuses the existing granular Mission mutation methods
 * (MissionPostDeployService::release/cancel/assign/reassign/updateSchedule/createPostDeploy)
 * with $notify=false — the individual per-action notification is skipped here in favour of
 * one consolidated summary per recipient, computed from a before/after snapshot diff via
 * PlanningDiffService::computeDiffFromSnapshots() and sent through the existing, previously
 * unwired PlanningChangeSummaryService.
 */
class PlanningModificationService
{
    public function __construct(
        private readonly \Doctrine\ORM\EntityManagerInterface $em,
        private readonly MissionPostDeployService             $postDeploy,
        private readonly PlanningDiffService                  $diffService,
        private readonly PlanningChangeSummaryService          $changeSummary,
    ) {}

    /**
     * @param array<int,array<string,mixed>> $lines Editor lines (same shape as PreviewLineV2 —
     *        date, postId, surgeonId, missionType, startTime, endTime, siteId, instrumentistId,
     *        status, existingMissionId). existingMissionId null = a newly-added line.
     * @return array{created:int,updated:int,cancelled:int,released:int,unchanged:int}
     */
    public function apply(PlanningVersion $version, array $lines, User $actor): array
    {
        // ── Snapshot every mission currently in this version, before any mutation ──────
        /** @var Mission[] $allMissionsBefore */
        $allMissionsBefore = $version->getMissions()->filter(
            fn (Mission $m) => $m->getStatus() !== MissionStatus::REJECTED,
        )->toArray();

        $beforeById = [];
        foreach ($allMissionsBefore as $m) {
            $beforeById[$m->getId()] = $this->diffService->serializeMission($m);
        }

        $counts = ['created' => 0, 'updated' => 0, 'cancelled' => 0, 'released' => 0, 'unchanged' => 0];
        $touchedMissionIds = [];

        foreach ($lines as $line) {
            $existingMissionId = $line['existingMissionId'] ?? null;

            if ($existingMissionId === null) {
                // New mission added in Modification mode.
                $mission = $this->createFromLine($version, $line, $actor);
                $touchedMissionIds[$mission->getId()] = true;
                $counts['created']++;
                continue;
            }

            $mission = $this->em->find(Mission::class, $existingMissionId);
            if ($mission === null || $mission->getPlanningVersion()?->getId() !== $version->getId()) {
                continue; // stale/foreign reference — silently skip rather than fail the whole batch
            }

            $changed = $this->applyLineToMission($mission, $line, $actor);
            if ($changed) {
                $touchedMissionIds[$mission->getId()] = true;
                if ($mission->getStatus() === MissionStatus::CANCELLED) {
                    $counts['cancelled']++;
                } elseif ($mission->getStatus() === MissionStatus::OPEN) {
                    $counts['released']++;
                } else {
                    $counts['updated']++;
                }
            } else {
                $counts['unchanged']++;
            }
        }

        $this->em->flush();

        // ── Snapshot again after mutation, diff, and notify only who's actually affected ──
        $afterById = $beforeById;
        foreach (array_keys($touchedMissionIds) as $missionId) {
            $mission = $this->em->find(Mission::class, $missionId);
            $afterById[$missionId] = $mission !== null && $mission->getStatus() !== MissionStatus::REJECTED
                ? $this->diffService->serializeMission($mission)
                : null; // deleted/rejected — treated as removed below
        }
        $afterById = array_filter($afterById, fn ($v) => $v !== null);

        $diff = $this->diffService->computeDiffFromSnapshots(
            array_values($beforeById),
            array_values($afterById),
        );

        if (!empty($diff['added']) || !empty($diff['removed']) || !empty($diff['modified'])) {
            $this->dispatchTargetedNotifications($version, $diff, $actor);
        }

        return $counts;
    }

    // ── Private — per-line application ──────────────────────────────────────────────

    /**
     * Applies one editor line's intent to an existing Mission. Returns true if anything
     * actually changed (i.e. a mutation call was made), false for a genuine no-op.
     */
    private function applyLineToMission(Mission $mission, array $line, User $actor): bool
    {
        $wantsCancelled = ($line['status'] ?? null) === 'SKIPPED';

        if ($wantsCancelled) {
            if ($mission->getStatus() === MissionStatus::CANCELLED) {
                return false;
            }
            if ($mission->getStatus() === MissionStatus::ASSIGNED) {
                $this->postDeploy->release($mission, $actor, notify: false);
            }
            if ($mission->getStatus() === MissionStatus::OPEN) {
                $this->postDeploy->cancel($mission, $actor, notify: false);
            }
            return true;
        }

        $changed = false;

        // Instrumentist assignment change — $changed only flips when a mutation actually
        // ran. A mismatched instrumentistId on a mission whose status allows none of the
        // three transitions (e.g. CLOSED, SUBMITTED, VALIDATED, IN_PROGRESS — stale frontend
        // data or a concurrent change) must NOT be reported as a change: nothing happened.
        $newInstrumentistId = $line['instrumentistId'] ?? null;
        $currentInstrumentistId = $mission->getInstrumentist()?->getId();
        if ($newInstrumentistId !== $currentInstrumentistId) {
            if ($newInstrumentistId === null && $mission->getStatus() === MissionStatus::ASSIGNED) {
                $this->postDeploy->release($mission, $actor, notify: false);
                $changed = true;
            } elseif ($newInstrumentistId !== null && $mission->getStatus() === MissionStatus::OPEN) {
                $this->postDeploy->assign($mission, $actor, (int) $newInstrumentistId, notify: false);
                $changed = true;
            } elseif ($newInstrumentistId !== null && $mission->getStatus() === MissionStatus::ASSIGNED) {
                $this->postDeploy->reassign($mission, $actor, (int) $newInstrumentistId, notify: false);
                $changed = true;
            }
        }

        // Schedule / site / type change — compare against the mission's *current* state
        // (re-fetched, since the block above may already have mutated status/instrumentist).
        $newStartAt = $this->combineDateTime($line['date'] ?? null, $line['startTime'] ?? null);
        $newEndAt   = $this->combineDateTime($line['date'] ?? null, $line['endTime'] ?? null);
        $newSite    = isset($line['siteId']) ? $this->em->find(Hospital::class, $line['siteId']) : null;
        $newType    = isset($line['missionType']) ? MissionType::tryFrom((string) $line['missionType']) : null;

        $scheduleChanged =
            ($newStartAt !== null && $newStartAt != $mission->getStartAt())
            || ($newEndAt !== null && $newEndAt != $mission->getEndAt())
            || ($newSite !== null && $newSite->getId() !== $mission->getSite()?->getId())
            || ($newType !== null && $newType !== $mission->getType());

        if ($scheduleChanged && in_array($mission->getStatus(), [MissionStatus::OPEN, MissionStatus::ASSIGNED], true)) {
            $this->postDeploy->updateSchedule($mission, $actor, $newStartAt, $newEndAt, $newSite, $newType, notify: false);
            $changed = true;
        }

        return $changed;
    }

    private function createFromLine(PlanningVersion $version, array $line, User $actor): Mission
    {
        $site = $this->em->find(Hospital::class, $line['siteId'] ?? null)
            ?? throw new NotFoundHttpException('Site not found for new mission line');
        $surgeon = $this->em->find(User::class, $line['surgeonId'] ?? null)
            ?? throw new NotFoundHttpException('Surgeon not found for new mission line');
        $instrumentist = isset($line['instrumentistId']) && $line['instrumentistId'] !== null
            ? $this->em->find(User::class, $line['instrumentistId'])
            : null;
        $type = MissionType::tryFrom((string) ($line['missionType'] ?? ''))
            ?? throw new UnprocessableEntityHttpException('Invalid missionType for new mission line');
        $startAt = $this->combineDateTime($line['date'] ?? null, $line['startTime'] ?? null)
            ?? throw new UnprocessableEntityHttpException('Invalid date/startTime for new mission line');
        $endAt = $this->combineDateTime($line['date'] ?? null, $line['endTime'] ?? null)
            ?? throw new UnprocessableEntityHttpException('Invalid date/endTime for new mission line');

        return $this->postDeploy->createPostDeploy(
            $version, $actor, $site, $surgeon, $instrumentist, $type, $startAt, $endAt, notify: false,
        );
    }

    private function combineDateTime(?string $date, ?string $time): ?\DateTimeImmutable
    {
        if ($date === null || $time === null) {
            return null;
        }
        try {
            return new \DateTimeImmutable("{$date}T{$time}:00");
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Private — targeted notification dispatch ────────────────────────────────────

    private function dispatchTargetedNotifications(PlanningVersion $version, array $diff, User $actor): void
    {
        // Re-queried fresh from the DB rather than reusing $version->getMissions() — that
        // Doctrine collection may already be hydrated from earlier in this same request (e.g.
        // apply()'s very first line, before any mutation), so a Mission created moments ago by
        // createFromLine()/createPostDeploy() would be invisible in it without a fresh query.
        /** @var Mission[] $allMissions */
        $allMissions = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Mission::class, 'm')
            ->where('m.planningVersion = :version')
            ->andWhere('m.status != :rejected')
            ->setParameter('version', $version)
            ->setParameter('rejected', MissionStatus::REJECTED)
            ->getQuery()
            ->getResult();

        $byInstrumentist  = [];
        $bySurgeon        = [];
        $openUncoveredIds = [];

        foreach ($allMissions as $mission) {
            if ($mission->getInstrumentist() !== null) {
                $byInstrumentist[$mission->getInstrumentist()->getId()][] = $mission;
            }
            if ($mission->getSurgeon() !== null) {
                $bySurgeon[$mission->getSurgeon()->getId()][] = $mission;
            }
            if ($mission->getStatus() === MissionStatus::OPEN) {
                $openUncoveredIds[] = $mission->getId();
            }
        }

        // Seed candidates who no longer have ANY current mission in this version but are still
        // referenced by the diff — e.g. an instrumentist whose only mission was just reassigned/
        // released away from them, or a surgeon/instrumentist whose only mission was just
        // cancelled. Without this, PlanningChangeSummaryService::sendChangeSummaryEmails() (which
        // iterates array_keys($byInstrumentist)/array_keys($bySurgeon)) would never even consider
        // them as a candidate, so they could never receive their "you lost this mission" email —
        // regardless of how correctly the diff itself identifies the change.
        foreach (['added', 'removed'] as $key) {
            foreach ($diff[$key] as $m) {
                $iid = $m['instrumentistId'] ?? null;
                if ($iid !== null && !isset($byInstrumentist[$iid])) {
                    $byInstrumentist[$iid] = [];
                }
                $sid = $m['surgeonId'] ?? null;
                if ($sid !== null && !isset($bySurgeon[$sid])) {
                    $bySurgeon[$sid] = [];
                }
            }
        }
        foreach ($diff['modified'] as $entry) {
            foreach (['from', 'to'] as $direction) {
                $iid = $entry['changes']['instrumentist'][$direction]['id'] ?? null;
                if ($iid !== null && !isset($byInstrumentist[$iid])) {
                    $byInstrumentist[$iid] = [];
                }
            }
            $sid = $entry['mission']['surgeonId'] ?? null;
            if ($sid !== null && !isset($bySurgeon[$sid])) {
                $bySurgeon[$sid] = [];
            }
        }

        $this->changeSummary->sendChangeSummaryEmails(
            versionId: $version->getId(),
            missions: $allMissions,
            byInstrumentist: $byInstrumentist,
            bySurgeon: $bySurgeon,
            openUncoveredIds: $openUncoveredIds,
            globalPdf: null,
            globalFilename: null,
            fromDate: $version->getPeriodStart(),
            toDate: $version->getPeriodEnd(),
            precomputedDiff: $diff,
        );
    }
}
