<?php

namespace App\Controller\Api;

use App\Dto\Request\InstrumentistMissionSyncRequest;
use App\Entity\User;
use App\Service\InstrumentistMissionSyncService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/instrumentist')]
class InstrumentistMissionSyncController extends AbstractController
{
    public function __construct(
        private readonly InstrumentistMissionSyncService $syncService,
        private readonly ValidatorInterface $validator,
    ) {}

    /**
     * V1 "polling intelligent" — GET /api/instrumentist/missions/sync?since=ISO_DATE
     *
     * Réponse :
     *   {
     *     "serverTime": "...",
     *     "changed": bool,
     *     "missions": MissionListDto[],
     *     "removedMissionIds": int[]
     *   }
     */
    #[Route(path: '/missions/sync', name: 'api_instrumentist_missions_sync', methods: ['GET'])]
    public function sync(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_INSTRUMENTIST');

        $dto = InstrumentistMissionSyncRequest::fromQuery($request->query->all());

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            throw new UnprocessableEntityHttpException((string) $errors);
        }

        $result = $this->syncService->sync($user, $dto->sinceParsed);

        return $this->json($this->syncService->toResponse($result), JsonResponse::HTTP_OK);
    }
}
