<?php

namespace App\Service;

use App\Entity\Absence;
use App\Entity\Mission;
use App\Entity\PlanningAlert;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\PlanningAlertStatus;
use App\Enum\PlanningAlertType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Shared alert idempotency/resolution logic used by both AbsenceImpactService and
 * PlanningOccurrenceExceptionService, so "don't create a duplicate" and "resolve, never
 * silently delete" are implemented exactly once.
 *
 * An alert is never auto-resolved by deleting the row — resolution always sets status +
 * resolvedAt + resolutionNote, preserving full history.
 */
class PlanningAlertService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Returns the existing OPEN/ACKNOWLEDGED alert for this exact (mission, type, absence)
     * combination, if one already exists — the anti-duplicate guard.
     */
    public function findActiveAlert(Mission $mission, PlanningAlertType $type, ?Absence $absence): ?PlanningAlert
    {
        $qb = $this->em->createQueryBuilder()
            ->select('a')
            ->from(PlanningAlert::class, 'a')
            ->where('a.mission = :mission')
            ->andWhere('a.type = :type')
            ->andWhere('a.status IN (:openStatuses)')
            ->setParameter('mission', $mission)
            ->setParameter('type', $type)
            ->setParameter('openStatuses', [PlanningAlertStatus::OPEN, PlanningAlertStatus::ACKNOWLEDGED]);

        if ($absence !== null) {
            $qb->andWhere('a.absence = :absence')->setParameter('absence', $absence);
        } else {
            $qb->andWhere('a.absence IS NULL');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Idempotent creation: if an active alert already covers this exact situation, returns it
     * unchanged (created=false). Otherwise persists a new PlanningAlert (created=true).
     *
     * @return array{alert: PlanningAlert, created: bool}
     */
    public function createIfNotDuplicate(Mission $mission, PlanningAlertType $type, ?Absence $absence, array $snapshot): array
    {
        $existing = $this->findActiveAlert($mission, $type, $absence);
        if ($existing !== null) {
            return ['alert' => $existing, 'created' => false];
        }

        $alert = new PlanningAlert();
        $alert->setMission($mission);
        $alert->setType($type);
        $alert->setAbsence($absence);
        $alert->setSnapshotJson($snapshot);
        $this->em->persist($alert);

        return ['alert' => $alert, 'created' => true];
    }

    /** @return PlanningAlert[] all OPEN/ACKNOWLEDGED alerts currently tied to this absence. */
    public function findActiveAlertsForAbsence(Absence $absence): array
    {
        return $this->em->createQueryBuilder()
            ->select('a')
            ->from(PlanningAlert::class, 'a')
            ->where('a.absence = :absence')
            ->andWhere('a.status IN (:openStatuses)')
            ->setParameter('absence', $absence)
            ->setParameter('openStatuses', [PlanningAlertStatus::OPEN, PlanningAlertStatus::ACKNOWLEDGED])
            ->getQuery()
            ->getResult();
    }

    /**
     * Resolves (never deletes) every active alert tied to this absence — used when the
     * absence is deleted or no longer overlaps any mission.
     *
     * @return PlanningAlert[] the alerts that were resolved
     */
    public function resolveAllForAbsence(Absence $absence, string $note, ?User $by = null): array
    {
        $alerts = $this->findActiveAlertsForAbsence($absence);
        foreach ($alerts as $alert) {
            $alert->resolve($by, $note);
        }
        return $alerts;
    }

    // ── Manager-facing transitions (Batch 4) ─────────────────────────────────
    //
    // Same-state re-application is idempotent (returns changed=false, alert untouched —
    // the FIRST resolution/ignore wins and its resolvedBy/note/resolvedAt are preserved).
    // Crossing between the two terminal states (RESOLVED <-> IGNORED) is rejected as a
    // genuine conflict (409) — that is a deliberate decision, not a repeat of the same one.

    /** @return bool true if the alert's status actually changed */
    public function acknowledge(PlanningAlert $alert, User $by): bool
    {
        return match ($alert->getStatus()) {
            PlanningAlertStatus::OPEN => $this->mutate($alert, fn () => $alert->acknowledge($by)),
            PlanningAlertStatus::ACKNOWLEDGED => false,
            PlanningAlertStatus::RESOLVED, PlanningAlertStatus::IGNORED => throw new ConflictHttpException(sprintf(
                "Impossible d'acquitter une alerte déjà %s.", $alert->getStatus()->value
            )),
        };
    }

    /** @return bool true if the alert's status actually changed */
    public function resolve(PlanningAlert $alert, ?User $by, string $note): bool
    {
        return match ($alert->getStatus()) {
            PlanningAlertStatus::OPEN, PlanningAlertStatus::ACKNOWLEDGED => $this->mutate($alert, fn () => $alert->resolve($by, $note)),
            PlanningAlertStatus::RESOLVED => false,
            PlanningAlertStatus::IGNORED => throw new ConflictHttpException("Impossible de résoudre une alerte déjà ignorée."),
        };
    }

    /** @return bool true if the alert's status actually changed */
    public function ignore(PlanningAlert $alert, User $by, string $note): bool
    {
        return match ($alert->getStatus()) {
            PlanningAlertStatus::OPEN, PlanningAlertStatus::ACKNOWLEDGED => $this->mutate($alert, fn () => $alert->ignore($by, $note)),
            PlanningAlertStatus::IGNORED => false,
            PlanningAlertStatus::RESOLVED => throw new ConflictHttpException("Impossible d'ignorer une alerte déjà résolue."),
        };
    }

    private function mutate(PlanningAlert $alert, callable $mutator): bool
    {
        $mutator();
        return true;
    }

    // ── Search (Batch 4) ──────────────────────────────────────────────────────

    /**
     * @param array{status?: PlanningAlertStatus, type?: PlanningAlertType, siteId?: int,
     *              surgeonId?: int, instrumentistId?: int, missionStatus?: MissionStatus,
     *              from?: \DateTimeImmutable, to?: \DateTimeImmutable} $filters
     * @return array{items: PlanningAlert[], total: int}
     */
    public function search(array $filters, int $page, int $limit): array
    {
        $apply = function (QueryBuilder $qb) use ($filters): QueryBuilder {
            $qb->join('a.mission', 'm');

            if (isset($filters['status'])) {
                $qb->andWhere('a.status = :status')->setParameter('status', $filters['status']);
            }
            if (isset($filters['type'])) {
                $qb->andWhere('a.type = :type')->setParameter('type', $filters['type']);
            }
            if (isset($filters['siteId'])) {
                $qb->andWhere('m.site = :siteId')->setParameter('siteId', $filters['siteId']);
            }
            if (isset($filters['surgeonId'])) {
                $qb->andWhere('m.surgeon = :surgeonId')->setParameter('surgeonId', $filters['surgeonId']);
            }
            if (isset($filters['instrumentistId'])) {
                $qb->andWhere('m.instrumentist = :instrumentistId')->setParameter('instrumentistId', $filters['instrumentistId']);
            }
            if (isset($filters['missionStatus'])) {
                $qb->andWhere('m.status = :missionStatus')->setParameter('missionStatus', $filters['missionStatus']);
            }
            if (isset($filters['from'])) {
                $qb->andWhere('m.startAt >= :from')->setParameter('from', $filters['from']);
            }
            if (isset($filters['to'])) {
                $qb->andWhere('m.startAt <= :to')->setParameter('to', $filters['to']);
            }

            return $qb;
        };

        $total = (int) $apply(
            $this->em->createQueryBuilder()->select('COUNT(a.id)')->from(PlanningAlert::class, 'a')
        )->getQuery()->getSingleScalarResult();

        $items = $apply(
            $this->em->createQueryBuilder()->select('a', 'm')->from(PlanningAlert::class, 'a')
                ->orderBy('a.detectedAt', 'DESC')
                ->setFirstResult(($page - 1) * $limit)
                ->setMaxResults($limit)
        )->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    // ── Serialization & action-flag recommendations (Batch 4) ───────────────

    public function serialize(PlanningAlert $alert): array
    {
        $mission = $alert->getMission();
        $absence = $alert->getAbsence();
        $resolvedBy = $alert->getResolvedBy();

        return [
            'id'             => $alert->getId(),
            'type'           => $alert->getType()->value,
            'status'         => $alert->getStatus()->value,
            'detectedAt'     => $alert->getDetectedAt()->format(\DateTimeInterface::ATOM),
            'resolvedAt'     => $alert->getResolvedAt()?->format(\DateTimeInterface::ATOM),
            'resolvedBy'     => $resolvedBy !== null ? $this->serializeUserRef($resolvedBy) : null,
            'resolutionNote' => $alert->getResolutionNote(),
            'mission'        => $this->serializeMission($mission),
            'absence'        => $absence !== null ? [
                'id'        => $absence->getId(),
                'dateStart' => $absence->getDateStart()->format('Y-m-d'),
                'dateEnd'   => $absence->getDateEnd()->format('Y-m-d'),
                'reason'    => $absence->getReason(),
            ] : null,
            'actions' => $this->computeActionFlags($alert),
        ];
    }

    private function serializeMission(Mission $mission): array
    {
        $site           = $mission->getSite();
        $surgeon        = $mission->getSurgeon();
        $instrumentist  = $mission->getInstrumentist();

        return [
            'id'             => $mission->getId(),
            'status'         => $mission->getStatus()->value,
            'startAt'        => $mission->getStartAt()->format(\DateTimeInterface::ATOM),
            'endAt'          => $mission->getEndAt()->format(\DateTimeInterface::ATOM),
            'site'           => $site !== null ? ['id' => $site->getId(), 'name' => $site->getName()] : null,
            'surgeon'        => $surgeon !== null ? $this->serializeUserRef($surgeon) : null,
            'instrumentist'  => $instrumentist !== null ? $this->serializeUserRef($instrumentist) : null,
        ];
    }

    /** Stable {id, email, name} shape — matches the convention already used by absences and eligible-instrumentists. */
    private function serializeUserRef(User $user): array
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return [
            'id'    => $user->getId(),
            'email' => $user->getEmail(),
            'name'  => $name !== '' ? $name : $user->getEmail(),
        ];
    }

    /**
     * Advisory flags only — no endpoint mutates a Mission yet (reassign/open-as-available
     * actions are a future batch). These describe what WOULD make sense given the alert's
     * current type/status and the mission's current status.
     *
     * @return array{canAcknowledge: bool, canResolve: bool, canIgnore: bool, canReassign: bool, canOpenAsAvailable: bool, recommendedAction: string}
     */
    public function computeActionFlags(PlanningAlert $alert): array
    {
        $status = $alert->getStatus();
        $active = $status === PlanningAlertStatus::OPEN || $status === PlanningAlertStatus::ACKNOWLEDGED;

        $mission        = $alert->getMission();
        $missionMutable = in_array($mission->getStatus(), [MissionStatus::DRAFT, MissionStatus::OPEN, MissionStatus::ASSIGNED], true);

        $canReassign = $active && $missionMutable && in_array($alert->getType(), [
            PlanningAlertType::REASSIGNMENT_REQUIRED,
            PlanningAlertType::INSTRUMENTIST_ABSENCE,
        ], true);

        $canOpenAsAvailable = $active && $missionMutable
            && $mission->getStatus() === MissionStatus::ASSIGNED
            && in_array($alert->getType(), [
                PlanningAlertType::REASSIGNMENT_REQUIRED,
                PlanningAlertType::INSTRUMENTIST_ABSENCE,
                PlanningAlertType::SURGEON_ABSENCE,
            ], true);

        return [
            'canAcknowledge'     => $status === PlanningAlertStatus::OPEN,
            'canResolve'         => $active,
            'canIgnore'          => $active,
            'canReassign'        => $canReassign,
            'canOpenAsAvailable' => $canOpenAsAvailable,
            'recommendedAction'  => $this->recommendAction($alert, $active),
        ];
    }

    private function recommendAction(PlanningAlert $alert, bool $active): string
    {
        if (!$active) {
            return 'NONE';
        }

        return match ($alert->getType()) {
            PlanningAlertType::REASSIGNMENT_REQUIRED => 'REASSIGN',
            PlanningAlertType::INSTRUMENTIST_ABSENCE => $alert->getMission()->getStatus() === MissionStatus::OPEN ? 'NONE' : 'REVIEW',
            PlanningAlertType::SURGEON_ABSENCE,
            PlanningAlertType::OCCURRENCE_CANCELLED,
            PlanningAlertType::SURGEON_CONFLICT,
            PlanningAlertType::INSTRUMENTIST_CONFLICT => 'REVIEW',
        };
    }
}
