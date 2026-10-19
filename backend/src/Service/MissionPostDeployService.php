<?php

namespace App\Service;

use App\Message\OpenMissionPoolMessage;
use App\Notification\MissionCommunicationService;
use App\Notification\MissionCommunicationSpec;
use App\Enum\MissionCommunicationSource;
use App\Enum\MissionCommunicationWithdrawal;
use App\Notification\OpenEpisode;
use App\Enum\OpenEpisodeCause;
use App\Dto\EligibilityResult;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionClaim;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\EligibilityEnforcementPolicy;
use App\Enum\EligibilityReason;
use App\Enum\IncompatibilityOrigin;
use App\Enum\MissionAssignmentEndReason;
use App\Enum\MissionAssignmentRefusalScope;
use App\Enum\MissionAssignmentSource;
use App\Enum\MissionChangeType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PublicationScope;
use App\Enum\SchedulePrecision;
use App\Exception\AssignmentRefusalException;
use App\Exception\InstrumentistIneligibleException;
use App\Exception\MissionClaimIneligibleException;
use App\Message\MissionLifecycleChangedMessage;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Clock\Clock;

/**
 * Application service for all post-deploy Mission mutations (R-04).
 *
 * Every controller or handler that needs to mutate a deployed Mission status
 * MUST go through this service — never persist directly in a controller (R-09).
 *
 * Invariants enforced here (not in controllers):
 *   - Status guard before mutation (409 if invalid transition)
 *   - AuditEvent created and flushed before dispatch (R-05)
 *   - MissionLifecycleChangedMessage dispatched after every flush (R-07)
 */
class MissionPostDeployService
{
    /** D-151 — `origin` of a RELEASED lifecycle message caused by the surgeon's refusal. */
    public const ORIGIN_SURGEON_REFUSAL = 'SURGEON_REFUSAL';

    /** D-150 — sole writer of the instrumentist of a published mission and of its affectation history. */
    private readonly MissionAssignmentService $assignments;

    public function __construct(
        private readonly EntityManagerInterface    $em,
        private readonly MessageBusInterface       $bus,
        private readonly AuditService              $audit,
        private readonly MissionEligibilityService $eligibilityService,
        // H4 (D-130) — every mutation that takes a person off a Mission closes what had been
        // communicated to her about it (mission_communication). Optional only so plain unit
        // tests can build the service; always autowired.
        private readonly ?MissionCommunicationService $communications = null,
        ?MissionAssignmentService $assignments = null,
        // D-152 — durable refusal ("refuser et bloquer"); always autowired.
        private readonly ?SurgeonInstrumentistIncompatibilityService $incompatibilities = null,
    ) {
        // Always autowired; a plain unit test that omits it still gets the real writer (built
        // on the same EntityManager), never a shortcut that would skip the history.
        $this->assignments = $assignments ?? new MissionAssignmentService($em);
    }

