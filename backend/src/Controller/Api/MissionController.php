<?php

namespace App\Controller\Api;

use App\Dto\Request\MissionCreateRequest;
use App\Dto\Request\MissionFilter;
use App\Dto\Request\MissionPublishRequest;
use App\Dto\Request\MissionSubmitRequest;
use App\Entity\Mission;
use App\Security\Voter\MissionVoter;
use App\Service\MissionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Bundle\SecurityBundle\Security;

#[Route(path: '/api/missions')]
class MissionController extends AbstractController
{
    public function __construct(
        private readonly MissionService $missionService,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly Security $security,
    ) {
    }

    #[Route(name: 'api_missions_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(MissionVoter::CREATE, new Mission());
        $dto = $this->deserializeAndValidate($request->getContent(), MissionCreateRequest::class);

        $mission = $this->missionService->create($dto, $user);

        return $this->json($mission, JsonResponse::HTTP_CREATED, [], ['groups' => $this->missionGroup()]);
    }

    #[Route(path: '/{id}/publish', name: 'api_missions_publish', methods: ['POST'])]
    public function publish(int $id, Request $request): JsonResponse
    {
        $mission = $this->missionService->getOr404($id);
        $this->denyAccessUnlessGranted(MissionVoter::PUBLISH, $mission);

        $dto = $this->deserializeAndValidate($request->getContent(), MissionPublishRequest::class);
        $publication = $this->missionService->publish($mission, $dto, $this->getUserOrThrow());

        return $this->json($publication, JsonResponse::HTTP_OK, [], ['groups' => 'mission:read_manager']);
    }

    #[Route(path: '/{id}/claim', name: 'api_missions_claim', methods: ['POST'])]
    public function claim(int $id, #[CurrentUser] $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($id);
        $this->denyAccessUnlessGranted(MissionVoter::CLAIM, $mission);

        $mission = $this->missionService->claim($mission, $user);

        return $this->json($mission, JsonResponse::HTTP_OK, [], ['groups' => $this->missionGroup()]);
    }

    #[Route(name: 'api_missions_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] $user): JsonResponse
    {
        $filterArray = $request->query->all();
        $dto = $this->serializer->deserialize(json_encode($filterArray), MissionFilter::class, 'json');
        $this->validateObject($dto);

        $result = $this->missionService->list($dto, $user);

        return $this->json($result, JsonResponse::HTTP_OK, [], ['groups' => $this->missionGroup()]);
    }

    #[Route(path: '/{id}', name: 'api_missions_get', methods: ['GET'])]
    public function getOne(int $id): JsonResponse
    {
        $mission = $this->missionService->getOr404($id);
        $this->denyAccessUnlessGranted(MissionVoter::VIEW, $mission);

        return $this->json($mission, JsonResponse::HTTP_OK, [], ['groups' => $this->missionGroup()]);
    }

    #[Route(path: '/{id}/submit', name: 'api_missions_submit', methods: ['POST'])]
    public function submit(int $id, Request $request): JsonResponse
    {
        $mission = $this->missionService->getOr404($id);
        $this->denyAccessUnlessGranted(MissionVoter::SUBMIT, $mission);

        $dto = $this->deserializeAndValidate($request->getContent(), MissionSubmitRequest::class);
        $mission = $this->missionService->submit($mission, $dto);

        return $this->json($mission, JsonResponse::HTTP_OK, [], ['groups' => $this->missionGroup()]);
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
            throw $this->createValidationException($errors);
        }
    }

    private function createValidationException($errors): \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
    {
        return new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException((string) $errors);
    }

    private function missionGroup(): string
    {
        $user = $this->security->getUser();
        $roles = $user?->getRoles() ?? [];
        return in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true) ? 'mission:read_manager' : 'mission:read';
    }

    private function getUserOrThrow(): object
    {
        $user = $this->security->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
