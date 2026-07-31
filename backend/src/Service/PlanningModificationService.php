<?php

namespace App\Service;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
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
     *        The frontend currently sends every line of the month (not just the ones the
     *        manager actually touched) — safe now that combineDateTime() compares real
     *        Europe/Brussels instants (D-089/D-090), since an untouched line's schedule can
     *        no longer register as spuriously "changed". $counts below still reflects the
     *        mutation-level (created/updated/cancelled/released/unchanged) bookkeeping this
     *        method has always returned; functionalChanges/usersNotified/emailsSent are the
     *        separate, diff-derived, manager-facing numbers (D-090 — see PlanningDiffService).
     * @return array{
     *   created:int,updated:int,cancelled:int,released:int,unchanged:int,
     *   functionalChanges:int,usersNotified:int,emailsSent:int,
     * }
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
                // A draft line withdrawn client-side before submission (never persisted) has
                // no "existing" state to compare against — status alone (never assigned by
                // createFromLine()) is the only signal available. Guards against ever
                // creating a mission the user explicitly removed from the batch.
                if (($line['status'] ?? null) === 'SKIPPED') {
                    $counts['unchanged']++;
                    continue;
                }
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

        // D-090: matched by missionId (computeDiffByMissionId), never the composite slot-key
        // matching of computeDiffFromSnapshots()/diff() — this is the SAME version's own
        // mission set before/after an edit, not two different versions' distinct rows. Key-
        // based matching would see a pure schedule edit's changed composite key as "old slot
        // removed, new slot added" (two functional changes for one edit) instead of one
        // `modified` entry.
        $diff = $this->diffService->computeDiffByMissionId($beforeById, $afterById);

        // D-090 — the manager-facing "what actually happened" numbers, distinct from the
        // mutation bookkeeping in $counts above: one functional change per added/removed/
        // modified mission in the diff (never a per-cell-touched or per-line-sent count —
        // the diff already excludes anything that isn't a real planning-visible change, see
        // PlanningDiffService), and the exact number of people the notification pass below
        // will email (never assumed equal — computed from what was actually dispatched).
        $functionalChanges = count($diff['added']) + count($diff['removed']) + count($diff['modified']);
        $notifiedUserIds   = [];

        if ($functionalChanges > 0) {
            $notifiedUserIds = $this->dispatchTargetedNotifications($version, $diff, $actor);
        }

        return $counts + [
            'functionalChanges' => $functionalChanges,
            'usersNotified'     => count($notifiedUserIds),
            'emailsSent'        => count($notifiedUserIds),
        ];
    }

    /**
     * Cancels every currently-cancellable mission in an already-deployed PlanningVersion —
     * "delete this generated month" without touching audit/history (D-055): each mission goes
     * through the exact same release→cancel post-deploy chain as a single SKIPPED line in
     * apply() (never a hard delete), and exactly one consolidated diff-based summary email is
     * sent per actually-affected person, same as any other batch. The PlanningVersion itself
     * is left ACTIVE — a hollow version with every mission CANCELLED is a legitimate, fully
     * auditable end state, not a special status.
     *
     * @return array{created:int,updated:int,cancelled:int,released:int,unchanged:int}
     */
    public function cancelAll(PlanningVersion $version, User $actor): array
    {
        // Only ASSIGNED/OPEN are actually cancellable server-side (applyLineToMission()'s
        // SKIPPED branch only transitions those two) — a mission already past that point
        // (SUBMITTED/VALIDATED/CLOSED/IN_PROGRESS) is left untouched rather than silently
        // miscounted as "cancelled" for a mutation that never happened.
        $missions = $version->getMissions()->filter(
            fn (Mission $m) => in_array($m->getStatus(), [MissionStatus::ASSIGNED, MissionStatus::OPEN], true),
        );

        $lines = [];
        foreach ($missions as $mission) {
            $lines[] = ['existingMissionId' => $mission->getId(), 'status' => 'SKIPPED'];
        }

        return $this->apply($version, $lines, $actor);
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

    /**
     * D-066: the incoming $date/$time strings are business (Europe/Brussels) wall-clock
     * digits from the editor — exactly like PlanningGeneratorServiceV2::generate() (see
     * its $day construction). Constructing a naive `new \DateTimeImmutable("...")` here
     * would silently label those digits with PHP's container default timezone (UTC in
     * every environment this app runs in — verified, no `date.timezone` override anywhere
     * in dev or prod images), which is a *different instant* from the same digits read
     * back via Mission::getStartAt()/getEndAt() (always Brussels-labeled by
     * BusinessDateTimeImmutableType). That mismatch made every mission in a Modification-
     * mode batch compare as "schedule changed" (a full DST-offset apart) even when the
     * manager never touched it, and — far worse — made updateSchedule() persist the
     * DST-shifted instant, corrupting the stored wall-clock time. See D-066 in
     * docs/decisions.md and the audit note above apply().
     */
    private function combineDateTime(?string $date, ?string $time): ?\DateTimeImmutable
    {
        if ($date === null || $time === null) {
            return null;
        }
        try {
            return new \DateTimeImmutable(
                "{$date}T{$time}:00",
                new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Private — targeted notification dispatch ────────────────────────────────────

    /** @return int[] distinct user IDs actually notified — see sendChangeSummaryEmails(). */
    private function dispatchTargetedNotifications(PlanningVersion $version, array $diff, User $actor): array
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

        return $this->changeSummary->sendChangeSummaryEmails(
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
