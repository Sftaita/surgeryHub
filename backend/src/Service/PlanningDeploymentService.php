<?php

namespace App\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningDeployment;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\PlanningDeploymentStatus;
use App\Enum\PlanningVersionStatus;
use App\Message\PlanningDeployPdfsMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class PlanningDeploymentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {}

    /**
     * Deploy a PlanningVersion — only fast DB operations run synchronously:
     *  1. Archive the currently ACTIVE version (if any)
     *  2. Activate the target version (DRAFT → ACTIVE)
     *  3. Publish missions in two separate bulk UPDATEs:
     *       a. DRAFT + instrumentist IS NOT NULL  → ASSIGNED
     *       b. DRAFT + instrumentist IS NULL + id IN selectedUncoveredIds → OPEN
     *       c. DRAFT + instrumentist IS NULL + not selected → stays DRAFT
     *  4. Record PlanningDeployment (status = PENDING)
     *  5. Flush
     *  6. Dispatch PlanningDeployPdfsMessage for async PDF/email/notification work
     *
     * The HTTP request returns immediately after step 5.
     * All heavy work (PDFs, emails, notifications) runs in the Messenger worker.
     *
     * @param array<int> $selectedUncoveredMissionIds IDs of uncovered missions the manager chose to publish as pool
     * @return array{deploymentId: ?int, missionCount: int, openPoolCount: int}
     */
    public function deploy(
        string $from,
        string $to,
        ?int $siteId,
        User $deployedBy,
        ?int $versionId = null,
        array $selectedUncoveredMissionIds = [],
    ): array {
        $fromDate = new \DateTimeImmutable($from);
        $toDate   = new \DateTimeImmutable($to);

        // ── 1. Resolve the target PlanningVersion ─────────────────────────────
        $version = null;
        if ($versionId !== null) {
            $version = $this->em->find(PlanningVersion::class, $versionId);
        }

        // ── 2. Archive the current ACTIVE version (if any) ───────────────────
        if ($version !== null) {
            $previousActive = $this->findActiveVersion($version->getSite()?->getId(), $fromDate, $toDate);
            if ($previousActive !== null && $previousActive->getId() !== $version->getId()) {
                $previousActive->setStatus(PlanningVersionStatus::ARCHIVED);
                $previousActive->setArchivedAt(new \DateTimeImmutable());
            }

            // ── 3. Activate the target version ────────────────────────────────
            $version->setStatus(PlanningVersionStatus::ACTIVE);
            $version->setDeployedAt(new \DateTimeImmutable());

            // Flush now, before the bulk-update/clear block below. Doctrine ORM 3.x's
            // EntityManager::clear() takes no arguments and always clears the ENTIRE
            // identity map — the `Mission::class` argument passed to it further down is
            // silently ignored. Without this flush, $version's ACTIVE status would be
            // detached and lost before ever reaching the database (found via Batch 9's
            // functional deploy test; a real EntityManager — not a mock — is what
            // surfaces this kind of bug. Affects V1 and V2 equally; this service is
            // reused unchanged by both, see docs/planning-v2-architecture-freeze.md §D).
            $this->em->flush();
        }

        // ── 4. Publish missions — two targeted bulk UPDATEs ──────────────────
        $openMissionIds = [];
        if ($version !== null) {
            // 4a. Pre-assigned missions (instrumentist already set) → ASSIGNED.
            //     These instrumentists own their mission immediately; no pool claim needed.
            $assignedCount = (int) $this->em->createQuery(
                'UPDATE App\Entity\Mission m SET m.status = :assigned
                 WHERE m.planningVersion = :v AND m.status = :draft AND m.instrumentist IS NOT NULL'
            )
                ->setParameter('assigned', MissionStatus::ASSIGNED)
                ->setParameter('v',        $version)
                ->setParameter('draft',    MissionStatus::DRAFT)
                ->execute();

            // 4b. V2 rule (Batch 15A): ALL uncovered DRAFT missions → OPEN automatically.
            //     No manual selection required — the manager already reviewed assignments
            //     in the Preview Editor. Every DRAFT without an instrumentist goes to pool.
            //     $selectedUncoveredMissionIds is intentionally ignored for V2 path.
            $poolCount = (int) $this->em->createQuery(
                'UPDATE App\Entity\Mission m SET m.status = :open
                 WHERE m.planningVersion = :v AND m.status = :draft AND m.instrumentist IS NULL'
            )
                ->setParameter('open',  MissionStatus::OPEN)
                ->setParameter('v',     $version)
                ->setParameter('draft', MissionStatus::DRAFT)
                ->execute();

            $this->em->clear(Mission::class);
            $missionCount = $assignedCount + $poolCount;

            // 4c. Capture the IDs of newly-opened missions so the async handler can
            //     fan out OPEN_MISSION_AVAILABLE notifications to eligible instrumentists.
            //     Must run after em->clear() because the bulk UPDATE bypassed Doctrine's map.
            if ($poolCount > 0) {
                $rows = $this->em->createQuery(
                    'SELECT m.id FROM App\Entity\Mission m
                     WHERE m.planningVersion = :v AND m.status = :open'
                )
                    ->setParameter('v',    $version)
                    ->setParameter('open', MissionStatus::OPEN)
                    ->getResult();
                $openMissionIds = array_column($rows, 'id');
            }
        } else {
            // Legacy fallback: no versionId — bulk UPDATE by date range.
            // All missions in the period that are DRAFT become OPEN (old behaviour preserved).
            $dql = 'UPDATE App\Entity\Mission m SET m.status = :open
                    WHERE m.startAt >= :from AND m.startAt <= :to AND m.status = :draft';

            if ($siteId !== null) {
                $dql .= ' AND m.site = :siteId';
            }

            $q = $this->em->createQuery($dql)
                ->setParameter('open',  MissionStatus::OPEN)
                ->setParameter('from',  $fromDate->setTime(0, 0, 0))
                ->setParameter('to',    $toDate->setTime(23, 59, 59))
                ->setParameter('draft', MissionStatus::DRAFT);

            if ($siteId !== null) {
                $site = $this->em->find(Hospital::class, $siteId);
                if ($site !== null) {
                    $q->setParameter('siteId', $site);
                }
            }

            $missionCount = (int) $q->execute();
            $poolCount    = $missionCount; // legacy: all published as "open"
            $this->em->clear(Mission::class);
            $openMissionIds = $selectedUncoveredMissionIds; // V1: carry manager-selected IDs
        }

        // ── 5. Record the deployment (PENDING — worker will update to DONE/FAILED) ──
        $deployment = new PlanningDeployment();
        $deployment->setPeriodFrom($fromDate);
        $deployment->setPeriodTo($toDate);
        $deployment->setStatus(PlanningDeploymentStatus::PENDING);

        // After em->clear(), $deployedBy may be detached from the identity map.
        // getReference() returns a managed proxy without hitting the DB.
        $deployment->setDeployedBy(
            $this->em->getReference(User::class, $deployedBy->getId())
        );

        if ($siteId !== null) {
            $site = $this->em->find(Hospital::class, $siteId);
            $deployment->setSite($site ?? null);
        }

        $this->em->persist($deployment);
        $this->em->flush();

        // ── 6. Dispatch async PDF + email + notifications ─────────────────────
        // flush() above populates $deployment->getId() via Doctrine's identity map.
        // V2 path: openUncoveredIds carries the IDs fetched in step 4c — non-empty when any
        //   missions were auto-published to pool.  Enables OPEN_MISSION_AVAILABLE fan-out.
        // V1 legacy path: openUncoveredIds carries the manager-selected IDs as before.
        $this->bus->dispatch(new PlanningDeployPdfsMessage(
            from:              $from,
            to:                $to,
            siteId:            $siteId,
            deployedById:      $deployedBy->getId(),
            deploymentId:      $deployment->getId(),
            openUncoveredIds:  $openMissionIds,
        ));

        return [
            'deploymentId'  => $deployment->getId(),
            'missionCount'  => $missionCount,
            'openPoolCount' => $poolCount,
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function findActiveVersion(?int $siteId, \DateTimeImmutable $from, \DateTimeImmutable $to): ?PlanningVersion
    {
        $qb = $this->em->createQueryBuilder()
            ->select('v')
            ->from(PlanningVersion::class, 'v')
            ->where('v.status = :active')
            ->andWhere('v.periodStart <= :to')
            ->andWhere('v.periodEnd >= :from')
            ->setParameter('active', PlanningVersionStatus::ACTIVE)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setMaxResults(1);

        if ($siteId !== null) {
            $qb->andWhere('v.site = :siteId')->setParameter('siteId', $siteId);
        } else {
            $qb->andWhere('v.site IS NULL');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }
}
