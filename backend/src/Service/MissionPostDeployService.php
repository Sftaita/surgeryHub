<?php

namespace App\Service;

use App\Dto\EligibilityResult;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionClaim;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\EligibilityReason;
use App\Enum\MissionChangeType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
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
     */
    public function release(Mission $mission, User $actor, bool $notify = true, ?string $reason = null): void
    {
        if ($mission->getStatus() !== MissionStatus::ASSIGNED) {
            throw new ConflictHttpException('Mission must be ASSIGNED to release');
        }

        $fromInstrumentist     = $mission->getInstrumentist();
        $fromInstrumentistId   = $fromInstrumentist?->getId();
        $fromInstrumentistName = $fromInstrumentist !== null
            ? $this->displayName($fromInstrumentist)
            : null;

        $mission->setStatus(MissionStatus::OPEN);
        $mission->setInstrumentist(null);

        $payload = [
            'fromInstrumentistId'   => $fromInstrumentistId,
            'fromInstrumentistName' => $fromInstrumentistName,
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

            $mission->setStatus(MissionStatus::IN_PROGRESS);

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
     */
    public function cancel(Mission $mission, User $actor, ?string $reason = null, bool $notify = true): void
    {
        if (!in_array($mission->getStatus(), [MissionStatus::OPEN, MissionStatus::ASSIGNED], true)) {
            throw new ConflictHttpException('Mission must be OPEN or ASSIGNED to cancel');
        }

        $fromInstrumentist     = $mission->getInstrumentist();
        $fromInstrumentistId   = $fromInstrumentist?->getId();
        $fromInstrumentistName = $fromInstrumentist !== null
            ? $this->displayName($fromInstrumentist)
            : null;

        $mission->setStatus(MissionStatus::CANCELLED);
        $mission->setInstrumentist(null);

        $payload = [
            'reason'                => $reason,
            'fromInstrumentistId'   => $fromInstrumentistId,
            'fromInstrumentistName' => $fromInstrumentistName,
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
            $labels  = array_map(fn (EligibilityReason $r) => $r->label(), $eligibility->reasons);
            throw new ConflictHttpException('Not eligible to claim this mission: ' . implode(', ', $labels));
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

                $existingClaim = $this->em->getRepository(MissionClaim::class)
                    ->findOneBy(['mission' => $mission]);
                if ($existingClaim !== null) {
                    throw new ConflictHttpException('Mission already claimed');
                }

                $claim = new MissionClaim();
                $claim
                    ->setMission($mission)
                    ->setInstrumentist($actor)
                    ->setClaimedAt(new \DateTimeImmutable());

                $mission->setInstrumentist($actor);
                $mission->setStatus(MissionStatus::ASSIGNED);

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
    public function assign(Mission $mission, User $actor, int $newInstrumentistId, bool $notify = true): void
    {
        if (!in_array($mission->getStatus(), [MissionStatus::OPEN, MissionStatus::ASSIGNED], true)) {
            throw new ConflictHttpException('Mission must be OPEN or ASSIGNED to assign');
        }

        $newInstrumentist = $this->em->find(User::class, $newInstrumentistId);
        if ($newInstrumentist === null) {
            throw new NotFoundHttpException('Instrumentist not found');
        }

        $fromInstrumentist     = $mission->getInstrumentist();
        $fromInstrumentistId   = $fromInstrumentist?->getId();
        $fromInstrumentistName = $fromInstrumentist !== null
            ? $this->displayName($fromInstrumentist)
            : null;

        $mission->setInstrumentist($newInstrumentist);
        if ($mission->getStatus() === MissionStatus::OPEN) {
            $mission->setStatus(MissionStatus::ASSIGNED);
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
    public function reassign(Mission $mission, User $actor, int $newInstrumentistId, bool $notify = true): void
    {
        if ($mission->getStatus() !== MissionStatus::ASSIGNED) {
            throw new ConflictHttpException('Mission must be ASSIGNED to reassign');
        }

        $newInstrumentist = $this->em->find(User::class, $newInstrumentistId);
        if ($newInstrumentist === null) {
            throw new NotFoundHttpException('Instrumentist not found');
        }

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
            ->setInstrumentist($instrumentist)
            ->setCreatedBy($actor)
            ->setStatus($instrumentist !== null ? MissionStatus::ASSIGNED : MissionStatus::OPEN)
            ->setStartAt($startAt)
            ->setEndAt($endAt);

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

    private function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
