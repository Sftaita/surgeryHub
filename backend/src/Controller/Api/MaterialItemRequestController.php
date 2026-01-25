<?php
// src/Controller/Api/MaterialItemRequestController.php

namespace App\Controller\Api;

use App\Dto\Request\MaterialItemRequestCreateRequest;
use App\Entity\MaterialItemRequest;
use App\Entity\User;
use App\Security\Voter\MissionVoter;
use App\Service\MaterialItemRequestService;
use App\Service\MissionEncodingGuard;
use App\Service\MissionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
final class MaterialItemRequestController extends AbstractController
{
    public function __construct(
        private readonly MissionService $missionService,
        private readonly MaterialItemRequestService $service,
        private readonly MissionEncodingGuard $encodingGuard,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route('/missions/{missionId}/material-item-requests', name: 'api_material_item_request_create', methods: ['POST'])]
    public function create(int $missionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($missionId);
        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $this->encodingGuard->assertEncodingAllowed($mission, $user);

        /** @var MaterialItemRequestCreateRequest $dto */
        $dto = $this->serializer->deserialize($request->getContent(), MaterialItemRequestCreateRequest::class, 'json');

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            throw new UnprocessableEntityHttpException((string) $errors);
        }

        $created = $this->service->create($mission, $dto, $user);

        return $this->json($this->toMaterialItemRequestArray($created), JsonResponse::HTTP_CREATED);
    }

    private function toMaterialItemRequestArray(MaterialItemRequest $r): array
    {
        return [
            'id' => (int) $r->getId(),
            'missionId' => (int) $r->getMission()?->getId(),
            'missionInterventionId' => $r->getMissionIntervention()?->getId(),
            'missionInterventionFirmId' => $r->getMissionInterventionFirm()?->getId(),
            'label' => (string) $r->getLabel(),
            'referenceCode' => $r->getReferenceCode(),
            'comment' => $r->getComment(),
            'createdById' => (int) $r->getCreatedBy()?->getId(),
        ];
    }
}
