<?php

namespace App\Controller\Api;

use App\Entity\Mission;
use App\Entity\User;
use App\Security\Voter\MissionVoter;
use App\Service\MissionReassignmentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use App\Activity\UuidV7;

/**
 * D-181 — changement d'instrumentiste de missions publiées (une ou plusieurs) et statut réel
 * des notifications envoyées. Aucune mutation ici : MissionReassignmentService →
 * MissionPostDeployService ; droits par mission via MissionVoter::REASSIGN.
 */
#[Route(path: '/api/missions')]
final class MissionReassignmentController extends AbstractController
{
    private const MAX_ITEMS = 50;

    public function __construct(
        private readonly MissionReassignmentService $reassignments,
    ) {
    }

    #[Route(path: '/reassignments', name: 'api_missions_reassignments', methods: ['POST'])]
    public function reassign(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data  = json_decode($request->getContent(), true);
        $items = is_array($data) && isset($data['items']) && is_array($data['items']) ? array_values($data['items']) : [];

        if ($items === [] || count($items) > self::MAX_ITEMS || array_filter($items, static fn ($i) => !is_array($i)) !== []) {
            return $this->json(
                ['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => sprintf('items : de 1 à %d missions.', self::MAX_ITEMS), 'violations' => []]],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
        $missionIds = array_map(static fn (array $i) => $i['missionId'] ?? null, $items);
        if (count($missionIds) !== count(array_unique(array_map('strval', $missionIds)))) {
            return $this->json(
                ['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'Une mission ne peut figurer qu\'une fois dans une même opération.', 'violations' => []]],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $result = $this->reassignments->reassign(
            $user,
            $items,
            isset($data['comment']) && is_string($data['comment']) ? $data['comment'] : null,
            fn (Mission $m) => $this->isGranted(MissionVoter::REASSIGN, $m),
        );

        return $this->json($result);
    }

    #[Route(path: '/reassignment-operations/{operationId}', name: 'api_missions_reassignment_operation', methods: ['GET'])]
    public function operation(string $operationId): JsonResponse
    {
        $this->denyAccessUnlessGranted(MissionVoter::VIEW_REASSIGNMENT_OPERATION, Mission::class);
        if (!UuidV7::isValid($operationId)) {
            throw $this->createNotFoundException('Opération introuvable.');
        }

        return $this->json([
            'operationId' => $operationId,
            'recipients'  => $this->reassignments->notificationStatus($operationId),
        ]);
    }
}
