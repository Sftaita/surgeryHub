<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\PlanningOccurrenceException;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\OccurrenceExceptionSource;
use App\Enum\PlanningAlertType;
use App\Enum\PlanningVersionStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * D-106 (Lot 6) — manual "Vérifier les conflits" scan: a safety net that re-audits an
 * already-ACTIVE PlanningVersion's CURRENT state against the same rules the automatic
 * pipelines already enforce, catching whatever slipped through (historical data, a race
 * condition, a gap in another lot). Never regenerates the planning, never mutates a Mission
 * directly — every correction goes through the exact same operational services
 * (AbsenceMissionReactionService, AbsenceImpactReconciliationService,
 * PlanningConflictDetectionService, PlanningAlertService) already used elsewhere in the
 * application for the same situation. No business rule from Lots 1-5 is reinterpreted here.
 *
 * Two kinds of finding, deliberately never conflated:
 *   - Objective facts (a person is currently absent per the Absence table, a restoration
 *     that should have already happened per the Lot 4 rules never did) — corrected
 *     automatically, exactly as if the triggering event had fired normally.
 *   - Judgment calls (a schedule conflict, an instrumentist who became inactive) — never
 *     mutated; only alerted. The manager decides.
 *
 * Idempotent by construction: every mutation reused here is already individually idempotent
 * (status guards inside MissionPostDeployService, createIfNotDuplicate()/resolve() inside
 * PlanningAlertService) — a second scan against unchanged data finds nothing left to do.
 *
 * Transactions/concurrency: each mission's correction is its own already-self-contained,
 * pessimistically-locked transaction (inside AbsenceMissionReactionService/
 * MissionPostDeployService, unchanged) — never one giant transaction spanning the whole
 * version. One mission failing (logged, skipped) never aborts the rest of the scan; two
 * managers scanning concurrently are serialized the same way any other concurrent mutation
 * of the same mission already is, by those same existing locks.
 */
