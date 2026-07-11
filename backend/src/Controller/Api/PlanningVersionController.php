<?php

namespace App\Controller\Api;

use App\Entity\Mission;
use App\Entity\PlanningDeployment;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\PlanningVersionStatus;
use App\Security\Voter\PlanningVoter;
use App\Service\PdfService;
use App\Service\PlanningCoverageService;
use App\Service\PlanningDiffService;
use App\Service\PlanningModificationService;
use App\Service\PlanningVersionHistoryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class PlanningVersionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface         $em,
        private readonly PlanningDiffService            $diffService,
        private readonly PdfService                     $pdfService,
        private readonly PlanningCoverageService        $coverageService,
        private readonly PlanningVersionHistoryService  $historyService,
        private readonly PlanningModificationService    $modificationService,
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

    // ── Show ──────────────────────────────────────────────────────────────────

    #[Route('/api/planning/versions/{id}', name: 'api_planning_version_show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $version = $this->em->find(PlanningVersion::class, $id);
        if ($version === null) {
            return $this->json(['error' => ['message' => 'PlanningVersion not found.']], 404);
        }

        return $this->json(array_merge(
            $this->serialize($version),
            [
                'allowedActions'  => $this->allowedActions($version),
                'lastDeployment'  => $this->serializeDeployment($this->findLastDeployment($version)),
            ],
        ));
    }

    // ── Diff ─────────────────────────────────────────────────────────────────

    /**
     * Planning-visible diff vs the previous ACTIVE/ARCHIVED version.
     * Call BEFORE deploying to preview what will change.
     */
    #[Route('/api/planning/versions/{id}/diff', name: 'api_planning_version_diff', methods: ['GET'])]
    public function diff(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $version = $this->em->find(PlanningVersion::class, $id);
        if ($version === null) {
            return $this->json(['error' => ['message' => 'PlanningVersion not found.']], 404);
        }

        return $this->json($this->diffService->diff($version));
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

    // ── Delete ────────────────────────────────────────────────────────────────

    /**
     * Deletes a DRAFT PlanningVersion and all its linked missions.
     * Refuses deletion if status is ACTIVE or ARCHIVED.
     */
    #[Route('/api/planning/versions/{id}', name: 'api_planning_versions_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $version = $this->em->find(PlanningVersion::class, $id);
        if ($version === null) {
            return $this->json(['error' => ['message' => 'PlanningVersion not found.']], 404);
        }

        if ($version->getStatus() !== PlanningVersionStatus::DRAFT) {
            return $this->json([
                'error' => [
                    'message' => sprintf(
                        'Impossible de supprimer une version %s. Seules les versions DRAFT peuvent être supprimées.',
                        $version->getStatus()->value,
                    ),
                ],
            ], 400);
        }

        // Refuse if any linked mission is already published or assigned.
        // Even a DRAFT version may have had some missions individually published
        // (e.g. via the ResolveModal), and deleting those would leave orphaned OPEN missions.
        $publishedCount = (int) $this->em->createQuery(
            'SELECT COUNT(m.id) FROM App\Entity\Mission m
             WHERE m.planningVersion = :v AND m.status != :draft'
        )
            ->setParameter('v',     $version)
            ->setParameter('draft', MissionStatus::DRAFT)
            ->getSingleScalarResult();

        if ($publishedCount > 0) {
            return $this->json([
                'error' => [
                    'message' => 'Impossible de supprimer ce planning car certaines missions ont déjà été publiées ou assignées.',
                ],
            ], 400);
        }

        // Delete linked DRAFT missions first (no cascade remove in ORM mapping)
        $this->em->createQuery(
            'DELETE FROM App\Entity\Mission m WHERE m.planningVersion = :v'
        )->setParameter('v', $version)->execute();

        $this->em->remove($version);
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    // ── PDF ───────────────────────────────────────────────────────────────────

    /**
     * Generates and streams the global PDF for a PlanningVersion (synchronous).
     * V1: blocking Dompdf — acceptable for typical planning sizes.
     */
    #[Route('/api/planning/versions/{id}/pdf', name: 'api_planning_version_pdf', methods: ['GET'])]
    public function pdf(int $id): Response
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $version = $this->em->find(PlanningVersion::class, $id);
        if ($version === null) {
            return $this->json(['error' => ['message' => 'PlanningVersion not found.']], 404);
        }

        $qb = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Mission::class, 'm')
            ->where('m.startAt >= :from')
            ->andWhere('m.startAt <= :to')
            ->andWhere('m.status NOT IN (:excluded)')
            ->setParameter('from',     $version->getPeriodStart()->setTime(0, 0, 0))
            ->setParameter('to',       $version->getPeriodEnd()->setTime(23, 59, 59))
            ->setParameter('excluded', [MissionStatus::REJECTED]);

        if ($version->getSite() !== null) {
            $qb->andWhere('m.site = :site')->setParameter('site', $version->getSite());
        }

        $missions = $qb->getQuery()->getResult();

        $pdf = $this->pdfService->generateFromTemplate('pdf/planning_global.html.twig', [
            'missions'   => $missions,
            'periodFrom' => $version->getPeriodStart(),
            'periodTo'   => $version->getPeriodEnd(),
        ]);

        $filename = sprintf('planning-v%d-%s-%s.pdf',
            $version->getVersionNumber(),
            $version->getPeriodStart()->format('Y-m-d'),
            $version->getPeriodEnd()->format('Y-m-d'),
        );

        return new Response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    // ── Private — serialization ───────────────────────────────────────────────

    /** Full serialization for the show endpoint (loads mission entities). */
    private function serialize(PlanningVersion $version): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Mission::class, 'm')
            ->where('m.startAt >= :from')
            ->andWhere('m.startAt <= :to')
            ->andWhere('m.status NOT IN (:excluded)')
            ->setParameter('from',     $version->getPeriodStart()->setTime(0, 0, 0))
            ->setParameter('to',       $version->getPeriodEnd()->setTime(23, 59, 59))
            ->setParameter('excluded', [MissionStatus::REJECTED]);

        if ($version->getSite() !== null) {
            $qb->andWhere('m.site = :site')->setParameter('site', $version->getSite());
        }

        /** @var Mission[] $missions */
        $missions = $qb->getQuery()->getResult();

        $total = count($missions);
        $draft = $open = $assigned = $withoutInstr = 0;
        $surgeonIds = $instrumentistIds = [];

        foreach ($missions as $mission) {
            $status   = $mission->getStatus();
            $hasInstr = $mission->getInstrumentist() !== null;

            if ($status === MissionStatus::DRAFT) {
                $draft++;
                if (!$hasInstr) $withoutInstr++;
            } elseif ($status === MissionStatus::OPEN) {
                $open++;
                if (!$hasInstr) $withoutInstr++;
            } else {
                $assigned++;
            }

            if ($mission->getSurgeon() !== null) {
                $surgeonIds[$mission->getSurgeon()->getId()] = true;
            }
            if ($hasInstr) {
                $instrumentistIds[$mission->getInstrumentist()->getId()] = true;
            }
        }

        $site = $version->getSite();

        return [
            'id'            => $version->getId(),
            'versionNumber' => $version->getVersionNumber(),
            'status'        => $version->getStatus()->value,
            'periodStart'   => $version->getPeriodStart()->format('Y-m-d'),
            'periodEnd'     => $version->getPeriodEnd()->format('Y-m-d'),
            'generatedAt'   => $version->getGeneratedAt()->format(\DateTimeInterface::ATOM),
            'deployedAt'    => $version->getDeployedAt()?->format(\DateTimeInterface::ATOM),
            'archivedAt'    => $version->getArchivedAt()?->format(\DateTimeInterface::ATOM),
            'site'          => $site !== null ? ['id' => $site->getId(), 'name' => $site->getName()] : null,
            'generatedBy'   => [
                'id'    => $version->getGeneratedBy()?->getId(),
                'email' => $version->getGeneratedBy()?->getEmail(),
            ],
            'summary' => [
                'total'               => $total,
                'draft'               => $draft,
                'open'                => $open,
                'assigned'            => $assigned,
                'withoutInstrumentist'=> $withoutInstr,
                'surgeonCount'        => count($surgeonIds),
                'instrumentistCount'  => count($instrumentistIds),
            ],
        ];
    }

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
