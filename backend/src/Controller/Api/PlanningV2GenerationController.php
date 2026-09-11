<?php

namespace App\Controller\Api;

use App\Dto\Request\Response\DeployResponse;
use App\Dto\Request\Response\DraftReopenResponse;
use App\Dto\Request\Response\DraftUpdateResponse;
use App\Dto\Request\Response\DraftVersionSummaryResponse;
use App\Dto\Request\Response\GeneratedPlanningResponse;
use App\Dto\Request\Response\PreviewLineResponse;
use App\Dto\Request\Response\PreviewResponse;
use App\Dto\Request\Response\PreviewSummaryResponse;
use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Entity\Hospital;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\EligibilityEnforcementPolicy;
use App\Enum\PlanningVersionStatus;
use App\Exception\PlanningDraftAlreadyExistsException;
use App\Exception\PlanningDraftConflictException;
use App\Security\Voter\PlanningVoter;
use App\Service\MissionEligibilityService;
use App\Service\PlanningDeploymentService;
use App\Service\PlanningDraftService;
use App\Service\PlanningGeneratorServiceV2;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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
        private readonly MissionEligibilityService $eligibilityService,
        private readonly PlanningDraftService $draftService,
    ) {}

    #[Route('/api/planning/v2/preview', name: 'api_planning_v2_preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        [$siteId, $siteGroupId, $month] = $this->parseTargetAndMonth($request);

        $lines          = $this->generator->preview($month, $siteId, $siteGroupId, null);
        $previewVersion = $this->generator->computePreviewVersion($month, $siteId, $siteGroupId);

        $response = new PreviewResponse(
            lines: array_map(PreviewLineResponse::fromLine(...), $lines),
            summary: PreviewSummaryResponse::fromLines($lines),
            previewVersion: $previewVersion,
            generatedAt: (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
        );

        return $this->json($response);
    }

    #[Route('/api/planning/v2/generate', name: 'api_planning_v2_generate', methods: ['POST'])]
    public function generate(Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        [$siteId, $siteGroupId, $month] = $this->parseTargetAndMonth($request);
        [$periodStart, $periodEnd]      = $this->monthRange($month);

        try {
            $this->assertNoUndeployedDraftExists($siteId, $periodStart, $periodEnd);
        } catch (PlanningDraftAlreadyExistsException $e) {
            // CAS D (D-115) — structured, so the frontend can offer "Ouvrir le brouillon"
            // directly instead of a dead-end message. Fallback path only: the primary UX
            // already shows "Brouillon existant" before generate() is ever called again.
            return $this->json([
                'code'      => 'PLANNING_DRAFT_ALREADY_EXISTS',
                'message'   => $e->getMessage(),
                'versionId' => $e->getExistingVersionId(),
            ], 409);
        }

        $data = json_decode($request->getContent() ?: '{}', true) ?? [];

        // Validate previewVersion when the client sends one (stale-preview guard).
        $clientVersion = isset($data['previewVersion']) && is_string($data['previewVersion'])
            ? $data['previewVersion']
            : null;

        if ($clientVersion !== null) {
            $serverVersion = $this->generator->computePreviewVersion($month, $siteId, $siteGroupId);
            if ($clientVersion !== $serverVersion) {
                return $this->json(
                    ['code' => 'PREVIEW_EXPIRED', 'message' => "Le planning a changé depuis la prévisualisation. Veuillez régénérer l'aperçu."],
                    409,
                );
            }
        }

        $overrideLines = isset($data['lines']) && is_array($data['lines']) ? $data['lines'] : null;

        $result = $this->generator->generate($month, $siteId, $siteGroupId, null, $currentUser, $overrideLines);

        return $this->json(new GeneratedPlanningResponse(
            versionId: $result['versionId'],
            created: $result['created'],
            updated: $result['updated'],
            skipped: $result['skipped'],
            rejectedAssignments: $result['rejectedAssignments'] ?? [],
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

        // D-115bis follow-up — a group-scoped draft whose scope was only RECONSTRUCTED
        // (never captured live at generate() time) must never be deployed until a manager
        // has explicitly confirmed it — see PlanningDraftService::confirmScope().
        $this->draftService->assertScopeConfirmed($version);

        try {
            $result = $this->deploymentService->deploy(
                from: $version->getPeriodStart()->format('Y-m-d'),
                to: $version->getPeriodEnd()->format('Y-m-d'),
                siteId: $version->getSite()?->getId(),
                deployedBy: $currentUser,
                versionId: $version->getId(),
                selectedUncoveredMissionIds: [],
            );
        } catch (PlanningDraftConflictException $e) {
            // D-090 — structured, never a bare 500/generic 409: the manager needs to see
            // exactly which mission/date/instrumentist is conflicting to resolve it.
            return $this->json([
                'code'      => 'DRAFT_CONFLICTS',
                'message'   => $e->getMessage(),
                'conflicts' => $e->getConflicts(),
            ], 409);
        }

        return $this->json(new DeployResponse(
            deploymentId: $result['deploymentId'],
            missionCount: $result['missionCount'],
            openPoolCount: $result['openPoolCount'],
        ));
    }

    /**
     * D-102 (Lot 2) — eligibility-aware instrumentist roster for the Preview Editor's
     * candidate pickers (single-line Inspector, bulk-assign, Mode Modification
     * "add mission" draft), none of which have a persisted Mission to query against
     * (a new/edited preview line, before generate()). Delegates to
     * `MissionEligibilityService::evaluateRoster()` — the SAME full active roster the
     * Preview Editor already fetches today (`GET /api/instrumentists?active=true`),
     * now annotated with real ABSENT/SCHEDULE_CONFLICT/NO_SITE_MEMBERSHIP reasons
     * instead of the frontend recomputing absence/conflict itself. `?policy=` defaults
     * to STRICT_ASSIGNMENT (generation) — the Preview Editor passes
     * PLANNING_MODIFICATION when editing an already-deployed month.
     */
    #[Route('/api/planning/v2/eligible-instrumentists', name: 'api_planning_v2_eligible_instrumentists', methods: ['GET'])]
    public function eligibleInstrumentistsForSlot(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $siteId = $request->query->get('siteId') !== null ? (int) $request->query->get('siteId') : null;
        $date   = $request->query->get('date');
        $start  = $request->query->get('startTime');
        $end    = $request->query->get('endTime');

        if ($date === null || $start === null || $end === null) {
            throw new BadRequestHttpException('date, startTime et endTime sont requis.');
        }

        $site = $siteId !== null ? $this->em->find(Hospital::class, $siteId) : null;
        if ($siteId !== null && $site === null) {
            throw new BadRequestHttpException('Site introuvable.');
        }

        $businessTz = new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE);
        try {
            $startAt = new \DateTimeImmutable("{$date}T{$start}:00", $businessTz);
            $endAt   = new \DateTimeImmutable("{$date}T{$end}:00", $businessTz);
        } catch (\Throwable) {
            throw new BadRequestHttpException('Format de date/heure invalide.');
        }
        if ($endAt <= $startAt) {
            throw new BadRequestHttpException('endTime doit être après startTime.');
        }

        $excludeMissionId = $request->query->get('excludeMissionId') !== null
            ? (int) $request->query->get('excludeMissionId')
            : null;

        $policy = EligibilityEnforcementPolicy::tryFrom((string) $request->query->get('policy', ''))
            ?? EligibilityEnforcementPolicy::STRICT_ASSIGNMENT;

        $results    = $this->eligibilityService->evaluateRoster($site, $startAt, $endAt, $excludeMissionId);
        $candidates = array_map(
            fn ($result) => $this->eligibilityService->serializeCandidate($result, $policy),
            $results,
        );

        return $this->json([
            'policy'     => $policy->value,
            'candidates' => $candidates,
        ]);
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
            throw new PlanningDraftAlreadyExistsException($existing->getId());
        }
    }

    // ── CAS D (D-115) — reopen / update / delete an already-persisted draft ────

    /**
     * "Ouvrir le brouillon" — reconstructs the Preview Editor from this draft's real,
     * persisted Missions (never a silent re-preview-as-source-of-truth — see
     * PlanningDraftService's docblock). `divergent` is informational only: it never
     * blocks the reopen or replaces anything already saved.
     */
    #[Route('/api/planning/v2/drafts/{id}', name: 'api_planning_v2_draft_reopen', methods: ['GET'])]
    public function reopenDraft(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $version = $this->em->find(PlanningVersion::class, $id);
        if ($version === null) {
            throw $this->createNotFoundException('PlanningVersion introuvable.');
        }

        $result = $this->draftService->reopen($version);

        return $this->json(new DraftReopenResponse(
            version: DraftVersionSummaryResponse::fromVersion($version),
            lines: array_map(PreviewLineResponse::fromLine(...), $result['lines']),
            summary: PreviewSummaryResponse::fromLines($result['lines']),
            previewVersion: $result['previewVersion'],
            divergent: $result['divergent'],
            generatedAt: (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
        ));
    }

    /**
     * Saves further editor changes (reassign/remove/add a line) directly onto this
     * draft's own Missions — never a new generate()/PlanningVersion. Body: `{lines:
     * PreviewLineV2[]}`, the exact same shape the editor already sends to generate()'s
     * override mode.
     */
    #[Route('/api/planning/v2/drafts/{id}', name: 'api_planning_v2_draft_update', methods: ['PATCH'])]
    public function updateDraft(int $id, Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $version = $this->em->find(PlanningVersion::class, $id);
        if ($version === null) {
            throw $this->createNotFoundException('PlanningVersion introuvable.');
        }

        $data  = $request->toArray();
        $lines = isset($data['lines']) && is_array($data['lines']) ? $data['lines'] : [];

        $result = $this->draftService->update($version, $lines, $currentUser);

        return $this->json(new DraftUpdateResponse(
            created: $result['created'],
            updated: $result['updated'],
            removed: $result['removed'],
            skipped: $result['skipped'],
            rejectedAssignments: $result['rejectedAssignments'],
        ));
    }

    /**
     * D-115bis follow-up — a manager's explicit review of a RECONSTRUCTED scope (see
     * PlanningVersionScopeSource). Body: `{siteIds: number[]}`, typically pre-filled by the
     * frontend with the reconstructed guess already shown via reopenDraft(), but editable —
     * the manager may add a site the reconstruction missed or remove one it wrongly kept.
     * Never accepted for a SNAPSHOT/CONFIRMED version, and never idempotently re-accepted
     * once CONFIRMED (see PlanningDraftScopeAlreadyConfirmedException) — an already-locked-
     * in manager decision is never silently overwritten.
     */
    #[Route('/api/planning/v2/drafts/{id}/confirm-scope', name: 'api_planning_v2_draft_confirm_scope', methods: ['POST'])]
    public function confirmDraftScope(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $version = $this->em->find(PlanningVersion::class, $id);
        if ($version === null) {
            throw $this->createNotFoundException('PlanningVersion introuvable.');
        }

        $data = $request->toArray();
        $siteIds = isset($data['siteIds']) && is_array($data['siteIds']) ? $data['siteIds'] : [];

        $confirmed = $this->draftService->confirmScope($version, $siteIds);

        return $this->json(DraftVersionSummaryResponse::fromVersion($confirmed));
    }
}
