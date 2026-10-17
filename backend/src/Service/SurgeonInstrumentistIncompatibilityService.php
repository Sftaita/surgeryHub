<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\MissionAssignment;
use App\Entity\PlanningAlert;
use App\Entity\SurgeonInstrumentistIncompatibility;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\EmploymentType;
use App\Enum\IncompatibilityOrigin;
use App\Enum\MissionStatus;
use App\Enum\PlanningAlertStatus;
use App\Enum\PlanningAlertType;
use App\Exception\IncompatibilityException;
use App\Message\PlanningAlertRaisedMessage;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-152 — the single writer of surgeon–instrumentist incompatibilities.
 *
 * Creating one never touches an existing affectation: every FUTURE mission of the surgeon
 * still ASSIGNED to the instrumentist becomes a SURGEON_INCOMPATIBILITY PlanningAlert (one
 * per mission, operational managers notified), which stays open until it is effectively
 * resolved (reassignment, opening to the pool, cancellation — MissionAssignmentService closes
 * it — or lifting the incompatibility). From then on MissionEligibilityService excludes the
 * instrumentist from every one of the surgeon's missions.
 *
 * Lifting never deletes: liftedAt/liftedBy are written once and the row stays as history.
 * The instrumentist is never told anything (no notification, no reason anywhere on her side).
 */
