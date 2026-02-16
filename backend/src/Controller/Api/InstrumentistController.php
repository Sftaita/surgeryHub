<?php

namespace App\Controller\Api;

use App\Dto\Request\MaterialLineCreateRequest;
use App\Dto\Request\MaterialLineUpdateRequest;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionType;
use App\Security\Voter\MissionVoter;
use App\Service\InterventionService;
use App\Service\MissionEncodingGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/missions/{missionId}/material-lines')]
class InstrumentistController extends AbstractController
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

        if ($mission->getType() === MissionType::CONSULTATION) {
            throw new BadRequestHttpException('Material not allowed for CONSULTATION missions');
        }

        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $this->encodingGuard->assertEncodingAllowed($mission, $user);

        /** @var MaterialLineCreateRequest $dto */
        $dto = $this->deserializeAndValidate($request->getContent(), MaterialLineCreateRequest::class);

        $line = $this->service->createMaterialLine($mission, $dto, $user);

        return $this->json(['id' => $line->getId()], Response::HTTP_CREATED);
    }

    #[Route('/{lineId}', methods: ['PATCH'])]
    public function update(int $missionId, int $lineId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->em->find(Mission::class, $missionId);
        if (!$mission) {
            return $this->json(['message' => 'Mission not found'], Response::HTTP_NOT_FOUND);
        }

        if ($mission->getType() === MissionType::CONSULTATION) {
            throw new BadRequestHttpException('Material not allowed for CONSULTATION missions');
        }

        $line = $this->em->find(MaterialLine::class, $lineId);
        if (!$line || $line->getMission()?->getId() !== $mission->getId()) {
            return $this->json(['message' => 'Material line not found'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $this->encodingGuard->assertEncodingAllowed($mission, $user);

        /** @var MaterialLineUpdateRequest $dto */
        $dto = $this->deserializeAndValidate($request->getContent(), MaterialLineUpdateRequest::class);

        $this->service->updateMaterialLine($line, $dto);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{lineId}', methods: ['DELETE'])]
    public function delete(int $missionId, int $lineId, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->em->find(Mission::class, $missionId);
        if (!$mission) {
            return $this->json(['message' => 'Mission not found'], Response::HTTP_NOT_FOUND);
        }

        if ($mission->getType() === MissionType::CONSULTATION) {
            throw new BadRequestHttpException('Material not allowed for CONSULTATION missions');
        }

        $line = $this->em->find(MaterialLine::class, $lineId);
        if (!$line || $line->getMission()?->getId() !== $mission->getId()) {
            return $this->json(['message' => 'Material line not found'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $this->encodingGuard->assertEncodingAllowed($mission, $user);

        $this->service->deleteMaterialLine($line);

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
