<?php

namespace App\Controller\Api;

use App\Dto\Request\MissionInterventionCreateRequest;
use App\Dto\Request\MissionInterventionUpdateRequest;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\User;
use App\Security\Voter\MissionVoter;
use App\Service\InterventionService;
use App\Service\MissionEncodingGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/missions/{missionId}/interventions')]
class InterventionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InterventionService $service,
        private readonly MissionEncodingGuard $encodingGuard,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route('', methods: ['POST'])]
    public function create(int $missionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->em->find(Mission::class, $missionId);
        if (!$mission) {
            return $this->json(['message' => 'Mission not found'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $this->encodingGuard->assertEncodingAllowed($mission, $user);

        /** @var MissionInterventionCreateRequest $dto */
        $dto = $this->deserializeAndValidate($request->getContent(), MissionInterventionCreateRequest::class);

        $intervention = $this->service->create($mission, $dto);

        return $this->json(['id' => $intervention->getId()], Response::HTTP_CREATED);
    }

    #[Route('/{interventionId}', methods: ['PATCH'])]
    public function update(int $missionId, int $interventionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->em->find(Mission::class, $missionId);
        if (!$mission) {
            return $this->json(['message' => 'Mission not found'], Response::HTTP_NOT_FOUND);
        }

        $intervention = $this->em->find(MissionIntervention::class, $interventionId);
        if (!$intervention || $intervention->getMission()?->getId() !== $mission->getId()) {
            return $this->json(['message' => 'Intervention not found'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $this->encodingGuard->assertEncodingAllowed($mission, $user);

        /** @var MissionInterventionUpdateRequest $dto */
        $dto = $this->deserializeAndValidate($request->getContent(), MissionInterventionUpdateRequest::class);

        $this->service->update($intervention, $dto);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{interventionId}', methods: ['DELETE'])]
    public function delete(int $missionId, int $interventionId, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->em->find(Mission::class, $missionId);
        if (!$mission) {
            return $this->json(['message' => 'Mission not found'], Response::HTTP_NOT_FOUND);
        }

        $intervention = $this->em->find(MissionIntervention::class, $interventionId);
        if (!$intervention || $intervention->getMission()?->getId() !== $mission->getId()) {
            return $this->json(['message' => 'Intervention not found'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $this->encodingGuard->assertEncodingAllowed($mission, $user);

        $this->service->delete($intervention);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function deserializeAndValidate(string $json, string $class): object
    {
        $dto = $this->serializer->deserialize($json, $class, 'json');

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            throw new UnprocessableEntityHttpException((string) $errors);
        }

        return $dto;
    }
}