class SurgeonInstrumentistIncompatibilityService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditService $audit,
        private readonly PlanningAlertService $alerts,
        private readonly MessageBusInterface $bus,
        private readonly UserRepository $users,
    ) {}

    /** Own transaction; the conflict alerts are notified to the managers after commit. */
    public function create(User $surgeon, User $instrumentist, IncompatibilityOrigin $origin, User $actor): SurgeonInstrumentistIncompatibility
    {
        if ($origin === IncompatibilityOrigin::SURGEON && !$this->isBlockableBy($surgeon, $instrumentist)) {
            throw new IncompatibilityException(IncompatibilityException::NOT_BLOCKABLE);
        }

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            [$incompatibility, $raised] = $this->createInTransaction($surgeon, $instrumentist, $origin, $actor);
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e instanceof UniqueConstraintViolationException
                ? new IncompatibilityException(IncompatibilityException::ALREADY_ACTIVE)
                : $e;
        }

        $this->notifyConflicts($raised);

        return $incompatibility;
    }

    /**
     * Joins the caller's transaction (durable refusal, MissionPostDeployService). The caller
     * notifies the returned alerts after its commit (notifyConflicts()).
     *
     * @return array{0: SurgeonInstrumentistIncompatibility, 1: PlanningAlert[]}
     */
    public function createInTransaction(
        User $surgeon,
        User $instrumentist,
        IncompatibilityOrigin $origin,
        User $actor,
        ?MissionAssignment $sourceAssignment = null,
    ): array {
        $this->assertPair($surgeon, $instrumentist);

        $existing = $this->findActive($surgeon, $instrumentist);
        if ($existing !== null) {
            throw new IncompatibilityException(IncompatibilityException::ALREADY_ACTIVE);
        }

        $incompatibility = new SurgeonInstrumentistIncompatibility($surgeon, $instrumentist, $origin, $actor, Clock::get()->now(), $sourceAssignment);
        $this->em->persist($incompatibility);
        $this->em->flush(); // the unique active key refuses a concurrent twin right here

        $raised = $this->raiseConflicts($incompatibility);

        $this->audit->recordGlobal($actor, AuditEventType::SURGEON_INSTRUMENTIST_INCOMPATIBILITY_CREATED, $this->auditPayload($incompatibility, $actor) + [
            'origin'             => $origin->value,
            'sourceAssignmentId' => $sourceAssignment?->getId(),
            'conflictMissionIds' => array_map(static fn (PlanningAlert $a) => $a->getMission()->getId(), $raised),
        ]);
        $this->em->flush();

        return [$incompatibility, $raised];
    }

    /** Lifts it (once) and closes its still-open conflicts, in one transaction. */
    public function lift(SurgeonInstrumentistIncompatibility $incompatibility, User $actor): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $affected = $connection->executeStatement(
                'UPDATE surgeon_instrumentist_incompatibility SET lifted_at = ?, lifted_by_id = ? WHERE id = ? AND lifted_at IS NULL',
                [Clock::get()->now()->format('Y-m-d H:i:s'), $actor->getId(), $incompatibility->getId()],
                [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
            );
            if ($affected !== 1) {
                throw new IncompatibilityException(IncompatibilityException::ALREADY_LIFTED);
            }
            $this->em->refresh($incompatibility);

            foreach ($this->openConflicts($incompatibility) as $alert) {
                $alert->resolve($actor, "Conflit résolu : l'incompatibilité a été levée.");
            }

            $this->audit->recordGlobal($actor, AuditEventType::SURGEON_INSTRUMENTIST_INCOMPATIBILITY_LIFTED, $this->auditPayload($incompatibility, $actor) + [
                'origin' => $incompatibility->getOrigin()->value,
            ]);
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }
    }

    public function findActive(User $surgeon, User $instrumentist): ?SurgeonInstrumentistIncompatibility
    {
        return $this->em->getRepository(SurgeonInstrumentistIncompatibility::class)
            ->findOneBy(['surgeon' => $surgeon, 'instrumentist' => $instrumentist, 'liftedAt' => null]);
    }

    /** @return SurgeonInstrumentistIncompatibility[] newest first, active and lifted (history) */
    public function historyOf(User $person, bool $asSurgeon): array
    {
        return $this->em->getRepository(SurgeonInstrumentistIncompatibility::class)
            ->findBy([$asSurgeon ? 'surgeon' : 'instrumentist' => $person], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /** @return PlanningAlert[] the still-open conflicts of this incompatibility */
    public function openConflicts(SurgeonInstrumentistIncompatibility $incompatibility): array
    {
        return $this->em->createQuery(
            'SELECT a, m FROM App\Entity\PlanningAlert a JOIN a.mission m
             WHERE a.type = :type AND a.status IN (:active)
               AND m.surgeon = :surgeon AND m.instrumentist = :instrumentist
             ORDER BY m.startAt ASC'
        )
            ->setParameter('type', PlanningAlertType::SURGEON_INCOMPATIBILITY)
            ->setParameter('active', [PlanningAlertStatus::OPEN, PlanningAlertStatus::ACKNOWLEDGED])
            ->setParameter('surgeon', $incompatibility->getSurgeon())
            ->setParameter('instrumentist', $incompatibility->getInstrumentist())
            ->getResult();
    }

    /**
     * D-152 — whom a surgeon may block from his profile: active instrumentists of one of his
     * sites, or active freelancers (eligible on every site, D-057). Not already blocked.
     *
     * @return User[]
     */
    public function blockableInstrumentists(User $surgeon, string $query = '', int $limit = 20): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')->from(User::class, 'u')
            ->where('u.active = true AND u.roles LIKE :role')
            ->andWhere('u.employmentType = :freelancer OR EXISTS (
                SELECT smi.id FROM App\Entity\SiteMembership smi, App\Entity\SiteMembership sms
                WHERE smi.user = u AND sms.user = :surgeon AND sms.site = smi.site
            )')
            ->andWhere('NOT EXISTS (
                SELECT b.id FROM App\Entity\SurgeonInstrumentistIncompatibility b
                WHERE b.surgeon = :surgeon AND b.instrumentist = u AND b.liftedAt IS NULL
            )')
            ->setParameter('role', '%ROLE_INSTRUMENTIST%')
            ->setParameter('freelancer', EmploymentType::FREELANCER)
            ->setParameter('surgeon', $surgeon)
            ->orderBy('u.lastname', 'ASC')->addOrderBy('u.firstname', 'ASC')
            ->setMaxResults($limit);

        foreach (array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [])) as $i => $term) {
            $qb->andWhere("LOWER(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, ''))) LIKE :q{$i}")
                ->setParameter("q{$i}", '%' . addcslashes($term, '%_\\') . '%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * D-152 (réserve 1) — manager search of the counterparts that may be declared incompatible
     * with $person: active instrumentists for a surgeon's file, active surgeons for an
     * instrumentist's file, never one already actively incompatible with $person. Paginated
     * on the server (the browser never loads the whole list); every term of $query must match
     * the first name, last name or e-mail. Same population create() accepts from a manager.
     *
     * @return array{items: User[], total: int}
     */
    public function managerCandidates(User $person, bool $personIsSurgeon, string $query, int $page, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()
            ->from(User::class, 'u')
            ->where('u.active = true AND u.roles LIKE :role AND u <> :person')
            ->andWhere($personIsSurgeon
                ? 'NOT EXISTS (SELECT b.id FROM App\Entity\SurgeonInstrumentistIncompatibility b WHERE b.surgeon = :person AND b.instrumentist = u AND b.liftedAt IS NULL)'
                : 'NOT EXISTS (SELECT b.id FROM App\Entity\SurgeonInstrumentistIncompatibility b WHERE b.instrumentist = :person AND b.surgeon = u AND b.liftedAt IS NULL)')
            ->setParameter('role', $personIsSurgeon ? '%ROLE_INSTRUMENTIST%' : '%ROLE_SURGEON%')
            ->setParameter('person', $person);

        foreach (array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [])) as $i => $term) {
            $qb->andWhere("(LOWER(COALESCE(u.firstname, '')) LIKE :q{$i} OR LOWER(COALESCE(u.lastname, '')) LIKE :q{$i} OR LOWER(u.email) LIKE :q{$i})")
                ->setParameter("q{$i}", '%' . addcslashes($term, '%_\\') . '%');
        }

        $total = (int) (clone $qb)->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();
        $items = $qb->select('u')
            ->orderBy('u.lastname', 'ASC')->addOrderBy('u.firstname', 'ASC')->addOrderBy('u.id', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    public function isBlockableBy(User $surgeon, User $instrumentist): bool
    {
        if (!$instrumentist->isActive() || !in_array('ROLE_INSTRUMENTIST', $instrumentist->getRoles(), true)) {
            return false;
        }
        if ($instrumentist->getEmploymentType() === EmploymentType::FREELANCER) {
            return true;
        }

        return (int) $this->em->createQuery(
            'SELECT COUNT(smi.id) FROM App\Entity\SiteMembership smi, App\Entity\SiteMembership sms
             WHERE smi.user = :instrumentist AND sms.user = :surgeon AND sms.site = smi.site'
        )
            ->setParameter('instrumentist', $instrumentist)
            ->setParameter('surgeon', $surgeon)
            ->getSingleScalarResult() > 0;
    }

    /**
     * Operational managers (D-149) told about each newly raised conflict, through the existing
     * planning-alert notification (PLANNING_ALERT, dispatcher, idempotent per alert).
     *
     * @param PlanningAlert[] $raised
     */
    public function notifyConflicts(array $raised): void
    {
        if ($raised === []) {
            return;
        }
        $recipients = array_map(static fn (User $u) => (int) $u->getId(), $this->users->findOperationalManagers());

        foreach ($raised as $alert) {
            $mission = $alert->getMission();
            $this->bus->dispatch(new PlanningAlertRaisedMessage(
                alertId: (int) $alert->getId(),
                alertType: PlanningAlertType::SURGEON_INCOMPATIBILITY->value,
                missionId: (int) $mission->getId(),
                siteId: $mission->getSite()?->getId(),
                siteName: $mission->getSite()?->getName(),
                missionDate: (string) $mission->getStartAt()?->format('Y-m-d'),
                absenceId: null,
                surgeonId: (int) $mission->getSurgeon()?->getId(),
                instrumentistId: $mission->getInstrumentist()?->getId(),
                recipientUserIds: $recipients,
                detectedAt: $alert->getDetectedAt()->format(\DateTimeInterface::ATOM),
            ));
        }
    }

    /** @return PlanningAlert[] newly created alerts (one per future mission still affected to the pair) */
    private function raiseConflicts(SurgeonInstrumentistIncompatibility $incompatibility): array
    {
        /** @var Mission[] $missions */
        $missions = $this->em->createQuery(
            'SELECT m FROM App\Entity\Mission m
             WHERE m.surgeon = :surgeon AND m.instrumentist = :instrumentist
               AND m.status = :assigned AND m.startAt > :now
             ORDER BY m.startAt ASC'
        )
            ->setParameter('surgeon', $incompatibility->getSurgeon())
            ->setParameter('instrumentist', $incompatibility->getInstrumentist())
            ->setParameter('assigned', MissionStatus::ASSIGNED)
            // Labelled Europe/Brussels like the stored startAt (D-066).
            ->setParameter('now', Clock::get()->now()->setTimezone(new \DateTimeZone('Europe/Brussels')))
            // Locking read: sees an affectation committed after this transaction's first read
            // (a plain read would use the older REPEATABLE READ snapshot and miss a concurrent claim).
            ->setLockMode(LockMode::PESSIMISTIC_READ)
            ->getResult();

        $raised = [];
        foreach ($missions as $mission) {
            ['alert' => $alert, 'created' => $created] = $this->alerts->createIfNotDuplicate(
                $mission,
                PlanningAlertType::SURGEON_INCOMPATIBILITY,
                null,
                [
                    'incompatibilityId' => $incompatibility->getId(),
                    'surgeonId'         => $incompatibility->getSurgeon()->getId(),
                    'surgeonName'       => self::name($incompatibility->getSurgeon()),
                    'instrumentistId'   => $incompatibility->getInstrumentist()->getId(),
                    'instrumentistName' => self::name($incompatibility->getInstrumentist()),
                    'origin'            => $incompatibility->getOrigin()->value,
                    'missionStartAt'    => $mission->getStartAt()?->format(\DateTimeInterface::ATOM),
                    'missionSiteName'   => $mission->getSite()?->getName(),
                ],
            );
            if ($created) {
                $raised[] = $alert;
            }
        }
        $this->em->flush();

        return $raised;
    }

    private function assertPair(User $surgeon, User $instrumentist): void
    {
        if ($surgeon->getId() === $instrumentist->getId()
            || !in_array('ROLE_SURGEON', $surgeon->getRoles(), true)
            || !in_array('ROLE_INSTRUMENTIST', $instrumentist->getRoles(), true)) {
            throw new IncompatibilityException(IncompatibilityException::INVALID_PAIR);
        }
    }

    /** @return array<string, mixed> */
    private function auditPayload(SurgeonInstrumentistIncompatibility $incompatibility, User $actor): array
    {
        return [
            'incompatibilityId' => $incompatibility->getId(),
            'surgeonId'         => $incompatibility->getSurgeon()->getId(),
            'surgeonName'       => self::name($incompatibility->getSurgeon()),
            'instrumentistId'   => $incompatibility->getInstrumentist()->getId(),
            'instrumentistName' => self::name($incompatibility->getInstrumentist()),
            'actorId'           => $actor->getId(),
            'actorName'         => self::name($actor),
        ];
    }

    public static function name(?User $user): string
    {
        if ($user === null) {
            return '';
        }
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));

        return $name !== '' ? $name : (string) $user->getEmail();
    }
}