    /**
     * ASSIGNED → OPEN.
     * Releases the current instrumentist back to the pool.
     * Throws 409 if mission is not ASSIGNED.
     *
     * $notify=false skips the individual MissionLifecycleChangedMessage dispatch (status
     * guard, mutation, audit event and flush still happen) — used by batch callers (e.g.
     * Planning V2 Modification mode's apply-modifications, or AbsenceMissionReactionService
     * which dispatches its own consolidated recap message after the loop instead of one
     * MissionLifecycleChangedMessage per mission) that consolidate many mutations into one
     * targeted summary notification instead of one email per action.
     *
     * $reason — free-text audit context (e.g. "Absence instrumentiste enregistrée"). Purely
     * informational, stored in the AuditEvent payload; does not change the transition itself.
     *
     * $causedByAbsenceId — set only by AbsenceMissionReactionService (D-104, Lot 4): the
     * structured (not free-text) id of the Absence that triggered this release, so a later
     * reconciliation can verify "is the mission's most recent AuditEvent still exactly this
     * absence's own reaction?" before ever attempting an automatic restore. previousStatus is
     * always ASSIGNED here (enforced by the guard above) but is still stored explicitly for a
     * uniform payload shape with cancel().
     */
    public function release(Mission $mission, User $actor, bool $notify = true, ?string $reason = null, ?int $causedByAbsenceId = null): void
    {
        // D-150 — under the mission row lock, re-checked against the committed state.
        $payload = $this->assignments->inLockedChange($mission, function () use ($mission, $actor, $reason, $causedByAbsenceId): array {
            if ($mission->getStatus() !== MissionStatus::ASSIGNED) {
                throw new ConflictHttpException('Mission must be ASSIGNED to release');
            }

            $previousStatusEnum = $mission->getStatus();
            $previousStatus     = $previousStatusEnum->value;

            $fromInstrumentist     = $mission->getInstrumentist();
            $fromInstrumentistId   = $fromInstrumentist?->getId();
            $fromInstrumentistName = $fromInstrumentist !== null
                ? $this->displayName($fromInstrumentist)
                : null;

            $mission->setStatus(MissionStatus::OPEN);
            $this->assignments->unassign($mission, MissionAssignmentEndReason::RELEASED, $actor);
            $this->resetEscalationIfLeavingOpen($mission, $previousStatusEnum);
            $this->withdrawCommunication($mission, $fromInstrumentist, MissionCommunicationWithdrawal::RELEASED, MissionCommunicationSpec::ROLE_INSTRUMENTIST);

            $payload = [
                'fromInstrumentistId'   => $fromInstrumentistId,
                'fromInstrumentistName' => $fromInstrumentistName,
                'previousStatus'        => $previousStatus,
                'causedByAbsenceId'     => $causedByAbsenceId,
                'reason'                => $reason,
                'actorId'               => $actor->getId(),
                'actorName'             => $this->displayName($actor),
            ];

            $this->audit->record($mission, $actor, AuditEventType::MISSION_RELEASED_TO_POOL, $payload);

            $this->em->flush();  // R-05: flush before dispatch

            return $payload;
        });

        if (!$notify) {
            return;
        }

        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId:  $mission->getId(),
            changeType: MissionChangeType::RELEASED,
            actorId:    $actor->getId(),
            payload:    $payload,
            occurredAt: new \DateTimeImmutable(),
        ));
    }

    /**
     * D-151 — ASSIGNED → OPEN: the mission's surgeon refuses the instrumentist who accepted
     * it, within the refusal window (MissionAssignmentRefusalPolicy). Only the affectation is
     * undone — never the mission: it goes back to the pool exactly like a release, and the
     * refused instrumentist can never take it back (EligibilityReason::REFUSED_FOR_MISSION,
     * read from this affectation's end reason on every eligibility path).
     *
     * $assignmentId is the affectation the surgeon saw: refusing it after the mission was
     * given to someone else is ASSIGNMENT_SUPERSEDED, never a refusal of the new person. Under
     * the mission row lock (inLockedChange()), so a double click, a concurrent manager change
     * or a second refusal gets a 409 instead of a second unassignment.
     *
     * The lifecycle message is RELEASED with origin SURGEON_REFUSAL: the instrumentist only
     * ever receives the neutral "affectation not maintained" wording; the managers are told
     * the real origin (ASSIGNMENT_REFUSED_BY_SURGEON); the surgeon gets nothing (he acted).
     */
    public function refuseAssignment(Mission $mission, User $surgeon, int $assignmentId, MissionAssignmentRefusalScope $scope): void
    {
        if ($scope === MissionAssignmentRefusalScope::DURABLE && $this->incompatibilities === null) {
            throw new \LogicException('A durable refusal needs SurgeonInstrumentistIncompatibilityService.');
        }

        $raisedConflicts = [];
        $payload = $this->assignments->inLockedChange($mission, function () use ($mission, $surgeon, $assignmentId, $scope, &$raisedConflicts): array {
            $current = $this->assignments->findCurrent($mission);
            if ($current === null) {
                throw new AssignmentRefusalException(AssignmentRefusalException::ASSIGNMENT_ALREADY_ENDED);
            }
            if ($current->getId() !== $assignmentId) {
                throw new AssignmentRefusalException(AssignmentRefusalException::ASSIGNMENT_SUPERSEDED);
            }

            $blocked = MissionAssignmentRefusalPolicy::blockedReason($mission, $current, Clock::get()->now());
            if ($blocked !== null) {
                throw new AssignmentRefusalException($blocked);
            }

            $previousStatusEnum    = $mission->getStatus();
            $fromInstrumentist     = $mission->getInstrumentist();
            $fromInstrumentistName = $fromInstrumentist !== null ? $this->displayName($fromInstrumentist) : null;

            $mission->setStatus(MissionStatus::OPEN);
            $this->assignments->unassign($mission, MissionAssignmentEndReason::REFUSED_BY_SURGEON, $surgeon);
            $current->recordRefusalScope($scope);
            $this->resetEscalationIfLeavingOpen($mission, $previousStatusEnum);
            $this->withdrawCommunication($mission, $fromInstrumentist, MissionCommunicationWithdrawal::RELEASED, MissionCommunicationSpec::ROLE_INSTRUMENTIST);

            // D-152 — « Refuser et bloquer pour mes futures missions »: same refusal, plus an
            // active incompatibility (unless one already exists), in the same transaction. Its
            // other future missions with her become conflicts for the managers.
            $incompatibilityId = null;
            if ($scope === MissionAssignmentRefusalScope::DURABLE && $fromInstrumentist !== null) {
                $existing = $this->incompatibilities->findActive($surgeon, $fromInstrumentist);
                if ($existing !== null) {
                    $incompatibilityId = $existing->getId();
                } else {
                    [$incompatibility, $raisedConflicts] = $this->incompatibilities->createInTransaction(
                        $surgeon, $fromInstrumentist, IncompatibilityOrigin::SURGEON_REFUSAL, $surgeon, $current,
                    );
                    $incompatibilityId = $incompatibility->getId();
                }
            }

            $payload = [
                'origin'                => self::ORIGIN_SURGEON_REFUSAL,
                'assignmentId'          => $assignmentId,
                'refusalScope'          => $scope->value,
                'assignmentSource'      => $current->getSource()->value,
                'assignedAt'            => $current->getAssignedAt()->format(\DateTimeInterface::ATOM),
                'fromInstrumentistId'   => $fromInstrumentist?->getId(),
                'fromInstrumentistName' => $fromInstrumentistName,
                'previousStatus'        => $previousStatusEnum->value,
                'incompatibilityId'     => $incompatibilityId,
                'actorId'               => $surgeon->getId(),
                'actorName'             => $this->displayName($surgeon),
            ];

            $this->audit->record($mission, $surgeon, AuditEventType::MISSION_ASSIGNMENT_REFUSED_BY_SURGEON, $payload);

            $this->em->flush();  // R-05: flush before dispatch

            return $payload;
        });

        // D-152 — the managers are told about the other conflicts once the refusal is committed.
        $this->incompatibilities?->notifyConflicts($raisedConflicts);

        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId:  $mission->getId(),
            changeType: MissionChangeType::RELEASED,
            actorId:    $surgeon->getId(),
            payload:    $payload,
            occurredAt: new \DateTimeImmutable(),
        ));
    }

    /**
     * D-151 — the mission's surgeon asks the managers to intervene on its current affectation
     * (refusal window over, mission imminent, affectation not refusable…). Never changes the
     * mission nor the affectation: it is recorded once per affectation and the operational
     * managers are told. Returns false when it had already been requested (idempotent: no
     * second notification).
     */
    public function requestAssignmentReview(Mission $mission, User $surgeon): bool
    {
        $payload = $this->assignments->inLockedChange($mission, function () use ($mission, $surgeon): ?array {
            $current = $this->assignments->findCurrent($mission);
            if ($current === null || !in_array($mission->getStatus(), [MissionStatus::ASSIGNED, MissionStatus::IN_PROGRESS], true)) {
                throw new AssignmentRefusalException(AssignmentRefusalException::REVIEW_NOT_POSSIBLE);
            }
            if ($current->isReviewRequested()) {
                return null;
            }

            $current->requestReview($surgeon, Clock::get()->now());

            $instrumentist = $current->getInstrumentist();
            $payload = [
                'assignmentId'      => $current->getId(),
                'assignmentSource'  => $current->getSource()->value,
                'assignedAt'        => $current->getAssignedAt()->format(\DateTimeInterface::ATOM),
                'instrumentistId'   => $instrumentist->getId(),
                'instrumentistName' => $this->displayName($instrumentist),
                'refusalBlockedReason' => MissionAssignmentRefusalPolicy::blockedReason($mission, $current, Clock::get()->now()),
                'actorId'           => $surgeon->getId(),
                'actorName'         => $this->displayName($surgeon),
            ];

            $this->audit->record($mission, $surgeon, AuditEventType::MISSION_ASSIGNMENT_REVIEW_REQUESTED, $payload);

            $this->em->flush();  // R-05: flush before dispatch

            return $payload;
        });

        if ($payload === null) {
            return false;
        }

        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId:  $mission->getId(),
            changeType: MissionChangeType::ASSIGNMENT_REVIEW_REQUESTED,
            actorId:    $surgeon->getId(),
            payload:    $payload,
            occurredAt: new \DateTimeImmutable(),
        ));

        return true;
    }

    /**
     * ASSIGNED → IN_PROGRESS (D-064).
     * Auto-started by MissionStartDueCommand once startAt has passed — $actor is the
     * system technical user (see Version20260715064809 migration), never a real human,
     * since nothing decided this transition beyond the clock. Throws 409 if mission is
     * not ASSIGNED.
     *
     * $notify defaults to false: this is a silent, non-actionable status flip (purely
     * cosmetic today — it only drives the "En cours" pill on the instrumentist's
     * Aujourd'hui hero card) and not something worth emailing anyone about.
     *
     * Uses the same pessimistic write lock as claim() — MissionStartDueCommand is now
     * run on an automated ~5min schedule (D-064), so two overlapping invocations (a slow
     * previous run still in flight when the next tick fires) could otherwise both read
     * the same mission as ASSIGNED before either commits, producing two MISSION_STARTED
     * audit events for the same mission. The lock forces the second invocation to wait,
     * then re-read the now-committed status and throw ConflictHttpException instead of
     * double-recording — MissionStartDueCommand treats that as "already started by a
     * concurrent run", not a real error.
     */
    public function start(Mission $mission, User $actor, bool $notify = false): void
    {
        $payload = null;

        $this->em->wrapInTransaction(function () use ($mission, $actor, &$payload): void {
            $this->em->lock($mission, LockMode::PESSIMISTIC_WRITE);
            // D-124 audit — same stale-read hole as claim(): MissionStartDueCommand loads its
            // whole batch first, so without this refresh a mission started (or cancelled) by a
            // concurrent run/request is re-checked as ASSIGNED and started again — or flipped
            // back from CANCELLED to IN_PROGRESS. Proven by MissionStartDueConcurrencyTest.
            $this->em->refresh($mission);

            if ($mission->getStatus() !== MissionStatus::ASSIGNED) {
                throw new ConflictHttpException('Mission must be ASSIGNED to start');
            }

            $previousStatusEnum = $mission->getStatus();
            $mission->setStatus(MissionStatus::IN_PROGRESS);
            $this->resetEscalationIfLeavingOpen($mission, $previousStatusEnum);

            $payload = [
                'actorId'   => $actor->getId(),
                'actorName' => $this->displayName($actor),
            ];

            $this->audit->record($mission, $actor, AuditEventType::MISSION_STARTED, $payload);

            $this->em->flush();  // R-05: flush before dispatch
        });

        if (!$notify) {
            return;
        }

        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId:  $mission->getId(),
            changeType: MissionChangeType::STARTED,
            actorId:    $actor->getId(),
            payload:    $payload,
            occurredAt: new \DateTimeImmutable(),
        ));
    }

    /**
     * OPEN|ASSIGNED → CANCELLED.
     * Throws 409 if mission is not OPEN or ASSIGNED.
     *
     * If the mission was ASSIGNED, its instrumentist is cleared as part of the transition
     * (a cancelled mission has no assignee) — this is a deliberate extension beyond the
     * original OPEN-only contract, added for AbsenceMissionReactionService (surgeon absence
     * → cancel, regardless of whether an instrumentist had already been assigned). Because
     * the instrumentist is already null by the time MissionLifecycleChangedMessageHandler
     * reloads the mission, its own "defensive" instrumentist-notification branch stays a
     * no-op here by construction — AbsenceMissionReactionService sends its own dedicated
     * absence email to the removed instrumentist instead, avoiding a duplicate.
     *
     * $notify — see release() doc.
     *
     * DRAFT accepted since D-090 (PlanningDraftRevalidationService) — neutralizing a DRAFT
     * mission whose surgeon is now absent, right before deploy() publishes it, reuses this
     * exact same mutation/audit path rather than a bespoke one. Every existing caller only
     * ever invokes this on OPEN/ASSIGNED (unchanged), so widening the guard is purely additive.
     *
     * $causedByAbsenceId — see release() doc (D-104, Lot 4).
     */
    public function cancel(Mission $mission, User $actor, ?string $reason = null, bool $notify = true, ?int $causedByAbsenceId = null): void
    {
        // D-150 — under the mission row lock, re-checked against the committed state.
        $payload = $this->assignments->inLockedChange($mission, fn (): array => $this->applyCancel($mission, $actor, $reason, $causedByAbsenceId));

        if (!$notify) {
            return;
        }

        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId:  $mission->getId(),
            changeType: MissionChangeType::CANCELLED,
            actorId:    $actor->getId(),
            payload:    $payload,
            occurredAt: new \DateTimeImmutable(),
        ));
    }

    /** cancel()'s mutation, run under the mission lock; returns the audit/lifecycle payload. */
    private function applyCancel(Mission $mission, User $actor, ?string $reason, ?int $causedByAbsenceId): array
    {
        if (!in_array($mission->getStatus(), [MissionStatus::DRAFT, MissionStatus::OPEN, MissionStatus::ASSIGNED], true)) {
            throw new ConflictHttpException('Mission must be DRAFT, OPEN or ASSIGNED to cancel');
        }

        $previousStatusEnum = $mission->getStatus();
        $previousStatus     = $previousStatusEnum->value;

        $fromInstrumentist     = $mission->getInstrumentist();
        $fromInstrumentistId   = $fromInstrumentist?->getId();
        $fromInstrumentistName = $fromInstrumentist !== null
            ? $this->displayName($fromInstrumentist)
            : null;

        // H2 (D-128) — snapshot taken BEFORE the mutation: cancel() clears the instrumentist
        // FK, so the async handler must find in the message everything it needs to tell the
        // previously assigned person what happened (who, which surgeon, where, when, why).
        $surgeon = $mission->getSurgeon();
        $snapshot = [
            'surgeonId'   => $surgeon?->getId(),
            'surgeonName' => $surgeon !== null ? $this->displayName($surgeon) : null,
            'siteId'      => $mission->getSite()?->getId(),
            'startAt'     => $mission->getStartAt()?->format(\DateTimeInterface::ATOM),
            'endAt'       => $mission->getEndAt()?->format(\DateTimeInterface::ATOM),
        ];

        $mission->setStatus(MissionStatus::CANCELLED);
        // A DRAFT has no affectation yet (D-150): unassign() then only clears the field.
        $this->assignments->unassign($mission, MissionAssignmentEndReason::CANCELLED, $actor);
        $this->resetEscalationIfLeavingOpen($mission, $previousStatusEnum);
        $this->withdrawCommunication($mission, $fromInstrumentist, MissionCommunicationWithdrawal::CANCELLED, MissionCommunicationSpec::ROLE_INSTRUMENTIST);
        $this->withdrawCommunication($mission, $surgeon, MissionCommunicationWithdrawal::CANCELLED, MissionCommunicationSpec::ROLE_SURGEON);

        $payload = [
            'reason'                => $reason,
            'fromInstrumentistId'   => $fromInstrumentistId,
            'fromInstrumentistName' => $fromInstrumentistName,
            'previousStatus'        => $previousStatus,
            'causedByAbsenceId'     => $causedByAbsenceId,
            'actorId'               => $actor->getId(),
            'actorName'             => $this->displayName($actor),
        ] + $snapshot;

        $this->audit->record($mission, $actor, AuditEventType::MISSION_CANCELLED_POST_DEPLOY, $payload);

        $this->em->flush();  // R-05: flush before dispatch

        return $payload;
    }

    /**
     * CANCELLED → ASSIGNED|OPEN (D-104, Lot 4).
     *
     * Narrow, purpose-built transition — CANCELLED is otherwise terminal everywhere else in
     * this codebase (see cancel()'s guard and every other transition's guard, none of which
     * accept CANCELLED as a source status). Exists solely for
     * AbsenceImpactReconciliationService::reconcileForDeletion()/reconcileForUpdate(), which
     * is exclusively responsible for deciding WHEN it is safe to call this (the caller must
     * already have verified: this mission's most recent AuditEvent is exactly the
     * absence-driven MISSION_CANCELLED_POST_DEPLOY caused by the absence now being deleted/
     * shrunk — i.e. nothing else has touched this mission since). This method itself only
     * enforces the mechanical status guard and, when $instrumentistId is given, eligibility
     * (STRICT_ASSIGNMENT — ABSENT/SCHEDULE_CONFLICT/INACTIVE per D-101) — it has no opinion
     * on absence causality.
     *
     * $instrumentistId — the previously-assigned instrumentist to re-validate and restore.
     * Pass null to restore straight to OPEN (no previous instrumentist, or the caller has
     * already determined the previous one is no longer eligible).
     */
    public function restoreAfterCancellation(
        Mission $mission,
        User $actor,
        ?int $instrumentistId,
        int $causedByAbsenceId,
        bool $notify = false,
    ): void {
        // D-150 — under the mission row lock, re-checked against the committed state.
        [$payload, $restoredStatus] = $this->assignments->inLockedChange($mission, function () use ($mission, $actor, $instrumentistId, $causedByAbsenceId): array {
            if ($mission->getStatus() !== MissionStatus::CANCELLED) {
                throw new ConflictHttpException('Mission must be CANCELLED to restore');
            }

            $newInstrumentist = null;
            if ($instrumentistId !== null) {
                $newInstrumentist = $this->em->find(User::class, $instrumentistId);
                if ($newInstrumentist === null) {
                    throw new NotFoundHttpException('Instrumentist not found');
                }
                $this->guardEligibility($mission, $newInstrumentist, EligibilityEnforcementPolicy::STRICT_ASSIGNMENT);
            }

            $restoredStatus = $newInstrumentist !== null ? MissionStatus::ASSIGNED : MissionStatus::OPEN;

            $previousStatusEnum = $mission->getStatus();
            $mission->setStatus($restoredStatus);
            if ($newInstrumentist !== null) {
                $this->assignments->assign($mission, $newInstrumentist, MissionAssignmentSource::RESTORED_AFTER_ABSENCE, $actor);
            } else {
                $this->assignments->unassign($mission, MissionAssignmentEndReason::CANCELLED, $actor);
            }
            $this->resetEscalationIfLeavingOpen($mission, $previousStatusEnum);

            $payload = [
                'previousStatus'         => MissionStatus::CANCELLED->value,
                'restoredStatus'         => $restoredStatus->value,
                'restoredInstrumentistId' => $newInstrumentist?->getId(),
                'causedByAbsenceId'      => $causedByAbsenceId,
                'actorId'                => $actor->getId(),
                'actorName'              => $this->displayName($actor),
            ];

            $this->audit->record($mission, $actor, AuditEventType::MISSION_RESTORED_AFTER_SURGEON_ABSENCE, $payload);

            $this->em->flush();  // R-05: flush before dispatch

            return [$payload, $restoredStatus];
        });

        // H3 (D-129) — a mission restored as OPEN is a real opening: its pool is solicited
        // through OpenMissionPoolNotifier, independently of $notify (which only governs the
        // person-addressed lifecycle notifications, handled by PlanningRestoredAfterAbsence).
        if ($restoredStatus === MissionStatus::OPEN) {
            $this->bus->dispatch(OpenMissionPoolMessage::of([
                OpenEpisode::forTransition((int) $mission->getId(), OpenEpisodeCause::RESTORED_AFTER_ABSENCE, new \DateTimeImmutable(), actorId: $actor->getId()),
            ]));
        }

        if (!$notify) {
            return;
        }

        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId:  $mission->getId(),
            changeType: $restoredStatus === MissionStatus::ASSIGNED ? MissionChangeType::REASSIGNED : MissionChangeType::RELEASED,
            actorId:    $actor->getId(),
            payload:    $payload,
            occurredAt: new \DateTimeImmutable(),
        ));
    }

    /**
     * OPEN → ASSIGNED.
     * Instrumentist claims a pool mission. Uses pessimistic write lock to prevent
     * double-claim race conditions. Throws 409 on state or eligibility conflict.
     */
    public function claim(Mission $mission, User $actor): void
    {
        // Pre-lock eligibility gate — fast fail before acquiring a pessimistic lock.
        // TOCTOU window is acceptable: the inner lock re-validates status + instrumentist.
        $eligibility = $this->eligibilityService->evaluate($mission, $actor);
        if (!$eligibility->eligible) {
            // BUG A (2026-09-09) — when ABSENT is (one of) the reason(s), resolve which
            // Absence row is actually blocking so the structured error carries enough for
            // the frontend to offer "Retirer mon absence pour ce jour" without a second
            // round trip. Never re-derives the ABSENT rule itself — same query
            // evaluate() already used, just also returning the row.
            $blockingAbsence = in_array(EligibilityReason::ABSENT, $eligibility->reasons, true) && $mission->getStartAt() !== null
                ? $this->eligibilityService->findBlockingAbsence($actor, $mission->getStartAt())
                : null;

            throw new MissionClaimIneligibleException($eligibility->reasons, $mission->getStartAt(), $blockingAbsence);
        }

        $assignment = null;
        try {
            $this->em->wrapInTransaction(function () use ($mission, $actor, &$assignment): void {
                $this->em->lock($mission, LockMode::PESSIMISTIC_WRITE);
                // D-124 audit — lock() alone does NOT reload an already-managed entity. A
                // concurrent request that loaded this Mission (OPEN) before the winner
                // committed would otherwise re-check a stale in-memory OPEN/NULL here and
                // silently overwrite the winner's assignment (mission_claim has no unique
                // constraint since Version20260212093000). Proven by
                // RoomTakeoverConcurrencyTest::test_two_instrumentists_racing_to_claim_the_new_mission_only_one_wins.
                $this->em->refresh($mission);

                if ($mission->getStatus() !== MissionStatus::OPEN) {
                    throw new ConflictHttpException('Mission not claimable');
                }

                if ($mission->getInstrumentist() !== null) {
                    throw new ConflictHttpException('Mission already claimed');
                }

                // D-152 — locking read of the (surgeon, instrumentist) incompatibility range:
                // a block created concurrently is either seen here or waits for this claim and
                // then raises it as a conflict — never a silent incompatible affectation.
                if ($mission->getSurgeon() !== null && $this->eligibilityService->isIncompatibleLocked($mission->getSurgeon(), $actor)) {
                    throw new MissionClaimIneligibleException([EligibilityReason::SURGEON_INCOMPATIBILITY], $mission->getStartAt());
                }

                // D-059 (architecture freeze): MissionClaim is an append-only historical
                // record and must never again be consulted for a business decision — a
                // mission that was claimed and later released legitimately keeps its old
                // MissionClaim row(s) forever, and that history must not block a future
                // claim. The Mission's own current state — status OPEN (checked above) +
                // instrumentist NULL (checked above), both read inside this same
                // pessimistic lock — is the exclusive source of truth for whether this
                // mission is claimable right now. (A `findOneBy(['mission' => $mission])`
                // existence guard used to sit here; removed 2026-09-09, see docs/decisions.md.)
                $claim = new MissionClaim();
                $claim
                    ->setMission($mission)
                    ->setInstrumentist($actor)
                    ->setClaimedAt(new \DateTimeImmutable());

                $previousStatusEnum = $mission->getStatus();
                $assignment = $this->assignments->assign($mission, $actor, $this->claimSource($mission, $actor), $actor);
                $mission->setStatus(MissionStatus::ASSIGNED);
                $this->resetEscalationIfLeavingOpen($mission, $previousStatusEnum);

                $this->em->persist($claim);

                $instrumentistName = $this->displayName($actor);
                $payload = [
                    'instrumentistId'   => $actor->getId(),
                    'instrumentistName' => $instrumentistName,
                    'actorId'           => $actor->getId(),
                    'actorName'         => $instrumentistName,
                ];

                $this->audit->record($mission, $actor, AuditEventType::MISSION_CLAIMED_FROM_POOL, $payload);

                $this->em->flush();  // R-05: flush before dispatch
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConflictHttpException('Mission already claimed');
        }

        // H4 (D-130) — she took it herself (pool, or accepting a nominative request): the
        // mission is part of her planning by her own action, no delivery proof needed.
        $this->communications?->activate($mission, $actor, MissionCommunicationSpec::ROLE_INSTRUMENTIST, MissionCommunicationSource::CLAIM);

        // Dispatch after transaction commits — R-07
        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId:  $mission->getId(),
            changeType: MissionChangeType::CLAIMED,
            actorId:    $actor->getId(),
            payload:    [
                'instrumentistId'   => $actor->getId(),
                'instrumentistName' => $this->displayName($actor),
                // D-151 — the surgeon's notification reads this affectation's refusal deadline.
                'assignmentId'      => $assignment?->getId(),
            ],
            occurredAt: new \DateTimeImmutable(),
        ));
    }

    /**
     * OPEN|ASSIGNED → ASSIGNED (manager-triggered assignment, e.g. via a PlanningAlert action).
     * Assigns a specific instrumentist; transitions OPEN→ASSIGNED when needed.
     * Throws 409 if the mission is not in a mutable post-deploy state, 404 if target not found.
     *
     * $causedByAbsenceId / $reassignedFromMissionId — CAS B (D-118): set only by
     * AbsenceMissionReactionService when this assignment is the automatic reassignment of an
     * instrumentist just freed from a surgeon-absence-cancelled mission. Same structured,
     * never-free-text convention as release()/cancel()'s own $causedByAbsenceId (D-104) —
     * lets a later audit (or AbsenceImpactReconciliationService) reconstruct "mission A
     * cancelled by absence N, freed instrumentist X, X reassigned to mission B by that same
     * absence N" purely from AuditEvent.payload on A and B, without a new AuditEventType.
     * Both default to null and are omitted from the payload comparison every other caller
     * relies on — purely additive, no existing behavior changes.
     */
    public function assign(
        Mission $mission,
        User $actor,
        int $newInstrumentistId,
        bool $notify = true,
        EligibilityEnforcementPolicy $policy = EligibilityEnforcementPolicy::STRICT_ASSIGNMENT,
        ?int $causedByAbsenceId = null,
        ?int $reassignedFromMissionId = null,
    ): void {
        // D-150 — under the mission row lock, re-checked against the committed state.
        $payload = $this->assignments->inLockedChange(
            $mission,
            fn (): array => $this->applyAssign($mission, $actor, $newInstrumentistId, $policy, $causedByAbsenceId, $reassignedFromMissionId),
        );

        if (!$notify) {
            return;
        }

        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId:  $mission->getId(),
            changeType: MissionChangeType::REASSIGNED,
            actorId:    $actor->getId(),
            payload:    $payload,
            occurredAt: new \DateTimeImmutable(),
        ));
    }

    /** assign()'s mutation, run under the mission lock; returns the audit/lifecycle payload. */
    private function applyAssign(
        Mission $mission,
        User $actor,
        int $newInstrumentistId,
        EligibilityEnforcementPolicy $policy,
        ?int $causedByAbsenceId,
        ?int $reassignedFromMissionId,
    ): array {
        if (!in_array($mission->getStatus(), [MissionStatus::OPEN, MissionStatus::ASSIGNED], true)) {
            throw new ConflictHttpException('Mission must be OPEN or ASSIGNED to assign');
        }

        $newInstrumentist = $this->em->find(User::class, $newInstrumentistId);
        if ($newInstrumentist === null) {
            throw new NotFoundHttpException('Instrumentist not found');
        }

        $this->guardEligibility($mission, $newInstrumentist, $policy);

        $fromInstrumentist     = $mission->getInstrumentist();
        $fromInstrumentistId   = $fromInstrumentist?->getId();
        $fromInstrumentistName = $fromInstrumentist !== null
            ? $this->displayName($fromInstrumentist)
            : null;

        $previousStatusEnum = $mission->getStatus();
        $this->assignments->assign($mission, $newInstrumentist, MissionAssignmentSource::MANAGER_ASSIGNMENT, $actor);
        if ($mission->getStatus() === MissionStatus::OPEN) {
            $mission->setStatus(MissionStatus::ASSIGNED);
        }
        $this->resetEscalationIfLeavingOpen($mission, $previousStatusEnum);
        if ($fromInstrumentist !== null && $fromInstrumentist->getId() !== $newInstrumentist->getId()) {
            $this->withdrawCommunication($mission, $fromInstrumentist, MissionCommunicationWithdrawal::REASSIGNED, MissionCommunicationSpec::ROLE_INSTRUMENTIST);
        }

        $payload = [
            'fromInstrumentistId'      => $fromInstrumentistId,
            'fromInstrumentistName'    => $fromInstrumentistName,
            'toInstrumentistId'        => $newInstrumentistId,
            'toInstrumentistName'      => $this->displayName($newInstrumentist),
            'causedByAbsenceId'        => $causedByAbsenceId,
            'reassignedFromMissionId'  => $reassignedFromMissionId,
            'actorId'                  => $actor->getId(),
            'actorName'                => $this->displayName($actor),
        ];

        $this->audit->record($mission, $actor, AuditEventType::MISSION_REASSIGNED_POST_DEPLOY, $payload);

        $this->em->flush();  // R-05: flush before dispatch

        return $payload;
    }

    /**
     * ASSIGNED → ASSIGNED (new instrumentist).
     * Manager reassigns a mission from one instrumentist to another.
     * Throws 409 if mission is not ASSIGNED, 404 if target instrumentist not found.
     *
     * $notify — see release() doc.
     */
    public function reassign(
        Mission $mission,
        User $actor,
        int $newInstrumentistId,
        bool $notify = true,
        EligibilityEnforcementPolicy $policy = EligibilityEnforcementPolicy::STRICT_ASSIGNMENT,
    ): void {
        // D-150 — under the mission row lock, re-checked against the committed state.
        $payload = $this->assignments->inLockedChange(
            $mission,
            fn (): array => $this->applyReassign($mission, $actor, $newInstrumentistId, $policy),
        );

        if (!$notify) {
            return;
        }

        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId:  $mission->getId(),
            changeType: MissionChangeType::REASSIGNED,
            actorId:    $actor->getId(),
            payload:    $payload,
            occurredAt: new \DateTimeImmutable(),
        ));
    }

    /** reassign()'s mutation, run under the mission lock; returns the audit/lifecycle payload. */
    private function applyReassign(Mission $mission, User $actor, int $newInstrumentistId, EligibilityEnforcementPolicy $policy): array
    {
        if ($mission->getStatus() !== MissionStatus::ASSIGNED) {
            throw new ConflictHttpException('Mission must be ASSIGNED to reassign');
        }

        $newInstrumentist = $this->em->find(User::class, $newInstrumentistId);
        if ($newInstrumentist === null) {
            throw new NotFoundHttpException('Instrumentist not found');
        }

        $this->guardEligibility($mission, $newInstrumentist, $policy);

        $fromInstrumentist     = $mission->getInstrumentist();
        $fromInstrumentistId   = $fromInstrumentist?->getId();
        $fromInstrumentistName = $fromInstrumentist !== null
            ? $this->displayName($fromInstrumentist)
            : null;

        $this->assignments->assign($mission, $newInstrumentist, MissionAssignmentSource::MANAGER_ASSIGNMENT, $actor);
        // Status stays ASSIGNED
        if ($fromInstrumentist !== null && $fromInstrumentist->getId() !== $newInstrumentist->getId()) {
            $this->withdrawCommunication($mission, $fromInstrumentist, MissionCommunicationWithdrawal::REASSIGNED, MissionCommunicationSpec::ROLE_INSTRUMENTIST);
        }

        $payload = [
            'fromInstrumentistId'   => $fromInstrumentistId,
            'fromInstrumentistName' => $fromInstrumentistName,
            'toInstrumentistId'     => $newInstrumentistId,
            'toInstrumentistName'   => $this->displayName($newInstrumentist),
            'actorId'               => $actor->getId(),
            'actorName'             => $this->displayName($actor),
        ];

        $this->audit->record($mission, $actor, AuditEventType::MISSION_REASSIGNED_POST_DEPLOY, $payload);

        $this->em->flush();  // R-05: flush before dispatch

        return $payload;
    }

    /**
     * Post-deploy schedule change: startAt/endAt/site/type. Any ASSIGNED/OPEN mission
     * (never DRAFT/CANCELLED/REJECTED — those go through other flows). Used by Planning V2
     * Modification mode; the individual PATCH /api/missions/{id} endpoint stays DRAFT-only
     * for its existing purpose, unrelated to this new post-deploy path.
     *
     * $notify — see release() doc.
     */
    public function updateSchedule(
        Mission $mission,
        User $actor,
        ?\DateTimeImmutable $startAt,
        ?\DateTimeImmutable $endAt,
        ?Hospital $site,
        ?MissionType $type,
        bool $notify = true,
        EligibilityEnforcementPolicy $policy = EligibilityEnforcementPolicy::STRICT_ASSIGNMENT,
    ): void {
        if (!in_array($mission->getStatus(), [MissionStatus::OPEN, MissionStatus::ASSIGNED], true)) {
            throw new ConflictHttpException('Mission must be OPEN or ASSIGNED to change its schedule');
        }

        $fromStartAt = $mission->getStartAt();
        $fromEndAt   = $mission->getEndAt();
        $fromSite    = $mission->getSite();
        $fromType    = $mission->getType();

        if ($startAt !== null) {
            $mission->setStartAt($startAt);
        }
        if ($endAt !== null) {
            $mission->setEndAt($endAt);
        }
        if ($site !== null) {
            $mission->setSite($site);
        }
        if ($type !== null) {
            $mission->setType($type);
        }

        // D-101 — a schedule/site change can introduce a new incompatibility (the
        // instrumentist wasn't absent/conflicted for the OLD slot but is for the NEW one).
        // Revalidate the current instrumentist (if any) against the mission's now-updated
        // fields; revert before throwing so nothing incompatible is ever left staged.
        $currentInstrumentist = $mission->getInstrumentist();
        if ($currentInstrumentist !== null) {
            try {
                $this->guardEligibility($mission, $currentInstrumentist, $policy);
            } catch (InstrumentistIneligibleException $e) {
                $mission->setStartAt($fromStartAt);
                $mission->setEndAt($fromEndAt);
                $mission->setSite($fromSite);
                $mission->setType($fromType);
                throw $e;
            }
        }

        $payload = [
            'fromStartAt' => $fromStartAt?->format(\DateTimeInterface::ATOM),
            'fromEndAt'   => $fromEndAt?->format(\DateTimeInterface::ATOM),
            'toStartAt'   => $mission->getStartAt()?->format(\DateTimeInterface::ATOM),
            'toEndAt'     => $mission->getEndAt()?->format(\DateTimeInterface::ATOM),
            'fromSiteId'  => $fromSite?->getId(),
            'toSiteId'    => $mission->getSite()?->getId(),
            'fromType'    => $fromType?->value,
            'toType'      => $mission->getType()?->value,
            'actorId'     => $actor->getId(),
            'actorName'   => $this->displayName($actor),
        ];

        $this->audit->record($mission, $actor, AuditEventType::MISSION_TIME_CHANGED_POST_DEPLOY, $payload);

        $this->em->flush();  // R-05: flush before dispatch

        if (!$notify) {
            return;
        }

        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId:  $mission->getId(),
            changeType: MissionChangeType::TIME_CHANGED,
            actorId:    $actor->getId(),
            payload:    $payload,
            occurredAt: new \DateTimeImmutable(),
        ));
    }

    /**
     * Creates a new Mission directly against an already-deployed PlanningVersion (Planning V2
     * Modification mode "add a mission" action) — distinct from MissionService::create(),
     * which always creates a DRAFT unlinked to any version for the pre-deploy authoring flow.
     * Status is ASSIGNED if an instrumentist is given, OPEN otherwise (never DRAFT — this
     * mission is immediately live in an active/published version).
     *
     * $notify — see release() doc.
     *
     * $planningVersion nullable since D-124: a room take-over (ReleasedRoomSlotTakeoverService)
     * reuses the absent surgeon's cancelled Mission's version when one exists, but a room
     * released BEFORE its planning was generated (D-103) has no version to attach to — the
     * Mission is then live without one, exactly like a manually published ad hoc Mission
     * (MissionService::publish()). Every pre-existing caller still passes a version.
     */
    public function createPostDeploy(
        ?PlanningVersion $planningVersion,
        User $actor,
        Hospital $site,
        User $surgeon,
        ?User $instrumentist,
        MissionType $type,
        \DateTimeImmutable $startAt,
        \DateTimeImmutable $endAt,
        bool $notify = true,
        EligibilityEnforcementPolicy $policy = EligibilityEnforcementPolicy::STRICT_ASSIGNMENT,
    ): Mission {
        if ($endAt <= $startAt) {
            throw new ConflictHttpException('endAt must be after startAt');
        }

        $mission = new Mission();
        $mission
            ->setPlanningVersion($planningVersion)
            ->setSite($site)
            ->setType($type)
            ->setSchedulePrecision(SchedulePrecision::EXACT)
            ->setSurgeon($surgeon)
            ->setCreatedBy($actor)
            ->setStartAt($startAt)
            ->setEndAt($endAt);

        // D-101 — "ajout post-deploy" is still a real affectation.
        if ($instrumentist !== null) {
            $this->guardEligibility($mission, $instrumentist, $policy);
            $this->assignments->assign($mission, $instrumentist, MissionAssignmentSource::POST_DEPLOY_ADDITION, $actor);
        }
        $mission->setStatus($instrumentist !== null ? MissionStatus::ASSIGNED : MissionStatus::OPEN);

        $this->em->persist($mission);

        $payload = [
            'surgeonId'         => $surgeon->getId(),
            'surgeonName'       => $this->displayName($surgeon),
            'instrumentistId'   => $instrumentist?->getId(),
            'instrumentistName' => $instrumentist !== null ? $this->displayName($instrumentist) : null,
            'actorId'           => $actor->getId(),
            'actorName'         => $this->displayName($actor),
        ];

        $this->audit->record($mission, $actor, AuditEventType::MISSION_ADDED_POST_DEPLOY, $payload);

        $this->em->flush();  // R-05: flush before dispatch

        if (!$notify) {
            return $mission;
        }

        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId:  $mission->getId(),
            changeType: MissionChangeType::ADDED,
            actorId:    $actor->getId(),
            payload:    $payload,
            occurredAt: new \DateTimeImmutable(),
        ));

        return $mission;
    }

    /**
     * D-125 — DRAFT|OPEN(unassigned) → ASSIGNED: "Attribuer directement". The manager and
     * the instrumentist already agreed outside SurgicalHub (phone, WhatsApp, at the OR…), so
     * no acceptance step exists: the Mission is covered immediately. Distinct from assign()
     * (post-deploy reassignment of an existing OPEN/ASSIGNED slot, audited as
     * MISSION_REASSIGNED_POST_DEPLOY) so the audit trail always tells "direct assignment, no
     * acceptance required" apart from a reassignment or a pool claim — and so the
     * notification is a confirmation (MISSION_ASSIGNED_DIRECTLY), never a request.
     *
     * Same pessimistic-lock + refresh discipline as claim(): an OPEN mission may be claimed
     * concurrently from the pool (or by the target of a pending TARGETED request); whichever
     * commits first wins, the other gets a 409 — never a silent overwrite. Eligibility is the
     * canonical STRICT_ASSIGNMENT gate (guardEligibility(), D-101).
     */
    public function assignDirectly(Mission $mission, User $actor, int $instrumentistId): void
    {
        $instrumentist = $this->em->find(User::class, $instrumentistId);
        if ($instrumentist === null) {
            throw new NotFoundHttpException('Instrumentist not found');
        }

        $payload = [];

        $this->em->wrapInTransaction(function () use ($mission, $actor, $instrumentist, &$payload): void {
            if ($mission->getId() !== null) {
                $this->em->lock($mission, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($mission);
            }

            $previousStatus = $mission->getStatus();
            $assignable = $previousStatus === MissionStatus::DRAFT
                || ($previousStatus === MissionStatus::OPEN && $mission->getInstrumentist() === null);
            if (!$assignable) {
                throw new ConflictHttpException('Mission must be DRAFT, or OPEN without instrumentist, to be assigned directly');
            }

            $this->guardEligibility($mission, $instrumentist, EligibilityEnforcementPolicy::STRICT_ASSIGNMENT);

            // A DRAFT becomes a published, covered mission right here: its affectation starts now.
            $this->assignments->assign($mission, $instrumentist, MissionAssignmentSource::DIRECT_ASSIGNMENT, $actor);
            $mission->setStatus(MissionStatus::ASSIGNED);
            $this->resetEscalationIfLeavingOpen($mission, $previousStatus);

            $payload = [
                'assignmentMode'     => 'DIRECT',
                'requiresAcceptance' => false,
                'fromStatus'         => $previousStatus->value,
                'instrumentistId'    => $instrumentist->getId(),
                'instrumentistName'  => $this->displayName($instrumentist),
                'actorId'            => $actor->getId(),
                'actorName'          => $this->displayName($actor),
            ];

            $this->audit->record($mission, $actor, AuditEventType::MISSION_ASSIGNED_DIRECTLY, $payload);

            $this->em->flush(); // R-05: flush before dispatch
        });

        // Dispatch after transaction commits — R-07
        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId:  $mission->getId(),
            changeType: MissionChangeType::ASSIGNED_DIRECTLY,
            actorId:    $actor->getId(),
            payload:    $payload,
            occurredAt: new \DateTimeImmutable(),
        ));
    }

    /**
     * D-110 (J-14) — marks the current OPEN episode's escalation as sent. Never changes
     * Mission status. Same pessimistic-lock convention as claim()/start(): re-validates
     * "still OPEN AND not yet escalated" AFTER acquiring the lock, so two overlapping
     * command runs (cron + a manual trigger) racing on the same Mission can't both send
     * the escalation — the second one's re-check finds the marker already set and returns
     * false without mutating or auditing anything.
     *
     * Returns true only when THIS call actually won the race and persisted the marker —
     * the caller must only dispatch the notification message when this returns true, and
     * only after this method returns (i.e. after the transaction has committed).
     */
    public function markUncoveredEscalationSent(Mission $mission, User $actor): bool
    {
        $marked = false;

        $this->em->wrapInTransaction(function () use ($mission, $actor, &$marked): void {
            $this->em->lock($mission, LockMode::PESSIMISTIC_WRITE);
            // D-124 audit — same stale-read hole as claim(): CheckUncoveredEscalationsCommand
            // loads its candidates first, so without this refresh a marker set (or a mission
            // covered) by a concurrent run/request is re-checked as "OPEN, not escalated" and
            // escalated again. Proven by CheckUncoveredEscalationsConcurrencyTest.
            $this->em->refresh($mission);

            if ($mission->getStatus() !== MissionStatus::OPEN || $mission->getUncoveredEscalationSentAt() !== null) {
                return;
            }

            $mission->setUncoveredEscalationSentAt(new \DateTimeImmutable());

            $surgeon = $mission->getSurgeon();
            $site    = $mission->getSite();
            $payload = [
                'startAt'          => $mission->getStartAt()?->format(\DateTimeInterface::ATOM),
                'siteId'           => $site?->getId(),
                'siteName'         => $site?->getName(),
                'surgeonId'        => $surgeon?->getId(),
                'surgeonName'      => $surgeon !== null ? $this->displayName($surgeon) : null,
                'daysUntilMission' => $mission->getStartAt() !== null
                    ? (int) (new \DateTimeImmutable())->diff($mission->getStartAt())->days
                    : null,
                'actorId'          => $actor->getId(),
                'actorName'        => $this->displayName($actor),
            ];

            $this->audit->record($mission, $actor, AuditEventType::MISSION_UNCOVERED_ESCALATION_SENT, $payload);

            $this->em->flush(); // R-05: flush before dispatch

            $marked = true;
        });

        return $marked;
    }

    /**
     * D-101 — single canonical eligibility gate for every assign/reassign/schedule-change/
     * add-mission mutation in this service. `evaluateForReassignment()` always computes
     * every applicable reason (single source of truth, never duplicated); the caller's
     * `$policy` decides which of those reasons actually block the mutation here — never a
     * free-form reasons list invented per call site. Throws with only the reasons that are
     * actually blocking under this policy (e.g. under `PLANNING_MODIFICATION`, a
     * `SCHEDULE_CONFLICT`-only result never throws — the caller's own
     * `syncAlertsForMission()` is the intended non-blocking surface for that case).
     */
    private function guardEligibility(Mission $mission, User $candidate, EligibilityEnforcementPolicy $policy): void
    {
        $eligibility = $this->eligibilityService->evaluateForReassignment($mission, $candidate);

        $blocking = array_values(array_filter(
            $eligibility->reasons,
            static fn (EligibilityReason $r) => in_array($r, $policy->blockingReasons(), true),
        ));

        // D-152 — inside the mutation's transaction, re-read the incompatibility with a lock:
        // serialises with a concurrent creation (see MissionEligibilityService::isIncompatibleLocked()).
        if (!in_array(EligibilityReason::SURGEON_INCOMPATIBILITY, $blocking, true)
            && $mission->getSurgeon() !== null
            && $this->em->getConnection()->isTransactionActive()
            && $this->eligibilityService->isIncompatibleLocked($mission->getSurgeon(), $candidate)) {
            $blocking[] = EligibilityReason::SURGEON_INCOMPATIBILITY;
        }

        if (!empty($blocking)) {
            throw new InstrumentistIneligibleException($blocking);
        }
    }

    /**
     * D-150 — a claim is the acceptance of a nominative offer when an active TARGETED
     * publication addressed to this instrumentist exists (D-125), otherwise a pool claim.
     * Read inside claim()'s lock, from the publications the voter already granted on.
     */
    private function claimSource(Mission $mission, User $instrumentist): MissionAssignmentSource
    {
        foreach ($mission->getPublications() as $publication) {
            if ($publication->isActive()
                && $publication->getScope() === PublicationScope::TARGETED
                && $publication->getTargetInstrumentist()?->getId() === $instrumentist->getId()) {
                return MissionAssignmentSource::OFFER_ACCEPTED;
            }
        }

        return MissionAssignmentSource::POOL_CLAIM;
    }

    /**
     * D-155 — OPEN (commencée, jamais couverte) → ENCODING_IN_PROGRESS : l'activité a réellement
     * eu lieu sans instrumentiste. Le chirurgien de la mission (ou un manager) l'encode ensuite
     * comme une instrumentiste le ferait, sans heures. La mission quitte immédiatement le pool
     * (plus OPEN : ni claim, ni offre, ni affectation). Sous le verrou de la mission, la règle
     * (ExecutedWithoutInstrumentistPolicy) relue contre l'état validé : un claim concurrent
     * l'emporte ou perd, jamais les deux.
     */
    public function declareExecutedWithoutInstrumentist(Mission $mission, User $actor): void
    {
        $this->assignments->inLockedChange($mission, function () use ($mission, $actor): void {
            $now = Clock::get()->now();
            $blocked = ExecutedWithoutInstrumentistPolicy::declarationBlockedReason($mission, $now);
            if ($blocked !== null) {
                throw new ConflictHttpException($blocked);
            }

            $previous = $mission->getStatus();
            $mission->setStatus(MissionStatus::ENCODING_IN_PROGRESS);
            $mission->setEncodingStartedAt($now);
            $mission->setExecutedWithoutInstrumentist($now, $actor);
            $this->resetEscalationIfLeavingOpen($mission, $previous);

            $surgeon = $mission->getSurgeon();
            $this->audit->record($mission, $actor, AuditEventType::MISSION_EXECUTED_WITHOUT_INSTRUMENTIST_DECLARED, [
                'previousStatus' => $previous->value,
                'newStatus'      => $mission->getStatus()->value,
                'actorId'        => $actor->getId(),
                'actorName'      => $this->displayName($actor),
                'declaredAs'     => ExecutedWithoutInstrumentistPolicy::isManager($actor) ? 'MANAGER' : 'SURGEON',
                'surgeonId'      => $surgeon?->getId(),
                'surgeonName'    => $surgeon !== null ? $this->displayName($surgeon) : null,
            ]);
            $this->em->flush();
        });
    }

    /**
     * D-155 — correction auditée d'une déclaration erronée (manager, motif obligatoire) :
     * ENCODING_IN_PROGRESS → OPEN, le fait est effacé. Jamais une fois l'encodage soumis,
     * validé ou verrouillé (le renvoyer d'abord en correction). Les interventions et le
     * matériel déjà encodés sont conservés et repris dans l'audit (rien n'est supprimé en
     * silence) ; la mission commencée reste non prenable depuis le pool (D-151).
     */
    public function cancelExecutedWithoutInstrumentistDeclaration(Mission $mission, User $actor, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new UnprocessableEntityHttpException("Indiquez le motif de l'annulation de la déclaration.");
        }

        $this->assignments->inLockedChange($mission, function () use ($mission, $actor, $reason): void {
            $blocked = ExecutedWithoutInstrumentistPolicy::cancellationBlockedReason($mission);
            if ($blocked !== null) {
                throw new ConflictHttpException($blocked);
            }

            $declaredAt = $mission->getExecutedWithoutInstrumentistAt();
            $declaredBy = $mission->getExecutedWithoutInstrumentistBy();
            $previous = $mission->getStatus();
            $mission->setStatus(MissionStatus::OPEN);
            $mission->setExecutedWithoutInstrumentist(null, null);

            $this->audit->record($mission, $actor, AuditEventType::MISSION_EXECUTED_WITHOUT_INSTRUMENTIST_CANCELLED, [
                'previousStatus'      => $previous->value,
                'newStatus'           => $mission->getStatus()->value,
                'reason'              => $reason,
                'actorId'             => $actor->getId(),
                'actorName'           => $this->displayName($actor),
                'declaredAt'          => $declaredAt?->format(\DateTimeInterface::ATOM),
                'declaredById'        => $declaredBy?->getId(),
                'declaredByName'      => $declaredBy !== null ? $this->displayName($declaredBy) : null,
                'interventionCount'   => $mission->getInterventions()->count(),
                'materialLineCount'   => $mission->getMaterialLines()->count(),
            ]);
            $this->em->flush();
        });
    }

    /**
     * D-110 (J-14) — single centralized rule: whenever a Mission truly leaves OPEN (to
     * ASSIGNED or CANCELLED), its uncovered-escalation marker must reset to NULL so a later,
     * genuinely new episode of non-coverage can trigger a fresh escalation instead of being
     * silently suppressed by a marker left over from a previous, already-resolved episode.
     * Called from every status-mutating method in this service (the sole place Mission
     * status is ever written in production, per the D-110 audit) rather than duplicated
     * per-call-site, so a future new transition can't silently forget it. A no-op whenever
     * the previous status wasn't OPEN, or the new status still is (defensive — no current
     * caller hits that case, but the guard costs nothing).
     */
    private function resetEscalationIfLeavingOpen(Mission $mission, MissionStatus $previousStatus): void
    {
        if ($previousStatus === MissionStatus::OPEN && $mission->getStatus() !== MissionStatus::OPEN) {
            $mission->setUncoveredEscalationSentAt(null);
        }
    }

    /** H4 (D-130) — persisted by the caller's own flush, in the same transaction as the mutation. */
    private function withdrawCommunication(Mission $mission, ?User $person, MissionCommunicationWithdrawal $reason, string $role): void
    {
        if ($person !== null && $mission->getId() !== null) {
            $this->communications?->withdraw($mission, $person, $reason, $role);
        }
    }

    private function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
