<?php

namespace App\Controller\Api;

use App\Entity\Mission;
use App\Entity\User;
use App\Security\Voter\MissionVoter;
use App\Service\MissionEncodingService;
use App\Service\MissionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route(path: '/api/missions')]
final class MissionEncodingController extends AbstractController
{
    public function __construct(
        private readonly MissionService $missionService,
        private readonly MissionEncodingService $encodingService,
    ) {}

    #[Route(path: '/{id}/encoding', name: 'api_missions_encoding_get', methods: ['GET'])]
    public function getEncoding(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($id);

        // Même garde que l’écran encodage (instrumentiste assigné / manager admin)
        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);

        $dto = $this->encodingService->buildEncodingDto($mission);

        return $this->json($dto, JsonResponse::HTTP_OK);
    }
}
