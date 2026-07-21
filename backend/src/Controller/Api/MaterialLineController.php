<?php

namespace App\Controller\Api;

use App\Dto\Request\MaterialLineCreateRequest;
use App\Dto\Request\MaterialLineUpdateRequest;
use App\Dto\Request\Response\FirmSlimDto;
use App\Dto\Request\Response\MaterialItemSlimDto;
use App\Dto\Request\Response\MissionEncodingMaterialLineDto;
use App\Entity\MaterialLine;
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
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * EPIC Revue instrumentiste, Lot 3, commit 4 — create() ne résout plus l'item/la cible
 * lui-même : délègue entièrement à InterventionService::createMaterialLine(), qui
 * consomme MaterialAttachmentResolver pour interventionDraftId (jamais interprété ici).
 * update()/delete() restent inchangés — interventionDraftId n'est pas encore supporté
 * pour un repointage (hors périmètre de ce commit).
 */
#[Route('/api/missions/{missionId}/material-lines')]
class MaterialLineController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InterventionService $service,
        private readonly MissionEncodingGuard $encodingGuard,
        private readonly SerializerInterface $serializer,
    ) {}

    #[Route('', name: 'api_material_lines_create', methods: ['POST'])]
    public function create(int $missionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->em->find(Mission::class, $missionId);
        if (!$mission) {
            return $this->json(['message' => 'Mission not found'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $this->encodingGuard->assertEncodingAllowed($mission, $user);

        /** @var MaterialLineCreateRequest $dto */
        $dto = $this->serializer->deserialize(
            $request->getContent(),
            MaterialLineCreateRequest::class,
            'json',
        );

        $line = $this->service->createMaterialLine($mission, $dto, $user);

        return $this->json($this->toDto($line), Response::HTTP_CREATED);
    }

    #[Route('/{lineId}', name: 'api_material_lines_update', methods: ['PATCH'], requirements: ['lineId' => '\d+'])]
    public function update(int $missionId, int $lineId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->em->find(Mission::class, $missionId);
        if (!$mission) {
            return $this->json(['message' => 'Mission not found'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $this->encodingGuard->assertEncodingAllowed($mission, $user);

        $line = $this->em->find(MaterialLine::class, $lineId);
        if (!$line || $line->getMission()?->getId() !== $mission->getId()) {
            return $this->json(['message' => 'Material line not found'], Response::HTTP_NOT_FOUND);
        }

        /** @var MaterialLineUpdateRequest $dto */
        $dto = $this->serializer->deserialize(
            $request->getContent(),
            MaterialLineUpdateRequest::class,
            'json',
        );

        if ($dto->quantity !== null) {
            $line->setQuantity($dto->quantity);
        }

        if ($dto->comment !== null) {
            $line->setComment($dto->comment);
        }

        if ($dto->missionInterventionId !== null) {
            $intervention = $this->em->find(MissionIntervention::class, $dto->missionInterventionId);
            if (!$intervention || $intervention->getMission()?->getId() !== $mission->getId()) {
                return $this->json(['message' => 'Intervention not found on this mission'], Response::HTTP_NOT_FOUND);
            }
            $line->setMissionIntervention($intervention);
        }

        $this->em->flush();

        return $this->json($this->toDto($line));
    }

    #[Route('/{lineId}', name: 'api_material_lines_delete', methods: ['DELETE'], requirements: ['lineId' => '\d+'])]
    public function delete(int $missionId, int $lineId, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->em->find(Mission::class, $missionId);
        if (!$mission) {
            return $this->json(['message' => 'Mission not found'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $this->encodingGuard->assertEncodingAllowed($mission, $user);

        $line = $this->em->find(MaterialLine::class, $lineId);
        if (!$line || $line->getMission()?->getId() !== $mission->getId()) {
            return $this->json(['message' => 'Material line not found'], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($line);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function toDto(MaterialLine $line): MissionEncodingMaterialLineDto
    {
        $item = $line->getItem();
        $firm = $item?->getFirm();

        return new MissionEncodingMaterialLineDto(
            id: (int) $line->getId(),
            missionInterventionId: $line->getMissionIntervention()?->getId(),
            item: new MaterialItemSlimDto(
                id: (int) $item?->getId(),
                firm: $firm ? new FirmSlimDto(id: (int) $firm->getId(), name: (string) $firm->getName()) : null,
                referenceCode: (string) $item?->getReferenceCode(),
                label: (string) $item?->getLabel(),
                unit: (string) $item?->getUnit(),
                isImplant: (bool) $item?->isImplant(),
                active: (bool) $item?->isActive(),
            ),
            quantity: (string) $line->getQuantity(),
            comment: $line->getComment(),
            interventionDraftId: $line->getInterventionDraft()?->getId(),
        );
    }
}
