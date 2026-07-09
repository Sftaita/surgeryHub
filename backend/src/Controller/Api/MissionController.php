<?php

namespace App\Controller\Api;

use App\Dto\Request\DeclareMissionRequest;
use App\Dto\Request\MissionCreateRequest;
use App\Dto\Request\MissionFilter;
use App\Dto\Request\MissionPatchRequest;
use App\Dto\Request\MissionPublishRequest;
use App\Dto\Request\MissionSubmitRequest;
use App\Entity\AuditEvent;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\EligibilityReason;
use App\Security\Voter\MissionVoter;
use App\Service\MissionEligibilityService;
use App\Service\MissionEncodingGuard;
use App\Service\MissionEncodingService;
use App\Service\MissionMapper;
use App\Service\MissionPostDeployService;
use App\Service\MissionService;
use App\Service\WebPushService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route(path: '/api/missions')]
class MissionController extends AbstractController
{
    public function __construct(
        private readonly MissionService            $missionService,
        private readonly MissionMapper             $mapper,
        private readonly MissionEncodingService    $encodingService,
        private readonly MissionEncodingGuard      $encodingGuard,
        private readonly SerializerInterface       $serializer,
        private readonly ValidatorInterface        $validator,
        private readonly WebPushService            $webPushService,
        private readonly EntityManagerInterface    $em,
        private readonly MissionPostDeployService  $missionPostDeployService,
        private readonly MissionEligibilityService $eligibilityService,
    ) {}

    #[Route(name: 'api_missions_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(MissionVoter::CREATE, Mission::class);

        /** @var MissionCreateRequest $dto */
        $dto = $this->deserializeAndValidate($request->getContent(), MissionCreateRequest::class);

        $mission = $this->missionService->create($dto, $user);

