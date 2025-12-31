<?php

namespace App\Controller\Api;

use App\Dto\Request\MaterialLineCreateRequest;
use App\Dto\Request\MaterialLineUpdateRequest;
use App\Dto\Request\MissionInterventionCreateRequest;
use App\Dto\Request\MissionInterventionFirmCreateRequest;
use App\Dto\Request\MissionInterventionFirmUpdateRequest;
use App\Dto\Request\MissionInterventionUpdateRequest;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionFirm;
use App\Entity\MaterialLine;
use App\Security\Voter\MissionVoter;
use App\Service\InterventionService;
use App\Service\MissionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
class InterventionController extends AbstractController
{
    public function __construct(
        private readonly MissionService $missionService,
        private readonly InterventionService $interventionService,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/missions/{missionId}/interventions', name: 'api_intervention_create', methods: ['POST'])]
    public function createIntervention(int $missionId, Request $request): JsonResponse
    {
        $mission = $this->missionService->getOr404($missionId);
        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);

        $dto = $this->deserializeAndValidate($request->getContent(), MissionInterventionCreateRequest::class);
        $intervention = $this->interventionService->addIntervention($mission, $dto);

        return $this->json($intervention, JsonResponse::HTTP_CREATED, [], ['groups' => 'mission:read']);
    }

    #[Route('/missions/{missionId}/interventions/{id}', name: 'api_intervention_update', methods: ['PATCH'])]
    public function updateIntervention(int $missionId, int $id, Request $request): JsonResponse
    {
        $mission = $this->missionService->getOr404($missionId);
        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $intervention = $this->findInterventionOnMission($missionId, $id);

        $dto = $this->deserializeAndValidate($request->getContent(), MissionInterventionUpdateRequest::class);
        $intervention = $this->interventionService->updateIntervention($intervention, $dto);

        return $this->json($intervention, JsonResponse::HTTP_OK, [], ['groups' => 'mission:read']);
    }

    #[Route('/missions/{missionId}/interventions/{id}', name: 'api_intervention_delete', methods: ['DELETE'])]
    public function deleteIntervention(int $missionId, int $id): JsonResponse
    {
        $mission = $this->missionService->getOr404($missionId);
        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $intervention = $this->findInterventionOnMission($missionId, $id);

        $this->interventionService->deleteIntervention($intervention);

        return $this->json(null, JsonResponse::HTTP_NO_CONTENT);
    }

    #[Route('/interventions/{interventionId}/firms', name: 'api_intervention_firm_create', methods: ['POST'])]
    public function createFirm(int $interventionId, Request $request): JsonResponse
    {
        $intervention = $this->em->getRepository(MissionIntervention::class)->find($interventionId);
        if (!$intervention) {
            throw $this->createNotFoundException('Intervention not found');
        }
        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $intervention->getMission());

        $dto = $this->deserializeAndValidate($request->getContent(), MissionInterventionFirmCreateRequest::class);
        $firm = $this->interventionService->addFirm($intervention, $dto);

        return $this->json($firm, JsonResponse::HTTP_CREATED, [], ['groups' => 'mission:read']);
    }

    #[Route('/interventions/{interventionId}/firms/{id}', name: 'api_intervention_firm_update', methods: ['PATCH'])]
    public function updateFirm(int $interventionId, int $id, Request $request): JsonResponse
    {
        $intervention = $this->em->getRepository(MissionIntervention::class)->find($interventionId);
        $firm = $this->em->getRepository(MissionInterventionFirm::class)->find($id);
        if (!$intervention || !$firm) {
            throw $this->createNotFoundException('Firm not found');
        }
        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $intervention->getMission());

        $dto = $this->deserializeAndValidate($request->getContent(), MissionInterventionFirmUpdateRequest::class);
        $firm = $this->interventionService->updateFirm($firm, $dto);

        return $this->json($firm, JsonResponse::HTTP_OK, [], ['groups' => 'mission:read']);
    }

    #[Route('/interventions/{interventionId}/firms/{id}', name: 'api_intervention_firm_delete', methods: ['DELETE'])]
    public function deleteFirm(int $interventionId, int $id): JsonResponse
    {
        $intervention = $this->em->getRepository(MissionIntervention::class)->find($interventionId);
        $firm = $this->em->getRepository(MissionInterventionFirm::class)->find($id);
        if (!$intervention || !$firm) {
            throw $this->createNotFoundException('Firm not found');
        }
        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $intervention->getMission());

        $this->interventionService->deleteFirm($firm);

        return $this->json(null, JsonResponse::HTTP_NO_CONTENT);
    }

    #[Route('/missions/{missionId}/material-lines', name: 'api_material_line_create', methods: ['POST'])]
    public function createMaterialLine(int $missionId, Request $request): JsonResponse
    {
        $mission = $this->missionService->getOr404($missionId);
        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);

        $dto = $this->deserializeAndValidate($request->getContent(), MaterialLineCreateRequest::class);
        $line = $this->interventionService->addMaterialLine($mission, $dto, $this->getUser());

        return $this->json($line, JsonResponse::HTTP_CREATED, [], ['groups' => 'mission:read']);
    }

    #[Route('/missions/{missionId}/material-lines/{id}', name: 'api_material_line_update', methods: ['PATCH'])]
    public function updateMaterialLine(int $missionId, int $id, Request $request): JsonResponse
    {
        $mission = $this->missionService->getOr404($missionId);
        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $line = $this->em->getRepository(MaterialLine::class)->find($id) ?? throw $this->createNotFoundException('Material line not found');

        $dto = $this->deserializeAndValidate($request->getContent(), MaterialLineUpdateRequest::class);
        $line = $this->interventionService->updateMaterialLine($line, $dto);

        return $this->json($line, JsonResponse::HTTP_OK, [], ['groups' => 'mission:read']);
    }

    #[Route('/missions/{missionId}/material-lines/{id}', name: 'api_material_line_delete', methods: ['DELETE'])]
    public function deleteMaterialLine(int $missionId, int $id): JsonResponse
    {
        $mission = $this->missionService->getOr404($missionId);
        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $line = $this->em->getRepository(MaterialLine::class)->find($id) ?? throw $this->createNotFoundException('Material line not found');

        $this->interventionService->deleteMaterialLine($line);

        return $this->json(null, JsonResponse::HTTP_NO_CONTENT);
    }

    private function deserializeAndValidate(string $json, string $class): object
    {
        $dto = $this->serializer->deserialize($json, $class, 'json');
        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            throw $this->createBadRequestException((string) $errors);
        }

        return $dto;
    }

    private function createBadRequestException(string $message): \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
    {
        return new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException($message);
    }

    private function findInterventionOnMission(int $missionId, int $id): MissionIntervention
    {
        $intervention = $this->em->getRepository(MissionIntervention::class)->find($id);
        if (!$intervention || $intervention->getMission()?->getId() !== $missionId) {
            throw $this->createNotFoundException('Intervention not found for mission');
        }

        return $intervention;
    }
}
