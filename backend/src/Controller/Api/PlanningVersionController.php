<?php

namespace App\Controller\Api;

use App\Entity\Mission;
use App\Entity\PlanningDeployment;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\PlanningVersionStatus;
use App\Security\Voter\PlanningVoter;
use App\Service\PlanningCoverageService;
use App\Service\PlanningModificationService;
use App\Service\PlanningResendService;
use App\Service\PlanningVersionHistoryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class PlanningVersionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface         $em,
        private readonly PlanningCoverageService        $coverageService,
        private readonly PlanningVersionHistoryService  $historyService,
        private readonly PlanningModificationService    $modificationService,
        private readonly PlanningResendService          $resendService,
    ) {}

    // ── List ──────────────────────────────────────────────────────────────────

    /**
     * Lists PlanningVersions with pagination and filters.
     * Each item includes summary counts (scalar query, no entity hydration),
     * allowedActions, and the most recent PlanningDeployment for the period+site.
     */
    #[Route('/api/planning/versions', name: 'api_planning_versions_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $page   = max(1, (int) ($request->query->get('page', 1)));
        $limit  = min(100, max(1, (int) ($request->query->get('limit', 20))));
        $offset = ($page - 1) * $limit;

        $statusParam  = $request->query->get('status');
        $periodFrom   = $request->query->get('periodFrom');
        $periodTo     = $request->query->get('periodTo');
        $siteId       = $request->query->get('siteId') !== null
            ? (int) $request->query->get('siteId')
            : null;

        // ── Build shared WHERE predicate ──────────────────────────────────────
        $applyFilters = function (\Doctrine\ORM\QueryBuilder $qb) use ($statusParam, $periodFrom, $periodTo, $siteId): \Doctrine\ORM\QueryBuilder {
            if ($statusParam !== null && PlanningVersionStatus::tryFrom($statusParam) !== null) {
                $qb->andWhere('v.status = :status')
                   ->setParameter('status', PlanningVersionStatus::from($statusParam));
            }
            if ($periodFrom !== null) {
                try {
                    $qb->andWhere('v.periodEnd >= :periodFrom')
                       ->setParameter('periodFrom', new \DateTimeImmutable($periodFrom));
                } catch (\Exception) {}
            }
            if ($periodTo !== null) {
                try {
                    $qb->andWhere('v.periodStart <= :periodTo')
                       ->setParameter('periodTo', new \DateTimeImmutable($periodTo));
                } catch (\Exception) {}
            }
            if ($siteId !== null) {
                $qb->andWhere('v.site = :siteId')->setParameter('siteId', $siteId);
            }
            return $qb;
        };

        // ── Count ─────────────────────────────────────────────────────────────
        $total = (int) $applyFilters(
            $this->em->createQueryBuilder()
                ->select('COUNT(v.id)')
                ->from(PlanningVersion::class, 'v')
        )->getQuery()->getSingleScalarResult();

        // ── Paginated results (eager-load site + generatedBy to avoid N+1) ───
        /** @var PlanningVersion[] $versions */
        $versions = $applyFilters(
            $this->em->createQueryBuilder()
                ->select('v', 'site', 'gb')
                ->from(PlanningVersion::class, 'v')
                ->leftJoin('v.site', 'site')
                ->leftJoin('v.generatedBy', 'gb')
                ->orderBy('v.generatedAt', 'DESC')
                ->setFirstResult($offset)
                ->setMaxResults($limit)
        )->getQuery()->getResult();

        $items = array_map(fn (PlanningVersion $v) => $this->serializeListItem($v), $versions);

        return $this->json(['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    // ── Modification mode (Planning V2 unified editor) ────────────────────────

    /**
     * Applies a batch of editor-staged changes (reassign, release, cancel, schedule
     * change, new mission) to an already-deployed PlanningVersion in one request, then
     * sends exactly one targeted "what changed" email per actually-affected person.
     * Never a global resend to everyone on the planning.
     */
    #[Route('/api/planning/versions/{id}/apply-modifications', name: 'api_planning_version_apply_modifications', methods: ['POST'])]
    public function applyModifications(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $version = $this->em->find(PlanningVersion::class, $id);
        if ($version === null) {
            return $this->json(['error' => ['message' => 'PlanningVersion not found.']], 404);
        }

        $data  = $request->toArray();
        $lines = isset($data['lines']) && is_array($data['lines']) ? $data['lines'] : [];

        $result = $this->modificationService->apply($version, $lines, $user);

        return $this->json($result);
    }

    /**
     * D-090 (anomalie fonctionnelle 1) — "Renvoyer le planning par e-mail" : renvoie à UN
     * seul utilisateur son planning tel qu'actuellement publié dans cette version (jamais
     * un brouillon — PlanningResendService refuse toute version non ACTIVE), avec le même
     * format que l'email de déploiement initial. Ne dépend d'aucun diff, ne prévient
     * personne d'autre, et enregistre systématiquement un NotificationEvent (succès ou
     * échec) dans l'historique du destinataire.
     */
    #[Route('/api/planning/versions/{id}/resend/{userId}', name: 'api_planning_version_resend', methods: ['POST'])]
    public function resend(int $id, int $userId, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $version = $this->em->find(PlanningVersion::class, $id);
        if ($version === null) {
            return $this->json(['error' => ['message' => 'PlanningVersion not found.']], 404);
        }

        $target = $this->em->find(User::class, $userId);
        if ($target === null) {
            return $this->json(['error' => ['message' => 'Utilisateur introuvable.']], 404);
        }

        try {
            $result = $this->resendService->resend($version, $target, $actor);
        } catch (ConflictHttpException $e) {
            return $this->json(['error' => ['message' => $e->getMessage()]], 409);
        } catch (NotFoundHttpException $e) {
            return $this->json(['error' => ['message' => $e->getMessage()]], 404);
        } catch (\Throwable $e) {
            return $this->json(['error' => ['message' => $e->getMessage()]], 502);
        }

        return $this->json($result);
    }

    /**
     * "Delete this generated month" — cancels every cancellable mission (ASSIGNED/OPEN) of an
     * already-deployed PlanningVersion in one batch. Never a hard delete: audit trail (D-055)
     * and the PlanningVersion itself are preserved, missions transition to CANCELLED through
     * the same post-deploy chain as an individual "Annuler la mission", and exactly one
     * targeted summary email is sent per actually-affected person. Only ACTIVE versions are
     * eligible; ARCHIVED means already superseded, nothing left to meaningfully cancel.
     * (The DRAFT hard-delete endpoint this docblock used to reference was V1-only UI
     * and was removed in D-079 — see docs/decisions.md errata.)
     */
    #[Route('/api/planning/versions/{id}/cancel-all', name: 'api_planning_version_cancel_all', methods: ['POST'])]
    public function cancelAll(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $version = $this->em->find(PlanningVersion::class, $id);
        if ($version === null) {
            return $this->json(['error' => ['message' => 'PlanningVersion not found.']], 404);
        }

        if ($version->getStatus() !== PlanningVersionStatus::ACTIVE) {
            return $this->json([
                'error' => [
                    'message' => sprintf(
                        'Impossible d\'annuler les missions d\'une version %s. Seule une version ACTIVE peut être annulée en masse.',
                        $version->getStatus()->value,
                    ),
                ],
            ], 400);
        }

        $result = $this->modificationService->cancelAll($version, $user);

        return $this->json($result);
    }

    // ── Coverage KPI (Batch 15F) ──────────────────────────────────────────────

    #[Route('/api/planning/versions/{id}/coverage-summary', name: 'api_planning_version_coverage_summary', methods: ['GET'])]
    public function coverageSummary(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $summary = $this->coverageService->computeForVersion($id);
        if ($summary === null) {
            return $this->json(['error' => ['message' => 'PlanningVersion not found.']], 404);
        }

        return $this->json([
            'versionId'       => $summary->versionId,
            'total'           => $summary->total,
            'covered'         => $summary->covered,
            'open'            => $summary->open,
            'cancelled'       => $summary->cancelled,
            'coveragePercent' => $summary->coveragePercent,
        ]);
    }

    // ── Version history timeline (Batch 15F) ──────────────────────────────────

    #[Route('/api/planning/versions/{id}/history', name: 'api_planning_version_history', methods: ['GET'])]
    public function history(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $timeline = $this->historyService->buildTimeline($id);
        if ($timeline === null) {
            return $this->json(['error' => ['message' => 'PlanningVersion not found.']], 404);
        }

        return $this->json($timeline);
    }

    // ── Private — serialization ───────────────────────────────────────────────

    /**
     * Lightweight serialization for the list endpoint.
     * Uses a single scalar GROUP BY query instead of loading full Mission entities.
     */
    private function serializeListItem(PlanningVersion $version): array
    {
        $site = $version->getSite();

        return [
            'id'             => $version->getId(),
            'versionNumber'  => $version->getVersionNumber(),
            'status'         => $version->getStatus()->value,
            'periodStart'    => $version->getPeriodStart()->format('Y-m-d'),
            'periodEnd'      => $version->getPeriodEnd()->format('Y-m-d'),
            'generatedAt'    => $version->getGeneratedAt()->format(\DateTimeInterface::ATOM),
            'deployedAt'     => $version->getDeployedAt()?->format(\DateTimeInterface::ATOM),
            'archivedAt'     => $version->getArchivedAt()?->format(\DateTimeInterface::ATOM),
            'site'           => $site !== null ? ['id' => $site->getId(), 'name' => $site->getName()] : null,
            'generatedBy'    => [
                'id'    => $version->getGeneratedBy()?->getId(),
                'email' => $version->getGeneratedBy()?->getEmail(),
            ],
            'summary'        => $this->summarize($version),
            'allowedActions' => $this->allowedActions($version),
            'lastDeployment' => $this->serializeDeployment($this->findLastDeployment($version)),
        ];
    }

    /**
     * Scalar GROUP BY summary — one SQL query, no entity hydration.
     * CASE WHEN m.instrumentist IS NULL counts uncovered slots efficiently.
     */
    private function summarize(PlanningVersion $version): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select(
                'm.status AS status',
                'COUNT(m.id) AS cnt',
                'SUM(CASE WHEN m.instrumentist IS NULL THEN 1 ELSE 0 END) AS noInstrCount',
            )
            ->from(Mission::class, 'm')
            ->where('m.startAt >= :from')
            ->andWhere('m.startAt <= :to')
            ->andWhere('m.status != :rejected')
            ->groupBy('m.status')
            ->setParameter('from',     $version->getPeriodStart()->setTime(0, 0, 0))
            ->setParameter('to',       $version->getPeriodEnd()->setTime(23, 59, 59))
            ->setParameter('rejected', MissionStatus::REJECTED);

        if ($version->getSite() !== null) {
            $qb->andWhere('m.site = :site')->setParameter('site', $version->getSite());
        }

        $rows  = $qb->getQuery()->getArrayResult();
        $total = $draft = $open = $assigned = $withoutInstr = 0;

        foreach ($rows as $row) {
            $cnt     = (int) $row['cnt'];
            $noInstr = (int) $row['noInstrCount'];
            $statusVal = $row['status'] instanceof MissionStatus
                ? $row['status']->value
                : (string) $row['status'];

            $total += $cnt;
            if ($statusVal === MissionStatus::DRAFT->value) {
                $draft = $cnt;
                $withoutInstr += $noInstr;
            } elseif ($statusVal === MissionStatus::OPEN->value) {
                $open = $cnt;
                $withoutInstr += $noInstr;
            } else {
                $assigned += $cnt;
            }
        }

        return [
            'total'               => $total,
            'draft'               => $draft,
            'open'                => $open,
            'assigned'            => $assigned,
            'withoutInstrumentist'=> $withoutInstr,
        ];
    }

    /** @return array{view: bool, deploy: bool, delete: bool, downloadPdf: bool, viewDiff: bool} */
    private function allowedActions(PlanningVersion $version): array
    {
        $isDraft = $version->getStatus() === PlanningVersionStatus::DRAFT;
        return [
            'view'        => true,
            'deploy'      => $isDraft,
            'delete'      => $isDraft,
            'downloadPdf' => true,
            'viewDiff'    => true,
        ];
    }

    /**
     * Finds the most recent PlanningDeployment for a version's period+site.
     * PlanningDeployment has no FK to PlanningVersion — matched by period dates + site.
     */
    private function findLastDeployment(PlanningVersion $version): ?PlanningDeployment
    {
        $qb = $this->em->createQueryBuilder()
            ->select('d')
            ->from(PlanningDeployment::class, 'd')
            ->where('d.periodFrom = :from')
            ->andWhere('d.periodTo = :to')
            ->setParameter('from', $version->getPeriodStart())
            ->setParameter('to',   $version->getPeriodEnd())
            ->orderBy('d.deployedAt', 'DESC')
            ->setMaxResults(1);

        if ($version->getSite() !== null) {
            $qb->andWhere('d.site = :site')->setParameter('site', $version->getSite());
        } else {
            $qb->andWhere('d.site IS NULL');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /** @return array<string, mixed>|null */
    private function serializeDeployment(?PlanningDeployment $deployment): ?array
    {
        if ($deployment === null) {
            return null;
        }
        return [
            'status'      => $deployment->getStatus()->value,
            'deployedAt'  => $deployment->getDeployedAt()->format(\DateTimeInterface::ATOM),
            'startedAt'   => $deployment->getStartedAt()?->format(\DateTimeInterface::ATOM),
            'completedAt' => $deployment->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            'hasError'    => $deployment->getErrorLog() !== null,
        ];
    }
}