class PlanningVersionAuditService
{
    /** Missions worth re-examining: OPEN/ASSIGNED for absence/conflict/inactive checks, CANCELLED for forgotten-restoration. */
    private const CHECKABLE_STATUSES = [MissionStatus::OPEN, MissionStatus::ASSIGNED, MissionStatus::CANCELLED];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AbsenceMissionReactionService $missionReactionService,
        private readonly AbsenceImpactReconciliationService $reconciliationService,
        private readonly PlanningConflictDetectionService $conflictDetectionService,
        private readonly PlanningAlertService $alertService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{
     *   checkedMissions: int, issuesFound: int, automaticCorrections: int,
     *   alertsCreated: int, alertsResolved: int, issues: array<int, array<string, mixed>>,
     * }
     */
    public function verify(PlanningVersion $version, User $actor): array
    {
        if ($version->getStatus() !== PlanningVersionStatus::ACTIVE) {
            throw new ConflictHttpException(sprintf(
                "Impossible de vérifier les conflits d'une version %s. Seule une version ACTIVE peut être vérifiée.",
                $version->getStatus()->value,
            ));
        }

        $issues                = [];
        $checkedMissions       = 0;
        $automaticCorrections  = 0;
        $alertsCreated         = 0;
        $alertsResolved        = 0;

        /** @var Mission[] $missions */
        $missions = $this->em->createQueryBuilder()
            ->select('m')->from(Mission::class, 'm')
            ->where('m.planningVersion = :version')
            ->andWhere('m.status IN (:statuses)')
            ->setParameter('version', $version)
            ->setParameter('statuses', self::CHECKABLE_STATUSES)
            ->getQuery()
            ->getResult();

        foreach ($missions as $mission) {
            $checkedMissions++;

            try {
                // A/B — surgeon or instrumentist currently absent, mission still reflects
                // the pre-absence state (the objective-fact category, §11/§12).
                $reaction = $this->missionReactionService->reconcileMissionAgainstCurrentAbsences($mission, $actor);
                if ($reaction !== null) {
                    $automaticCorrections++;
                    $issues[] = [
                        'type'      => $reaction['changeType'] === 'CANCELLED' ? 'SURGEON_ABSENCE' : 'INSTRUMENTIST_ABSENCE',
                        'missionId' => $mission->getId(),
                        'action'    => $reaction['changeType'] === 'CANCELLED' ? 'CANCELLED' : 'RELEASED_TO_POOL',
                    ];
                    continue;
                }

                // E — forgotten restoration after a surgeon absence was deleted/shrunk.
                if ($mission->getStatus() === MissionStatus::CANCELLED) {
                    $restored = $this->reconciliationService->reconcileCancelledMissionIfNoLongerJustified($mission, $actor);
                    if ($restored !== null) {
                        $automaticCorrections++;
                        $issues[] = [
                            'type'      => 'FORGOTTEN_RESTORATION',
                            'missionId' => $mission->getId(),
                            'action'    => $restored['restoredStatus'] === 'ASSIGNED' ? 'RESTORED_ASSIGNED' : 'RESTORED_OPEN',
                        ];
                    }
                    continue;
                }

                // E — forgotten restoration after an instrumentist absence was deleted/shrunk.
                if ($mission->getStatus() === MissionStatus::OPEN) {
                    $restored = $this->reconciliationService->reconcileReleasedMissionIfNoLongerJustified($mission, $actor);
                    if ($restored !== null) {
                        $automaticCorrections++;
                        $issues[] = ['type' => 'FORGOTTEN_RESTORATION', 'missionId' => $mission->getId(), 'action' => 'RESTORED_ASSIGNED'];
                        continue;
                    }
                }

                // D — instrumentist became inactive since assignment. Alert-only (user
                // decision, D-106) — never a mutation, unlike A/B/E above.
                if ($mission->getStatus() === MissionStatus::ASSIGNED) {
                    $alertDelta = $this->syncInactiveAlert($mission, $actor);
                    $alertsCreated  += $alertDelta['created'];
                    $alertsResolved += $alertDelta['resolved'];
                    if ($alertDelta['created'] > 0) {
                        $issues[] = ['type' => 'INSTRUMENTIST_INACTIVE', 'missionId' => $mission->getId(), 'action' => 'ALERT_CREATED'];
                    }
                }

                // C — schedule conflict, same-site/cross-site/cross-PlanningVersion, fully
                // reused from PlanningConflictDetectionService (D-091) — never reimplemented.
                if (in_array($mission->getStatus(), PlanningConflictDetectionService::ACTIVE_STATUSES, true)) {
                    $conflictResult = $this->conflictDetectionService->syncAlertsForMission($mission);
                    $alertsCreated  += count($conflictResult['created']);
                    $alertsResolved += count($conflictResult['resolved']);
                    foreach ($conflictResult['created'] as $alert) {
                        $snapshot = $alert->getSnapshotJson();
                        $issues[] = [
                            'type'                 => $alert->getType()->value,
                            'missionId'            => $alert->getMission()->getId(),
                            'conflictingMissionId' => $snapshot['conflictingMissionId'] ?? null,
                            'action'               => 'ALERT_CREATED',
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('PlanningVersionAuditService: mission check failed', [
                    'missionId' => $mission->getId(),
                    'error'     => $e->getMessage(),
                ]);
                // One bad mission must never abort the rest of the scan (§33) — a partial,
                // logged, controlled result beats a 500 that hides everything else checked.
            }
        }

        // F — forgotten occurrence restoration (no Mission generated yet), scoped to this
        // version's site+period — "if pertinent" per §6F, never touching a MANAGER exception.
        foreach ($this->findCandidateOccurrenceExceptions($version) as $exception) {
            try {
                $restored = $this->reconciliationService->reconcileOccurrenceIfNoLongerJustified($exception, $actor);
                if ($restored !== null) {
                    $automaticCorrections++;
                    $issues[] = [
                        'type'   => 'FORGOTTEN_OCCURRENCE_RESTORATION',
                        'postId' => $restored['postId'],
                        'action' => 'RESTORED',
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger->error('PlanningVersionAuditService: occurrence check failed', [
                    'exceptionId' => $exception->getId(),
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        // D-106 bug fix (Lot 7 live campaign): PlanningConflictDetectionService::applySync()'s
        // "resolve stale alert" branch mutates the alert entity but never flushes itself —
        // safe in its pre-existing callers because THEY flush afterward for an unrelated
        // reason. This orchestrator has no such guarantee: every OTHER correction path here
        // flushes (MissionPostDeployService/PlanningAlertService's create branch), but a
        // stale-conflict resolution that happens to be the LAST pending change in the whole
        // scan had nothing left to flush it — silently lost even though $alertsResolved (and
        // the dialog) already reported it as done. One explicit flush here guarantees every
        // mutation performed during this scan is durable before the response is built.
        $this->em->flush();

        return [
            'checkedMissions'      => $checkedMissions,
            'issuesFound'          => count($issues),
            'automaticCorrections' => $automaticCorrections,
            'alertsCreated'        => $alertsCreated,
            'alertsResolved'       => $alertsResolved,
            'issues'               => $issues,
        ];
    }

    /**
     * Bidirectional per §14: creates INSTRUMENTIST_INACTIVE when the assignee is currently
     * inactive, resolves a stale one when they're active again (or no longer the assignee) —
     * independently of any other alert type on the same mission (§14's "cause indépendante"
     * rule: an ABSENT alert disappearing must never resolve a still-real conflict, and vice
     * versa — each alert type here is only ever touched by its own dedicated check).
     *
     * @return array{created: int, resolved: int}
     */
    private function syncInactiveAlert(Mission $mission, User $actor): array
    {
        $instrumentist = $mission->getInstrumentist();

        if ($instrumentist !== null && !$instrumentist->isActive()) {
            $result = $this->alertService->createIfNotDuplicate($mission, PlanningAlertType::INSTRUMENTIST_INACTIVE, null, [
                'missionId'         => $mission->getId(),
                'instrumentistId'   => $instrumentist->getId(),
                'instrumentistName' => self::displayName($instrumentist),
            ]);
            if ($result['created']) {
                $this->em->flush(); // createIfNotDuplicate() only persists — matches PlanningConflictDetectionService::applySync()'s own flush-after-create.
            }
            return ['created' => $result['created'] ? 1 : 0, 'resolved' => 0];
        }

        $stale = $this->alertService->findActiveAlert($mission, PlanningAlertType::INSTRUMENTIST_INACTIVE, null);
        if ($stale !== null) {
            $this->alertService->resolve($stale, $actor, 'Instrumentiste de nouveau actif ou réaffecté (vérification manuelle).');
            $this->em->flush();
            return ['created' => 0, 'resolved' => 1];
        }

        return ['created' => 0, 'resolved' => 0];
    }

    /** @return PlanningOccurrenceException[] */
    private function findCandidateOccurrenceExceptions(PlanningVersion $version): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('e')->from(PlanningOccurrenceException::class, 'e')
            ->join('e.post', 'p')
            ->where('e.source = :source')
            ->andWhere('e.occurrenceDate >= :start')
            ->andWhere('e.occurrenceDate <= :end')
            ->setParameter('source', OccurrenceExceptionSource::SURGEON_ABSENCE)
            ->setParameter('start', $version->getPeriodStart(), Types::DATE_IMMUTABLE)
            ->setParameter('end', $version->getPeriodEnd(), Types::DATE_IMMUTABLE);

        if ($version->getSite() !== null) {
            $qb->andWhere('p.site = :site')->setParameter('site', $version->getSite());
        }

        return $qb->getQuery()->getResult();
    }

    private static function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
