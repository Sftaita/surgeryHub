<?php

namespace App\Service;

use App\Dto\EligibilityResult;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionClaim;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\EligibilityEnforcementPolicy;
use App\Enum\EligibilityReason;
use App\Enum\MissionChangeType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use App\Exception\InstrumentistIneligibleException;
use App\Exception\MissionClaimIneligibleException;
use App\Message\MissionLifecycleChangedMessage;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

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
    public function __construct(
        private readonly EntityManagerInterface    $em,
        private readonly MessageBusInterface       $bus,
        private readonly AuditService              $audit,
        private readonly MissionEligibilityService $eligibilityService,
    ) {}

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
        $mission->setInstrumentist(null);
        $this->resetEscalationIfLeavingOpen($mission, $previousStatusEnum);

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

        $mission->setStatus(MissionStatus::CANCELLED);
        $mission->setInstrumentist(null);
        $this->resetEscalationIfLeavingOpen($mission, $previousStatusEnum);

        $payload = [
            'reason'                => $reason,
            'fromInstrumentistId'   => $fromInstrumentistId,
            'fromInstrumentistName' => $fromInstrumentistName,
            'previousStatus'        => $previousStatus,
            'causedByAbsenceId'     => $causedByAbsenceId,
            'actorId'               => $actor->getId(),
            'actorName'             => $this->displayName($actor),
        ];

        $this->audit->record($mission, $actor, AuditEventType::MISSION_CANCELLED_POST_DEPLOY, $payload);

        $this->em->flush();  // R-05: flush before dispatch

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
        $mission->setInstrumentist($newInstrumentist);
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

        try {
            $this->em->wrapInTransaction(function () use ($mission, $actor): void {
                $this->em->lock($mission, LockMode::PESSIMISTIC_WRITE);

                if ($mission->getStatus() !== MissionStatus::OPEN) {
                    throw new ConflictHttpException('Mission not claimable');
                }

                if ($mission->getInstrumentist() !== null) {
                    throw new ConflictHttpException('Mission already claimed');
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
                $mission->setInstrumentist($actor);
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

        // Dispatch after transaction commits — R-07
        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId:  $mission->getId(),
            changeType: MissionChangeType::CLAIMED,
            actorId:    $actor->getId(),
            payload:    [
                'instrumentistId'   => $actor->getId(),
                'instrumentistName' => $this->displayName($actor),
            ],
            occurredAt: new \DateTimeImmutable(),
        ));
    }

    /**
     * OPEN|ASSIGNED → ASSIGNED (manager-triggered assignment, e.g. via a PlanningAlert action).
     * Assigns a specific instrumentist; transitions OPEN→ASSIGNED when needed.
     * Throws 409 if the mission is not in a mutable post-deploy state, 404 if target not found.
     */
    public function assign(
        Mission $mission,
        User $actor,
        int $newInstrumentistId,
        bool $notify = true,
        EligibilityEnforcementPolicy $policy = EligibilityEnforcementPolicy::STRICT_ASSIGNMENT,
    ): void {
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
        $mission->setInstrumentist($newInstrumentist);
        if ($mission->getStatus() === MissionStatus::OPEN) {
            $mission->setStatus(MissionStatus::ASSIGNED);
        }
        $this->resetEscalationIfLeavingOpen($mission, $previousStatusEnum);

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

        $mission->setInstrumentist($newInstrumentist);
        // Status stays ASSIGNED

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
     */
    public function createPostDeploy(
        PlanningVersion $planningVersion,
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
        }
        $mission->setInstrumentist($instrumentist);
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

        if (!empty($blocking)) {
            throw new InstrumentistIneligibleException($blocking);
        }
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

    private function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
