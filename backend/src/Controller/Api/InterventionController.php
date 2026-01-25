<?php
// src/Controller/Api/InterventionController.php

namespace App\Controller\Api;

use App\Dto\Request\MaterialLineCreateRequest;
use App\Dto\Request\MaterialLineUpdateRequest;
use App\Dto\Request\MissionInterventionCreateRequest;
use App\Dto\Request\MissionInterventionFirmCreateRequest;
use App\Dto\Request\MissionInterventionFirmUpdateRequest;
use App\Dto\Request\MissionInterventionUpdateRequest;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionFirm;
use App\Entity\User;
use App\Security\Voter\MissionVoter;
use App\Service\InterventionService;
use App\Service\MissionEncodingGuard;
use App\Service\MissionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
class InterventionController extends AbstractController
{
    public function __construct(
        private readonly MissionService $missionService,
        private readonly InterventionService $interventionService,
        private readonly MissionEncodingGuard $encodingGuard,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/missions/{missionId}/interventions', name: 'api_intervention_create', methods: ['POST'])]
    public function createIntervention(int $missionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($missionId);

        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $this->encodingGuard->assertEncodingAllowed($mission, $user);

        /** @var MissionInterventionCreateRequest $dto */
        $dto = $this->deserializeAndValidate($request->getContent(), MissionInterventionCreateRequest::class);

        $intervention = $this->interventionService->addIntervention($mission, $dto);

        return $this->json($this->toInterventionArray($intervention), JsonResponse::HTTP_CREATED);
    }

    #[Route('/missions/{missionId}/interventions/{id}', name: 'api_intervention_update', methods: ['PATCH'])]
    public function updateIntervention(int $missionId, int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($missionId);

        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $this->encodingGuard->assertEncodingAllowed($mission, $user);

        $intervention = $this->findInterventionOnMission($missionId, $id);

        /** @var MissionInterventionUpdateRequest $dto */
        $dto = $this->deserializeAndValidate($request->getContent(), MissionInterventionUpdateRequest::class);

        $intervention = $this->interventionService->updateIntervention($intervention, $dto);

        return $this->json($this->toInterventionArray($intervention), JsonResponse::HTTP_OK);
    }

    #[Route('/missions/{missionId}/interventions/{id}', name: 'api_intervention_delete', methods: ['DELETE'])]
    public function deleteIntervention(int $missionId, int $id, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($missionId);

        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $this->encodingGuard->assertEncodingAllowed($mission, $user);

        $intervention = $this->findInterventionOnMission($missionId, $id);

        $this->interventionService->deleteIntervention($intervention);

        return $this->json(null, JsonResponse::HTTP_NO_CONTENT);
    }

    #[Route('/interventions/{interventionId}/firms', name: 'api_intervention_firm_create', methods: ['POST'])]
    public function createFirm(int $interventionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $intervention = $this->em->getRepository(MissionIntervention::class)->find($interventionId);
        if (!$intervention) {
            throw $this->createNotFoundException('Intervention not found');
        }

        $mission = $intervention->getMission();
        if (!$mission instanceof Mission) {
            throw new NotFoundHttpException('Mission not found');
        }

        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $this->encodingGuard->assertEncodingAllowed($mission, $user);

        /** @var MissionInterventionFirmCreateRequest $dto */
        $dto = $this->deserializeAndValidate($request->getContent(), MissionInterventionFirmCreateRequest::class);

        $firm = $this->interventionService->addFirm($intervention, $dto);

        return $this->json($this->toFirmArray($firm), JsonResponse::HTTP_CREATED);
    }

    #[Route('/interventions/{interventionId}/firms/{id}', name: 'api_intervention_firm_update', methods: ['PATCH'])]
    public function updateFirm(int $interventionId, int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $intervention = $this->em->getRepository(MissionIntervention::class)->find($interventionId);
        $firm = $this->em->getRepository(MissionInterventionFirm::class)->find($id);

        if (!$intervention || !$firm) {
            throw $this->createNotFoundException('Firm not found');
        }

        if ($firm->getMissionIntervention()?->getId() !== $intervention->getId()) {
            throw $this->createNotFoundException('Firm not found for intervention');
        }

        $mission = $intervention->getMission();
        if (!$mission instanceof Mission) {
            throw new NotFoundHttpException('Mission not found');
        }

        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $this->encodingGuard->assertEncodingAllowed($mission, $user);

        /** @var MissionInterventionFirmUpdateRequest $dto */
        $dto = $this->deserializeAndValidate($request->getContent(), MissionInterventionFirmUpdateRequest::class);

        $firm = $this->interventionService->updateFirm($firm, $dto);

        return $this->json($this->toFirmArray($firm), JsonResponse::HTTP_OK);
    }

    #[Route('/interventions/{interventionId}/firms/{id}', name: 'api_intervention_firm_delete', methods: ['DELETE'])]
    public function deleteFirm(int $interventionId, int $id, #[CurrentUser] User $user): JsonResponse
    {
        $intervention = $this->em->getRepository(MissionIntervention::class)->find($interventionId);
        $firm = $this->em->getRepository(MissionInterventionFirm::class)->find($id);

        if (!$intervention || !$firm) {
            throw $this->createNotFoundException('Firm not found');
        }

        if ($firm->getMissionIntervention()?->getId() !== $intervention->getId()) {
            throw $this->createNotFoundException('Firm not found for intervention');
        }

        $mission = $intervention->getMission();
        if (!$mission instanceof Mission) {
            throw new NotFoundHttpException('Mission not found');
        }

        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $this->encodingGuard->assertEncodingAllowed($mission, $user);

        $this->interventionService->deleteFirm($firm);

        return $this->json(null, JsonResponse::HTTP_NO_CONTENT);
    }

    #[Route('/missions/{missionId}/material-lines', name: 'api_material_line_create', methods: ['POST'])]
    public function createMaterialLine(int $missionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($missionId);

        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $this->encodingGuard->assertEncodingAllowed($mission, $user);

        /** @var MaterialLineCreateRequest $dto */
        $dto = $this->deserializeAndValidate($request->getContent(), MaterialLineCreateRequest::class);

        $line = $this->interventionService->addMaterialLine($mission, $dto, $user);

        return $this->json($this->toMaterialLineArray($line), JsonResponse::HTTP_CREATED);
    }

    #[Route('/missions/{missionId}/material-lines/{id}', name: 'api_material_line_update', methods: ['PATCH'])]
    public function updateMaterialLine(int $missionId, int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($missionId);

        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $this->encodingGuard->assertEncodingAllowed($mission, $user);

        $line = $this->em->getRepository(MaterialLine::class)->find($id)
            ?? throw $this->createNotFoundException('Material line not found');

        if ($line->getMission()?->getId() !== $missionId) {
            throw $this->createNotFoundException('Material line not found for mission');
        }

        /** @var MaterialLineUpdateRequest $dto */
        $dto = $this->deserializeAndValidate($request->getContent(), MaterialLineUpdateRequest::class);

        $line = $this->interventionService->updateMaterialLine($line, $dto);

        return $this->json($this->toMaterialLineArray($line), JsonResponse::HTTP_OK);
    }

    #[Route('/missions/{missionId}/material-lines/{id}', name: 'api_material_line_delete', methods: ['DELETE'])]
    public function deleteMaterialLine(int $missionId, int $id, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($missionId);

        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $this->encodingGuard->assertEncodingAllowed($mission, $user);

        $line = $this->em->getRepository(MaterialLine::class)->find($id)
            ?? throw $this->createNotFoundException('Material line not found');

        if ($line->getMission()?->getId() !== $missionId) {
            throw $this->createNotFoundException('Material line not found for mission');
        }

        $this->interventionService->deleteMaterialLine($line);

        return $this->json(null, JsonResponse::HTTP_NO_CONTENT);
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

    private function findInterventionOnMission(int $missionId, int $id): MissionIntervention
    {
        $intervention = $this->em->getRepository(MissionIntervention::class)->find($id);
        if (!$intervention || $intervention->getMission()?->getId() !== $missionId) {
            throw $this->createNotFoundException('Intervention not found for mission');
        }

        return $intervention;
    }

    private function toInterventionArray(MissionIntervention $i): array
    {
        $firms = [];
        foreach ($i->getFirms() as $f) {
            $firms[] = $this->toFirmArray($f);
        }

        return [
            'id' => (int) $i->getId(),
            'missionId' => (int) $i->getMission()?->getId(),
            'code' => (string) $i->getCode(),
            'label' => (string) $i->getLabel(),
            'orderIndex' => (int) ($i->getOrderIndex() ?? 0),
            'firms' => $firms,
        ];
    }

    private function toFirmArray(MissionInterventionFirm $f): array
    {
        return [
            'id' => (int) $f->getId(),
            'interventionId' => (int) $f->getMissionIntervention()?->getId(),
            'firmName' => (string) $f->getFirmName(),
        ];
    }

    private function toMaterialLineArray(MaterialLine $l): array
    {
        $item = $l->getItem();
        $itemArr = null;

        if ($item instanceof MaterialItem) {
            $itemArr = [
                'id' => (int) $item->getId(),
                'manufacturer' => $item->getManufacturer(),
                'referenceCode' => (string) $item->getReferenceCode(),
                'label' => (string) $item->getLabel(),
                'unit' => (string) $item->getUnit(),
                'isImplant' => (bool) $item->isImplant(),
                'active' => (bool) $item->isActive(),
            ];
        }

        return [
            'id' => (int) $l->getId(),
            'missionId' => (int) $l->getMission()?->getId(),
            'missionInterventionId' => $l->getMissionIntervention()?->getId(),
            'missionInterventionFirmId' => $l->getMissionInterventionFirm()?->getId(),
            'item' => $itemArr,
            'quantity' => $l->getQuantity(),
            'comment' => $l->getComment(),
        ];
    }
}
