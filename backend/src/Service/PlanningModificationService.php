<?php

namespace App\Service;

use App\Message\PlanningModificationAppliedMessage;
use App\Notification\OpenEpisode;
use App\Enum\OpenEpisodeCause;
use App\Enum\OpenPoolExclusionReason;
use App\Activity\Recorder\PlanningActivityRecorder;
use App\Enum\ActivityOutcome;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\EligibilityEnforcementPolicy;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningVersionStatus;
use App\Exception\PlanningVersionNotActiveException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
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
        private readonly PlanningConflictDetectionService      $conflictDetection,
        private readonly AbsenceMissionReactionService         $absenceMissionReaction,
        private readonly PlanningVersionOperationalScope       $operationalScope,
        private readonly PlanningActivityRecorder              $activity,
        private readonly \Symfony\Component\Messenger\MessageBusInterface $bus,
        private readonly \App\Activity\CorrelationContext       $correlation,
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
        // CAS C (D-116) — a stale Modification-mode session (opened while $version was
        // ACTIVE, since archived by a concurrent redeploy for the same site/period — see
        // PlanningDeploymentService::deploy()) must never silently attach/mutate missions
        // on a version nobody is looking at anymore. Every published-planning view resolves
        // to the CURRENT ACTIVE version, so anything written here would be invisible from
        // that point on. Checked first, before any snapshot/mutation.
        if ($version->getStatus() !== PlanningVersionStatus::ACTIVE) {
            throw new PlanningVersionNotActiveException(sprintf(
                'This planning version is %s, not the active one — reload the page to continue editing the current published planning.',
                $version->getStatus()->value,
            ));
        }

        // ── Snapshot every mission currently in this version's operational scope ───────
        // D-125 — the calendar the manager edits shows every live Mission of the period/
        // sites (PlanningVersionOperationalScope), not only the ones this version's
        // generate() produced; the before/after diff must cover exactly the same set, or an
        // edit on a manually added / accepted-request Mission would never reach its people.
        /** @var Mission[] $allMissionsBefore */
        $allMissionsBefore = $this->operationalScope->missions($version);

        $beforeById = [];
        foreach ($allMissionsBefore as $m) {
            $beforeById[$m->getId()] = $this->diffService->serializeMission($m);
        }

        $counts = ['created' => 0, 'updated' => 0, 'cancelled' => 0, 'released' => 0, 'unchanged' => 0];
        $touchedMissionIds = [];

        // H1 (D-126) — finer-grained, journal-only bookkeeping of what the batch actually
        // did (the API's $counts above are unchanged). Filled by applyLineToMission().
        $detail = [
            'assigned' => 0, 'reassigned' => 0, 'released' => 0, 'cancelled' => 0,
            'scheduleChanged' => 0, 'absenceReconciled' => 0, 'skippedForeign' => 0,
        ];

        // H3 (D-129) — side effects are emitted only for a VALIDATED batch.
        //
        // The whole batch now runs in ONE database transaction: every line's mutation (each
        // MissionPostDeployService call still flushes, now as part of this transaction), the
        // conflict-alert re-sync, the diff and the PLANNING_MODIFICATION_APPLIED trace either
        // all commit or all roll back. Messages a nested service dispatches during the batch
        // (e.g. an absence reconciliation) go to the Doctrine transport on the same
        // connection, so they are rolled back with it too. Formerly each line committed on
        // its own and a failure left a PARTIAL batch whose earlier lines were already live.
        //
        // Every notification of the batch — the per-person recap (PLANNING_CHANGE_SUMMARY)
        // and the pool for the missions it opened (OpenMissionPoolNotifier, BATCH: one email
        // per recipient) — is produced asynchronously by PlanningModificationAppliedMessage,
        // dispatched only AFTER the commit (R-07). A failed batch notifies nobody.
        $removedBy   = []; // missionId => instrumentist a manager removed in this batch
        $createdIds  = []; // missions created by this batch
        $connection  = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $this->applyLines($version, $lines, $actor, $counts, $touchedMissionIds, $detail, $removedBy, $createdIds);
            $this->em->flush();

            // ── Snapshot again after mutation, diff ─────────────────────────────────
            $afterById = $beforeById;
            $openedEpisodes = [];
            $batchKey = $this->correlation->correlationId();
            foreach (array_keys($touchedMissionIds) as $missionId) {
                $mission = $this->em->find(Mission::class, $missionId);
                $afterById[$missionId] = $mission !== null && $mission->getStatus() !== MissionStatus::REJECTED
                    ? $this->diffService->serializeMission($mission)
                    : null; // deleted/rejected — treated as removed below

                // D-091 — a manual reassignment/reschedule can create (or resolve) a cross-site
                // conflict; re-sync SURGEON_CONFLICT/INSTRUMENTIST_CONFLICT alerts for every
                // touched mission so the Alertes tab reflects reality immediately. Deliberately
                // non-blocking here (unlike deploy()): the alert makes the conflict visible for
                // manager review, it does not reject the save itself. D-167: a mission cancelled
                // by this batch is synced too — it raises nothing, but the conflict alerts it took
                // part in are re-evaluated (and resolved) instead of outliving it.
                if ($mission !== null && $mission->getStatus() !== MissionStatus::REJECTED) {
                    $this->conflictDetection->syncAlertsForMission($mission);
                }

                // H3 (D-129) — the batch's real openings: a mission this batch released (a
                // manager removed its instrumentist) or created without instrumentist, and
                // that is still OPEN and uncovered at the end of the batch. The removed
                // instrumentist is contextually excluded from her own mission's pool episode.
                if ($mission !== null && $mission->getStatus() === MissionStatus::OPEN && $mission->getInstrumentist() === null
                    && (isset($removedBy[$missionId]) || isset($createdIds[$missionId]))) {
                    $openedEpisodes[] = OpenEpisode::forBatch(
                        $missionId,
                        OpenEpisodeCause::PLANNING_MODIFICATION,
                        $batchKey,
                        isset($removedBy[$missionId]) ? [$removedBy[$missionId] => OpenPoolExclusionReason::REMOVED_BY_MANAGER] : [],
                        $actor->getId(),
                    );
                }
            }
            $afterById = array_filter($afterById, fn ($v) => $v !== null);

            // D-090: matched by missionId (computeDiffByMissionId), never the composite slot-key
            // matching of computeDiffFromSnapshots()/diff() — this is the SAME version's own
            // mission set before/after an edit, not two different versions' distinct rows.
            $diff = $this->diffService->computeDiffByMissionId($beforeById, $afterById);

            // D-090 — the manager-facing "what actually happened" numbers: one functional
            // change per added/removed/modified mission in the diff, and the people the
            // recap will inform (computed now, sent after commit).
            $functionalChanges = count($diff['added']) + count($diff['removed']) + count($diff['modified']);
            $recipients = $functionalChanges > 0 ? $this->changeSummary->recipients($diff) : ['instrumentists' => [], 'surgeons' => []];
            $recipientCount = count($recipients['instrumentists']) + count($recipients['surgeons']);

            // H1 (D-126) — the batch's own trace, committed with the batch.
            $this->activity->modificationApplied(
                $version,
                $this->modificationStats($lines, $counts, $detail, $touchedMissionIds) + [
                    'functionalChanges' => $functionalChanges,
                    'usersNotified'     => $recipientCount,
                    'openedCount'       => count($openedEpisodes),
                ],
                ActivityOutcome::SUCCESS,
                $actor,
            );

            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            // Nothing of the batch was kept: report it honestly (own transaction), rethrow.
            $this->activity->modificationApplied(
                $version,
                $this->modificationStats($lines, $counts, $detail, $touchedMissionIds) + [
                    'errorType'   => (new \ReflectionClass($e))->getShortName(),
                    'errorStatus' => $e instanceof HttpExceptionInterface ? $e->getStatusCode() : null,
                    'rolledBack'  => true,
                ],
                ActivityOutcome::FAILED,
                $actor,
                standalone: true,
            );
            // The rolled-back mutations are still in the unit of work: drop them so no later
            // flush (same request, or a long-running worker) can resurrect half a batch.
            $this->em->clear();
            throw $e;
        }

        // ── After commit only: every notification of the batch, asynchronously ───────
        if ($functionalChanges > 0 || $openedEpisodes !== []) {
            $this->bus->dispatch(new PlanningModificationAppliedMessage(
                planningVersionId: (int) $version->getId(),
                batchKey: $batchKey,
                diff: $diff,
                openedEpisodes: array_map(static fn (OpenEpisode $e) => $e->toArray(), $openedEpisodes),
                actorId: (int) $actor->getId(),
            ));
        }

        return $counts + [
            'functionalChanges' => $functionalChanges,
            // People the batch's recap is being sent to (asynchronously, after commit).
            'usersNotified'     => $recipientCount,
            'emailsSent'        => $recipientCount,
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
     *
     * D-165 — a requested change the mission's status does not allow (an instrumentist change
     * or a cancellation on an IN_PROGRESS/SUBMITTED/… mission, a schedule change past
     * IN_PROGRESS) is refused with a 409 — formerly it was silently counted "unchanged" and
     * the manager believed the planning was updated. The whole batch is rolled back.
     *
     * Order inside the line: a removed instrumentist is released BEFORE the schedule change;
     * a new instrumentist is assigned AFTER it, so her eligibility is always evaluated on the
     * slot she will actually hold, never on the mission's previous times.
     */
    /** @param array<string, int> $detail H1 (D-126) journal-only counters, see apply() */
    private function applyLineToMission(Mission $mission, array $line, User $actor, array &$detail, array &$removedBy = []): bool
    {
        $wantsCancelled = ($line['status'] ?? null) === 'SKIPPED';

        if ($wantsCancelled) {
            if ($mission->getStatus() === MissionStatus::CANCELLED) {
                return false;
            }
            if (!in_array($mission->getStatus(), [MissionStatus::OPEN, MissionStatus::ASSIGNED], true)) {
                throw $this->refusal($mission, 'elle ne peut plus être annulée depuis le planning');
            }
            if ($mission->getStatus() === MissionStatus::ASSIGNED) {
                $this->postDeploy->release($mission, $actor, notify: false);
                $detail['released']++;
            }
            if ($mission->getStatus() === MissionStatus::OPEN) {
                $this->postDeploy->cancel($mission, $actor, notify: false);
                $detail['cancelled']++;
            }
            return true;
        }

        $newInstrumentistId     = isset($line['instrumentistId']) ? (int) $line['instrumentistId'] : null;
        $currentInstrumentistId = $mission->getInstrumentist()?->getId();
        $instrumentistChanges   = $newInstrumentistId !== $currentInstrumentistId;
        if ($instrumentistChanges && !in_array($mission->getStatus(), [MissionStatus::OPEN, MissionStatus::ASSIGNED], true)) {
            throw $this->refusal($mission, 'son instrumentiste ne peut plus être modifié(e) depuis le planning');
        }

        $changed = false;
        if ($instrumentistChanges && $newInstrumentistId === null) {
            $changed = $this->applyInstrumentistChange($mission, null, $currentInstrumentistId, $actor, $detail, $removedBy);
        }

        // Schedule / site / type change — compared against the mission's *current* state.
        $newStartAt = $this->combineDateTime($line['date'] ?? null, $line['startTime'] ?? null);
        $newEndAt   = $this->combineDateTime($line['date'] ?? null, $line['endTime'] ?? null);
        $newSite    = isset($line['siteId']) ? $this->em->find(Hospital::class, $line['siteId']) : null;
        $newType    = isset($line['missionType']) ? MissionType::tryFrom((string) $line['missionType']) : null;

        $scheduleChanged =
            ($newStartAt !== null && $newStartAt != $mission->getStartAt())
            || ($newEndAt !== null && $newEndAt != $mission->getEndAt())
            || ($newSite !== null && $newSite->getId() !== $mission->getSite()?->getId())
            || ($newType !== null && $newType !== $mission->getType());

        if ($scheduleChanged) {
            if (!in_array($mission->getStatus(), MissionPostDeployService::RESCHEDULABLE_STATUSES, true)) {
                throw $this->refusal($mission, 'ses horaires ne peuvent plus être modifiés depuis le planning');
            }
            $this->postDeploy->updateSchedule($mission, $actor, $newStartAt, $newEndAt, $newSite, $newType, notify: false, policy: EligibilityEnforcementPolicy::PLANNING_MODIFICATION);
            $detail['scheduleChanged']++;
            $changed = true;
        }

        if ($instrumentistChanges && $newInstrumentistId !== null) {
            $changed = $this->applyInstrumentistChange($mission, $newInstrumentistId, $currentInstrumentistId, $actor, $detail, $removedBy) || $changed;
        }

        return $changed;
    }

    /**
     * Instrumentist assignment change — returns true only when a mutation actually ran.
     *
     * @param array<string, int> $detail
     * @param array<int, int>    $removedBy
     */
    private function applyInstrumentistChange(Mission $mission, ?int $newInstrumentistId, ?int $currentInstrumentistId, User $actor, array &$detail, array &$removedBy): bool
    {
        if ($newInstrumentistId === null) {
            if ($mission->getStatus() !== MissionStatus::ASSIGNED) {
                return false;
            }
            // Backend invariant (never rely solely on the editor sending the right status):
            // if this mission's surgeon is currently absent, releasing must never reopen it
            // to the pool — reuse the exact same reconciliation MissionPostDeployService's
            // callers already trust (D-062/D-106) instead of unconditionally calling
            // release(). Returns null when neither person is currently absent, in which case
            // this really is a normal release-to-pool and proceeds exactly as before.
            if ($this->absenceMissionReaction->reconcileMissionAgainstCurrentAbsences($mission, $actor, notify: false) === null) {
                $removedBy[$mission->getId()] = $currentInstrumentistId;
                $this->postDeploy->release($mission, $actor, notify: false);
                $detail['released']++;
            } else {
                $detail['absenceReconciled']++;
            }
            return true;
        }

        if ($mission->getStatus() === MissionStatus::OPEN) {
            // D-101 — Mode Modification: ABSENT/INACTIVE block, SCHEDULE_CONFLICT does
            // not (D-091/D-052 — surfaced instead via syncAlertsForMission() below).
            $this->postDeploy->assign($mission, $actor, $newInstrumentistId, notify: false, policy: EligibilityEnforcementPolicy::PLANNING_MODIFICATION);
            $detail['assigned']++;
            return true;
        }
        if ($mission->getStatus() === MissionStatus::ASSIGNED) {
            $this->postDeploy->reassign($mission, $actor, $newInstrumentistId, notify: false, policy: EligibilityEnforcementPolicy::PLANNING_MODIFICATION);
            $detail['reassigned']++;
            return true;
        }

        return false;
    }

    /** D-165 — explicit refusal of a change the mission's current status does not allow. */
    private function refusal(Mission $mission, string $what): ConflictHttpException
    {
        $surgeon = $mission->getSurgeon();

        return new ConflictHttpException(sprintf(
            'Mission #%d (%s, %s) — statut « %s » : %s. Rien n\'a été enregistré.',
            $mission->getId(),
            $surgeon !== null ? 'Dr ' . trim(($surgeon->getFirstname() ?? '') . ' ' . ($surgeon->getLastname() ?? '')) : 'sans chirurgien',
            $mission->getStartAt()?->format('d/m/Y') ?? '—',
            $mission->getStatus()?->label() ?? '—',
            $what,
        ));
    }

    /**
     * Applies every editor line of the batch (extracted from apply() so the whole batch can
     * be wrapped in the H1 failure reporting without re-indenting its logic).
     *
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, int>                $counts
     * @param array<int, true>                  $touchedMissionIds
     * @param array<string, int>                $detail
     */
    /**
     * @param array<int, int>  $removedBy  H3 — missionId => instrumentist a manager removed (filled)
     * @param array<int, true> $createdIds H3 — missions created by the batch (filled)
     */
    private function applyLines(PlanningVersion $version, array $lines, User $actor, array &$counts, array &$touchedMissionIds, array &$detail, array &$removedBy, array &$createdIds): void
    {
            foreach ($this->orderForApplication($lines) as $line) {
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
                    $createdIds[$mission->getId()] = true;
                    $counts['created']++;
                    continue;
                }

                $mission = $this->em->find(Mission::class, $existingMissionId);
                // D-125 — "foreign" now means outside the version's operational scope (another
                // period/site, a foreign DRAFT…), never merely "not created by this generate()":
                // a Mission added manually before/after generation is edited like any other,
                // without being re-parented (planningVersion stays its provenance).
                if ($mission === null || !$this->operationalScope->contains($version, $mission)) {
                    $detail['skippedForeign']++;
                    continue; // stale/foreign reference — silently skip rather than fail the whole batch
                }

                $changed = $this->applyLineToMission($mission, $line, $actor, $detail, $removedBy);
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
    }

    /**
     * D-165 — the batch is the manager's whole intended day: an existing mission that gets a
     * NEW instrumentist is applied only after every other line (cancellations, releases,
     * schedule changes), so her eligibility is checked against the batch's final schedules —
     * e.g. Dr A shortened to 07:45–14:00 before she is assigned to Dr B at 14:45–18:00 —
     * never against a slot this same batch is about to shorten. Stable otherwise.
     *
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array<string, mixed>>
     */
    private function orderForApplication(array $lines): array
    {
        $first = [];
        $last  = [];
        foreach ($lines as $line) {
            $missionId = $line['existingMissionId'] ?? null;
            $newId     = isset($line['instrumentistId']) ? (int) $line['instrumentistId'] : null;
            $mission   = $missionId !== null && $newId !== null && ($line['status'] ?? null) !== 'SKIPPED'
                ? $this->em->find(Mission::class, $missionId)
                : null;
            if ($mission !== null && $mission->getInstrumentist()?->getId() !== $newId) {
                $last[] = $line;
            } else {
                $first[] = $line;
            }
        }

        return [...$first, ...$last];
    }

    /**
     * H1 (D-126) — PLANNING_MODIFICATION_APPLIED payload.
     *
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, int>                $counts
     * @param array<string, int>                $detail
     * @param array<int, true>                  $touchedMissionIds
     * @return array<string, int|null>
     */
    private function modificationStats(array $lines, array $counts, array $detail, array $touchedMissionIds): array
    {
        return [
            'requestedLines'   => count($lines),
            'modifiedMissions' => count($touchedMissionIds),
            'created'          => $counts['created'],
            'unchanged'        => $counts['unchanged'],
        ] + $detail;
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
            policy: EligibilityEnforcementPolicy::PLANNING_MODIFICATION,
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
}
