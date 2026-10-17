<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\MissionAssignment;
use App\Entity\User;
use App\Enum\MissionAssignmentEndReason;
use App\Enum\MissionAssignmentSource;
use App\Enum\MissionStatus;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Clock\Clock;

/**
 * D-150 — single writer of Mission::$instrumentist on a published mission, and of its
 * MissionAssignment history.
 *
 * Called explicitly by the application services that already own each transition
 * (MissionPostDeployService, MissionService::declareMission(), PlanningAlertActionService),
 * which pass the business source themselves. Status changes, AuditEvents, flushes and
 * lifecycle messages stay with those callers (D-056) — this service only keeps
 * "who is affected to this mission, since when, from where" exact.
 *
 * Never used for DRAFT missions (planning being prepared — PlanningDraftService,
 * PlanningGeneratorServiceV2, MissionService::create()/assignInstrumentistDraft() keep
 * writing the DRAFT instrumentist directly): an affectation starts when the mission is
 * published. PlanningDeploymentService opens the affectations of the DRAFTs it publishes in
 * bulk (openForDeployedVersion()). The closed list of allowed direct writers is pinned by
 * MissionInstrumentistWriteArchitectureTest.
 */
class MissionAssignmentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Affects $instrumentist to $mission. The current affectation, if it is someone else's,
     * ends with $replacedReason. Re-affecting the person already affected changes nothing:
     * her affectation simply continues (no new row, her assignedAt is kept).
     */
    public function assign(
        Mission $mission,
        User $instrumentist,
        MissionAssignmentSource $source,
        ?User $actor,
        MissionAssignmentEndReason $replacedReason = MissionAssignmentEndReason::REPLACED,
    ): MissionAssignment {
        $current = $this->findCurrent($mission);
        if ($current !== null && $this->isSamePerson($current->getInstrumentist(), $instrumentist)) {
            $mission->setInstrumentist($instrumentist);

            return $current;
        }

        $now = Clock::get()->now();
        if ($current !== null) {
            $this->close($current, $replacedReason, $actor, $now);
        }

        $assignment = new MissionAssignment($mission, $instrumentist, $source, $actor, $now);
        $this->em->persist($assignment);
        $mission->setInstrumentist($instrumentist);

        return $assignment;
    }

    /** Takes the instrumentist off $mission; her current affectation (if any) ends with $reason. */
    public function unassign(Mission $mission, MissionAssignmentEndReason $reason, ?User $actor): ?MissionAssignment
    {
        $current = $this->findCurrent($mission);
        if ($current !== null) {
            $this->close($current, $reason, $actor, Clock::get()->now());
        }
        $mission->setInstrumentist(null);

        return $current;
    }

    /**
     * Ends the open affectation of a mission reaching a final status (REJECTED) while keeping
     * Mission::$instrumentist: encoding, billing and consultation keep reading who worked on
     * it. A final mission never has an open affectation.
     */
    public function endOnFinalStatus(Mission $mission, MissionAssignmentEndReason $reason, ?User $actor): ?MissionAssignment
    {
        $current = $this->findCurrent($mission);
        if ($current !== null) {
            $this->close($current, $reason, $actor, Clock::get()->now());
        }

        return $current;
    }

    /** The open affectation of $mission, including one created earlier in this unit of work. */
    public function findCurrent(Mission $mission): ?MissionAssignment
    {
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof MissionAssignment && $entity->getMission() === $mission && $entity->isActive()) {
                return $entity;
            }
        }

        if ($mission->getId() === null) {
            return null;
        }

        $found = $this->em->getRepository(MissionAssignment::class)->findOneBy(['mission' => $mission, 'endedAt' => null]);

        // In-memory state wins: an affectation ended earlier in this unit of work is no longer current.
        return $found instanceof MissionAssignment && $found->isActive() ? $found : null;
    }

    /** D-151 — $instrumentist had an affectation to $mission that has ended (minimal view, "Affectation annulée"). */
    public function hasEndedAffectationOf(Mission $mission, User $instrumentist): bool
    {
        if ($mission->getId() === null || $instrumentist->getId() === null) {
            return false;
        }

        return (int) $this->em->createQuery(
            'SELECT COUNT(a.id) FROM App\Entity\MissionAssignment a
             WHERE a.mission = :mission AND a.instrumentist = :instrumentist AND a.endedAt IS NOT NULL'
        )
            ->setParameter('mission', $mission)
            ->setParameter('instrumentist', $instrumentist)
            ->getSingleScalarResult() > 0;
    }

    /**
     * Runs $change — a mutation of $mission's affectation/status ending with its own flush —
     * inside a transaction holding the mission row lock. Joins the caller's transaction when
     * there is one (batch callers: PlanningModificationService, absence reactions).
     *
     * The lock is taken with a locking read that also returns the current committed status
     * and instrumentist: if they differ from what this request loaded, someone else changed
     * the mission in between and we answer 409 instead of overwriting their change. No
     * refresh(): it would silently discard changes the caller has not flushed yet.
     *
     * @template T
     * @param callable(): T $change
     * @return T
     */
    public function inLockedChange(Mission $mission, callable $change): mixed
    {
        $connection      = $this->em->getConnection();
        $ownsTransaction = !$connection->isTransactionActive();
        if ($ownsTransaction) {
            $connection->beginTransaction();
        }

        try {
            $this->lockAndVerify($mission);
            $result = $change();
            if ($ownsTransaction) {
                $connection->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }
    }

    /**
     * PlanningDeploymentService — opens, in one statement, the affectation of every mission of
     * $versionId that its bulk UPDATE just published as ASSIGNED and that has none yet. Must run
     * in the same transaction as that UPDATE.
     */
    public function openForDeployedVersion(int $versionId, ?User $deployedBy): int
    {
        return (int) $this->em->getConnection()->executeStatement(
            'INSERT INTO mission_assignment (mission_id, instrumentist_id, source, assigned_at, assigned_by_id)
             SELECT m.id, m.instrumentist_id, ?, ?, ?
             FROM mission m
             WHERE m.planning_version_id = ?
               AND m.status = ?
               AND m.instrumentist_id IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM mission_assignment a WHERE a.mission_id = m.id AND a.ended_at IS NULL)',
            [
                MissionAssignmentSource::PLANNING_DEPLOYMENT->value,
                Clock::get()->now(),
                $deployedBy?->getId(),
                $versionId,
                MissionStatus::ASSIGNED->value,
            ],
            [ParameterType::STRING, Types::DATETIME_IMMUTABLE, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING],
        );
    }

    private function lockAndVerify(Mission $mission): void
    {
        if ($mission->getId() === null) {
            return;
        }

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT status, instrumentist_id FROM mission WHERE id = ? FOR UPDATE',
            [$mission->getId()],
            [ParameterType::INTEGER],
        );
        if (!is_array($row) || !array_key_exists('status', $row)) {
            return;
        }

        $committedInstrumentistId = $row['instrumentist_id'] !== null ? (int) $row['instrumentist_id'] : null;
        if ($row['status'] !== $mission->getStatus()->value || $committedInstrumentistId !== $mission->getInstrumentist()?->getId()) {
            throw new ConflictHttpException('La mission a été modifiée entre-temps : rechargez-la avant de recommencer.');
        }
    }

    /**
     * Ends the affectation in memory and — when it is already in the database — at once,
     * conditionally on it still being open: freeing the unique active slot before the new
     * row is inserted (Doctrine flushes inserts before updates), and turning a concurrent
     * close of the same affectation into a 409 instead of a silent double end.
     */
    private function close(MissionAssignment $assignment, MissionAssignmentEndReason $reason, ?User $actor, \DateTimeImmutable $now): void
    {
        $assignment->end($reason, $actor, $now);

        if ($assignment->getId() === null) {
            return;
        }

        $connection = $this->em->getConnection();
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('An affectation can only be ended inside a transaction (see inLockedChange()).');
        }

        $affected = $connection->executeStatement(
            'UPDATE mission_assignment SET ended_at = ?, end_reason = ?, ended_by_id = ? WHERE id = ? AND ended_at IS NULL',
            [$now, $reason->value, $actor?->getId(), $assignment->getId()],
            [Types::DATETIME_IMMUTABLE, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
        );
        if ($affected !== 1) {
            throw new ConflictHttpException('Cette affectation a déjà été modifiée entre-temps : rechargez la mission.');
        }
    }

    private function isSamePerson(User $a, User $b): bool
    {
        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }
}
