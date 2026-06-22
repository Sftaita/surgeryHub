<?php

namespace App\Controller\Api;

use App\Dto\Request\Response\DeployResponse;
use App\Dto\Request\Response\GeneratedPlanningResponse;
use App\Dto\Request\Response\PreviewLineResponse;
use App\Dto\Request\Response\PreviewResponse;
use App\Dto\Request\Response\PreviewSummaryResponse;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\PlanningVersionStatus;
use App\Security\Voter\PlanningVoter;
use App\Service\PlanningDeploymentService;
use App\Service\PlanningGeneratorServiceV2;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Exposes PlanningGeneratorServiceV2 (built Batch 2, never wired to HTTP until now —
 * the single most important finding of the Batch 8 architecture freeze) through its
 * own dedicated routes, entirely parallel to V1's /api/planning/preview|generate and
 * PlanningDeployController. No V1 code touched. No feature flag/cutover wiring here —
 * these routes simply exist; nothing routes V1 traffic to them.
 *
 * Deploy reuses PlanningDeploymentService unchanged — V2-generated missions are
 * structurally identical Mission/PlanningVersion rows, so the existing deploy/PDF/diff
 * pipeline already works on them with zero changes (confirmed Batch 8 §D).
 */
class PlanningV2GenerationController extends AbstractController
{
    public function __construct(
        private readonly PlanningGeneratorServiceV2 $generator,
        private readonly PlanningDeploymentService $deploymentService,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/api/planning/v2/preview', name: 'api_planning_v2_preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        [$siteId, $siteGroupId, $month] = $this->parseTargetAndMonth($request);

        $lines = $this->generator->preview($month, $siteId, $siteGroupId, null);

        $response = new PreviewResponse(
            lines: array_map(PreviewLineResponse::fromLine(...), $lines),
            summary: PreviewSummaryResponse::fromLines($lines),
        );

        return $this->json($response);
    }

    #[Route('/api/planning/v2/generate', name: 'api_planning_v2_generate', methods: ['POST'])]
    public function generate(Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        [$siteId, $siteGroupId, $month] = $this->parseTargetAndMonth($request);
        [$periodStart, $periodEnd] = $this->monthRange($month);

        $this->assertNoUndeployedDraftExists($siteId, $periodStart, $periodEnd);

        $result = $this->generator->generate($month, $siteId, $siteGroupId, null, $currentUser);

        return $this->json(new GeneratedPlanningResponse(
            versionId: $result['versionId'],
            created: $result['created'],
            updated: $result['updated'],
            skipped: $result['skipped'],
        ));
    }

    #[Route('/api/planning/v2/deploy', name: 'api_planning_v2_deploy', methods: ['POST'])]
    public function deploy(Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $data = json_decode($request->getContent() ?: '{}', true) ?? [];

        if (!isset($data['planningVersionId']) || !is_numeric($data['planningVersionId'])) {
            throw new BadRequestHttpException('planningVersionId est requis.');
        }

        $version = $this->em->find(PlanningVersion::class, (int) $data['planningVersionId']);
        if ($version === null) {
            throw $this->createNotFoundException('PlanningVersion introuvable.');
        }

        // sendPdf maps to the existing sendChangeSummary lever — PlanningDeploymentService has
        // no separate "skip PDFs entirely" toggle, and adding one is out of this batch's scope
        // ("no V2-specific deploy logic unless required"). The standard per-recipient planning
        // PDFs are sent unconditionally either way, exactly as for a V1 deploy.
        $sendPdf = (bool) ($data['sendPdf'] ?? true);

        $result = $this->deploymentService->deploy(
            from: $version->getPeriodStart()->format('Y-m-d'),
            to: $version->getPeriodEnd()->format('Y-m-d'),
            siteId: $version->getSite()?->getId(),
            deployedBy: $currentUser,
            versionId: $version->getId(),
            selectedUncoveredMissionIds: [],
            sendChangeSummary: $sendPdf,
        );

        return $this->json(new DeployResponse(
            deploymentId: $result['deploymentId'],
            missionCount: $result['missionCount'],
            openPoolCount: $result['openPoolCount'],
        ));
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /** @return array{0: ?int, 1: ?int, 2: string} [siteId, siteGroupId, "YYYY-MM"] */
    private function parseTargetAndMonth(Request $request): array
    {
        $data = json_decode($request->getContent() ?: '{}', true) ?? [];

        $siteId      = isset($data['siteId']) && $data['siteId'] !== null ? (int) $data['siteId'] : null;
        $siteGroupId = isset($data['siteGroupId']) && $data['siteGroupId'] !== null ? (int) $data['siteGroupId'] : null;

        if ($siteId !== null && $siteGroupId !== null) {
            throw new BadRequestHttpException('Fournir siteId OU siteGroupId, pas les deux.');
        }
        if ($siteId === null && $siteGroupId === null) {
            throw new BadRequestHttpException('siteId ou siteGroupId est requis.');
        }

        if (!isset($data['year']) || !is_numeric($data['year']) || !isset($data['month']) || !is_numeric($data['month'])) {
            throw new BadRequestHttpException('year et month sont requis.');
        }

        $year  = (int) $data['year'];
        $month = (int) $data['month'];
        if ($month < 1 || $month > 12) {
            throw new BadRequestHttpException('month doit être compris entre 1 et 12.');
        }

        return [$siteId, $siteGroupId, sprintf('%04d-%02d', $year, $month)];
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    private function monthRange(string $month): array
    {
        $start = new \DateTimeImmutable($month . '-01');
        return [$start, $start->modify('last day of this month')];
    }

    /**
     * Explicit duplicate rejection (chosen over silent idempotency): generating twice
     * for the same site+period while an undeployed DRAFT already exists is almost
     * certainly a mistake (double-click, or forgetting a draft was already made) —
     * the manager must deploy or delete the existing draft first. Once a version is
     * ACTIVE/ARCHIVED, regenerating the same period is allowed (matches V1's existing
     * versioning semantics — a new DRAFT with the next version number).
     *
     * Known limitation (documented in Batch 8 §B/§I): a site-group generation stores
     * PlanningVersion.site = null, the same "no site filter" bucket V1 uses — two
     * different site groups generated for the same month would collide in this check.
     * Not fixed here; PlanningVersion has no siteGroupId column today.
     */
    private function assertNoUndeployedDraftExists(?int $siteId, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('v')
            ->from(PlanningVersion::class, 'v')
            ->where('v.periodStart = :periodStart')
            ->andWhere('v.periodEnd = :periodEnd')
            ->andWhere('v.status = :draft')
            ->setParameter('periodStart', $periodStart)
            ->setParameter('periodEnd', $periodEnd)
            ->setParameter('draft', PlanningVersionStatus::DRAFT)
            ->setMaxResults(1);

        if ($siteId !== null) {
            $qb->andWhere('v.site = :siteId')->setParameter('siteId', $siteId);
        } else {
            $qb->andWhere('v.site IS NULL');
        }

        $existing = $qb->getQuery()->getOneOrNullResult();
        if ($existing !== null) {
            throw new ConflictHttpException(sprintf(
                'Un brouillon (version #%d) existe déjà pour cette période — déployez-le ou supprimez-le avant de régénérer.',
                $existing->getId(),
            ));
        }
    }
}
