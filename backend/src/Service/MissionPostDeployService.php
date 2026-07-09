<?php

namespace App\Service;

use App\Dto\EligibilityResult;
use App\Entity\Mission;
use App\Entity\MissionClaim;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\EligibilityReason;
use App\Enum\MissionChangeType;
use App\Enum\MissionStatus;
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
     */
    public function release(Mission $mission, User $actor): void
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
            'actorId'               => $actor->getId(),
            'actorName'             => $this->displayName($actor),
        ];

        $this->audit->record($mission, $actor, AuditEventType::MISSION_RELEASED_TO_POOL, $payload);

        $this->em->flush();  // R-05: flush before dispatch

        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId:  $mission->getId(),
            changeType: MissionChangeType::RELEASED,
            actorId:    $actor->getId(),
            payload:    $payload,
            occurredAt: new \DateTimeImmutable(),
        ));
    }

    /**
     * OPEN → CANCELLED.
     * Throws 409 if mission is not OPEN.
     */
    public function cancel(Mission $mission, User $actor, ?string $reason = null): void
    {
        if ($mission->getStatus() !== MissionStatus::OPEN) {
            throw new ConflictHttpException('Mission must be OPEN to cancel');
        }

        $mission->setStatus(MissionStatus::CANCELLED);

        $payload = [
            'reason'   => $reason,
            'actorId'  => $actor->getId(),
            'actorName'=> $this->displayName($actor),
        ];

        $this->audit->record($mission, $actor, AuditEventType::MISSION_CANCELLED_POST_DEPLOY, $payload);

        $this->em->flush();  // R-05: flush before dispatch

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
    public function assign(Mission $mission, User $actor, int $newInstrumentistId): void
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
     */
    public function reassign(Mission $mission, User $actor, int $newInstrumentistId): void
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

        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId:  $mission->getId(),
            changeType: MissionChangeType::REASSIGNED,
            actorId:    $actor->getId(),
            payload:    $payload,
            occurredAt: new \DateTimeImmutable(),
        ));
    }

    private function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