        return $this->json($this->mapper->toDetailDto($mission, $user), JsonResponse::HTTP_CREATED);
    }

    // ✅ Lot B3 — declare
    #[Route(path: '/declare', name: 'api_missions_declare', methods: ['POST'])]
    public function declare(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(MissionVoter::DECLARE, Mission::class);

        /** @var DeclareMissionRequest $dto */
        $dto = $this->deserializeAndValidate($request->getContent(), DeclareMissionRequest::class);

        $mission = $this->missionService->declareMission($dto, $user);

        return $this->json($this->mapper->toDetailDto($mission, $user), JsonResponse::HTTP_CREATED);
    }

    // ✅ Lot B3 — approve DECLARED → ASSIGNED
    #[Route(path: '/{id}/approve-declared', name: 'api_missions_approve_declared', methods: ['POST'])]
    public function approveDeclared(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($id);
        $this->denyAccessUnlessGranted(MissionVoter::APPROVE_DECLARED, $mission);

        // ✅ Lot B4: actor explicite
        $mission = $this->missionService->approveDeclared($mission, $user);

        return $this->json($this->mapper->toDetailDto($mission, $user), JsonResponse::HTTP_OK);
    }

    // ✅ Lot B3 — reject DECLARED → REJECTED
    #[Route(path: '/{id}/reject-declared', name: 'api_missions_reject_declared', methods: ['POST'])]
    public function rejectDeclared(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($id);
        $this->denyAccessUnlessGranted(MissionVoter::REJECT_DECLARED, $mission);

        // ✅ Lot B4: actor explicite
        $mission = $this->missionService->rejectDeclared($mission, $user);

        return $this->json($this->mapper->toDetailDto($mission, $user), JsonResponse::HTTP_OK);
    }

    #[Route(path: '/{id}', name: 'api_missions_patch', methods: ['PATCH'])]
    public function patch(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($id);
        $this->denyAccessUnlessGranted(MissionVoter::EDIT, $mission);

        /** @var MissionPatchRequest $dto */
        $dto = $this->deserializeAndValidate($request->getContent(), MissionPatchRequest::class);

        $mission = $this->missionService->patch($mission, $dto, $user);

        return $this->json($this->mapper->toDetailDto($mission, $user), JsonResponse::HTTP_OK);
    }

    #[Route(path: '/{id}/publish', name: 'api_missions_publish', methods: ['POST'])]
    public function publish(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($id);
        $this->denyAccessUnlessGranted(MissionVoter::PUBLISH, $mission);

        /** @var MissionPublishRequest $dto */
        $dto = $this->deserializeAndValidate($request->getContent(), MissionPublishRequest::class);

        $this->missionService->publish($mission, $dto, $user);

        $this->webPushService->sendToSiteInstrumentists(
            $mission,
            'Nouvelle mission disponible',
            'Une nouvelle mission a été publiée sur votre site.',
            ['missionId' => $mission->getId()],
        );

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

    // Pre-deploy assignment only (DRAFT). Deployed missions must use /release or /reassign (R-04, D-056).
    #[Route(path: '/{id}/assign-instrumentist', name: 'api_missions_assign_instrumentist', methods: ['POST'])]
    public function assignInstrumentist(int $id, Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $mission = $this->missionService->getOr404($id);
        $this->denyAccessUnlessGranted(MissionVoter::ASSIGN_INSTRUMENTIST, $mission);

        $data = json_decode($request->getContent(), true) ?? [];
        $instrumentistId = $data['instrumentistId'] ?? null;

        $mission = $this->missionService->assignInstrumentistDraft($mission, $instrumentistId);

        return $this->json($this->mapper->toDetailDto($mission, $currentUser));
    }

    #[Route(path: '/{id}/claim', name: 'api_missions_claim', methods: ['POST'])]
    public function claim(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($id);
        $this->denyAccessUnlessGranted(MissionVoter::CLAIM, $mission);

        $this->missionPostDeployService->claim($mission, $user);

        return $this->json($this->mapper->toDetailDto($mission, $user), JsonResponse::HTTP_OK);
    }

    // ── Eligibility (Batch 15D) ───────────────────────────────────────────────

    #[Route(path: '/{id}/eligible-instrumentists', name: 'api_missions_eligible_instrumentists', methods: ['GET'])]
    public function eligibleInstrumentists(int $id): JsonResponse
    {
        $mission = $this->missionService->getOr404($id);
        $this->denyAccessUnlessGranted(MissionVoter::VIEW_ELIGIBLE_INSTRUMENTISTS, $mission);

        $results   = $this->eligibilityService->evaluateAllCandidates($mission);
        $eligible  = [];
        $ineligible = [];

        foreach ($results as $result) {
            $candidate = $result->candidate;
            $entry = [
                'id'    => $candidate->getId(),
                'name'  => trim(($candidate->getFirstname() ?? '') . ' ' . ($candidate->getLastname() ?? '')),
                'email' => $candidate->getEmail(),
            ];

            if ($result->eligible) {
                $eligible[] = $entry;
            } else {
                $entry['reasons'] = array_map(
                    fn (EligibilityReason $r) => $r->value,
                    $result->reasons,
                );
                $ineligible[] = $entry;
            }
        }

        return $this->json([
            'missionId'     => $mission->getId(),
            'missionStatus' => $mission->getStatus()->value,
            'eligible'      => $eligible,
            'ineligible'    => $ineligible,
        ]);
    }

    // ── Audit history (Batch 15F) ─────────────────────────────────────────────

    #[Route(path: '/{id}/audit', name: 'api_missions_audit', methods: ['GET'])]
    public function audit(int $id): JsonResponse
    {
        $mission = $this->missionService->getOr404($id);
        $this->denyAccessUnlessGranted(MissionVoter::VIEW_AUDIT, $mission);

        /** @var AuditEvent[] $events */
        $events = $this->em->createQuery(
            'SELECT a, actor FROM App\Entity\AuditEvent a
             JOIN a.actor actor
             WHERE a.mission = :mission
             ORDER BY a.createdAt DESC'
        )
            ->setParameter('mission', $mission)
            ->getResult();

        $items = array_map(static function (AuditEvent $ae): array {
            $actor = $ae->getActor();
            return [
                'eventType'  => $ae->getEventType()->value,
                'occurredAt' => $ae->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'actorId'    => $actor?->getId(),
                'actorName'  => trim(($actor?->getFirstname() ?? '') . ' ' . ($actor?->getLastname() ?? '')),
                'payload'    => $ae->getPayload(),
            ];
        }, $events);

        return $this->json($items);
    }

    // ── Post-deploy lifecycle (Batch 15B) ─────────────────────────────────────

    #[Route(path: '/{id}/release', name: 'api_missions_release', methods: ['POST'])]
    public function release(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($id);
        $this->denyAccessUnlessGranted(MissionVoter::RELEASE, $mission);

        $this->missionPostDeployService->release($mission, $user);

        return $this->json($this->mapper->toDetailDto($mission, $user), JsonResponse::HTTP_OK);
    }

    #[Route(path: '/{id}/cancel', name: 'api_missions_cancel', methods: ['POST'])]
    public function cancel(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($id);
        $this->denyAccessUnlessGranted(MissionVoter::CANCEL, $mission);

        $data   = json_decode($request->getContent(), true) ?? [];
        $reason = isset($data['reason']) && is_string($data['reason']) ? $data['reason'] : null;

        $this->missionPostDeployService->cancel($mission, $user, $reason);

        return $this->json($this->mapper->toDetailDto($mission, $user), JsonResponse::HTTP_OK);
    }

    #[Route(path: '/{id}/reassign', name: 'api_missions_reassign', methods: ['POST'])]
    public function reassign(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($id);
        $this->denyAccessUnlessGranted(MissionVoter::REASSIGN, $mission);

        $data            = json_decode($request->getContent(), true) ?? [];
        $instrumentistId = $data['instrumentistId'] ?? null;

        if (!is_numeric($instrumentistId)) {
            return $this->json(
                ['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'instrumentistId is required', 'violations' => []]],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $this->missionPostDeployService->reassign($mission, $user, (int) $instrumentistId);

        return $this->json($this->mapper->toDetailDto($mission, $user), JsonResponse::HTTP_OK);
    }

    #[Route(name: 'api_missions_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $dto = MissionFilter::fromQuery($request->query->all());
        $this->validateObject($dto);

        $result = $this->missionService->list($dto, $user);

        $items = array_map(fn (Mission $m) => $this->mapper->toListDto($m, $user), $result['items']);

        return $this->json([
            'items' => $items,
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
        ]);
    }

    #[Route(path: '/{id}', name: 'api_missions_get', methods: ['GET'])]
    public function getOne(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($id);
        $this->denyAccessUnlessGranted(MissionVoter::VIEW, $mission);

        return $this->json($this->mapper->toDetailDto($mission, $user), JsonResponse::HTTP_OK);
    }

    #[Route(path: '/{id}/submit', name: 'api_missions_submit', methods: ['POST'])]
    public function submit(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($id);
        $this->denyAccessUnlessGranted(MissionVoter::SUBMIT, $mission);

        /** @var MissionSubmitRequest $dto */
        $dto = $this->deserializeAndValidate($request->getContent(), MissionSubmitRequest::class);

        $mission = $this->missionService->submit($mission, $dto, $user);

        return $this->json($this->mapper->toDetailDto($mission, $user), JsonResponse::HTTP_OK);
    }

    #[Route(path: '/{id}/encoding', name: 'api_missions_get_encoding', methods: ['GET'])]
    public function getEncoding(int $id, #[CurrentUser] User $user): JsonResponse
    {
        // IMPORTANT: ne pas utiliser getOr404ForEncoding() (ça déclenche l’hydratation proxy MissionIntervention -> warning 500)
        $mission = $this->missionService->getOr404($id);

        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $this->encodingGuard->assertEncodingAllowed($mission, $user);

        // MissionEncodingService attend (Mission $mission, User $viewer)
        $encodingDto = $this->encodingService->buildEncodingDto($mission, $user);

        return $this->json($encodingDto, JsonResponse::HTTP_OK);
    }

    private function deserializeAndValidate(string $json, string $class): object
    {
        $dto = $this->serializer->deserialize(
            $json,
            $class,
            'json',
            [
                DateTimeNormalizer::FORMAT_KEY => \DateTimeInterface::ATOM,
            ]
        );

        $this->validateObject($dto);

        return $dto;
    }

    private function validateObject(object $dto): void
    {
        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            throw new UnprocessableEntityHttpException((string) $errors);
        }
    }
}