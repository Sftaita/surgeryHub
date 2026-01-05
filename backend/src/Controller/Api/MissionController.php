<?php

namespace App\Controller\Api;

use App\Dto\Request\MissionCreateRequest;
use App\Dto\Request\MissionFilter;
use App\Dto\Request\MissionPublishRequest;
use App\Dto\Request\MissionSubmitRequest;
use App\Entity\Mission;
use App\Entity\User;
use App\Security\Voter\MissionVoter;
use App\Service\MissionMapper;
use App\Service\MissionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route(path: '/api/missions')]
class MissionController extends AbstractController
{
    public function __construct(
        private readonly MissionService $missionService,
        private readonly MissionMapper $mapper,
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

        // Retour minimal detail DTO
        return $this->json($this->mapper->toDetailDto($mission, $user), JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/{id}/publish', name: 'api_missions_publish', methods: ['POST'])]
    public function publish(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($id);
        $this->denyAccessUnlessGranted(MissionVoter::PUBLISH, $mission);

        /** @var MissionPublishRequest $dto */
        $dto = $this->deserializeAndValidate($request->getContent(), MissionPublishRequest::class);

        $publication = $this->missionService->publish($mission, $dto, $user);

        // publication = manager/admin; on renvoie l'entité publication si tu veux, sinon 204
        return $this->json($publication, JsonResponse::HTTP_OK, [], ['groups' => 'mission:read_manager']);
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

        $mission = $this->missionService->submit($mission, $dto);

        return $this->json($this->mapper->toDetailDto($mission, $user), JsonResponse::HTTP_OK);
    }

    private function deserializeAndValidate(string $json, string $class): object
    {
        $dto = $this->serializer->deserialize($json, $class, 'json');
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
