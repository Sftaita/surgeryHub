<?php

namespace App\Controller\Api;

use App\Dto\Request\MissionCreateRequest;
use App\Dto\Request\MissionFilter;
use App\Dto\Request\MissionPatchRequest;
use App\Dto\Request\MissionPublishRequest;
use App\Dto\Request\MissionSubmitRequest;
use App\Entity\Mission;
use App\Entity\User;
use App\Security\Voter\MissionVoter;
use App\Service\MissionEncodingGuard;
use App\Service\MissionEncodingService;
use App\Service\MissionMapper;
use App\Service\MissionService;
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
        private readonly MissionService $missionService,
        private readonly MissionMapper $mapper,
        private readonly MissionEncodingService $encodingService,
        private readonly MissionEncodingGuard $encodingGuard,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
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

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

    #[Route(path: '/{id}/claim', name: 'api_missions_claim', methods: ['POST'])]
    public function claim(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($id);
        $this->denyAccessUnlessGranted(MissionVoter::CLAIM, $mission);

        $mission = $this->missionService->claim($mission, $user);

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
        $mission = $this->missionService->getOr404ForEncoding($id);

        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $this->encodingGuard->assertEncodingAllowed($mission, $user);

        $encodingDto = $this->encodingService->buildEncodingDto($mission);

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
