<?php

namespace App\Controller\Api;

use App\Dto\Request\MaterialItemRequestCreateRequest;
use App\Entity\Mission;
use App\Security\Voter\MissionVoter;
use App\Service\MaterialItemRequestService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/missions/{missionId}/material-item-requests')]
class MaterialItemRequestController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MaterialItemRequestService $service,
    ) {}

    #[Route('', methods: ['POST'])]
    public function create(int $missionId, Request $request): JsonResponse
    {
        $mission = $this->em->find(Mission::class, $missionId);
        if (!$mission) {
            return $this->json(['message' => 'Mission not found'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);

        /** @var MaterialItemRequestCreateRequest $dto */
        $dto = $this->get('serializer')->deserialize(
            $request->getContent(),
            MaterialItemRequestCreateRequest::class,
            'json'
        );

        $req = $this->service->create($mission, $dto);

        return $this->json(['id' => $req->getId()], Response::HTTP_CREATED);
    }
}
