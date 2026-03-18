<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Security\Voter\PlanningVoter;
use App\Service\PlanningDeploymentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class PlanningDeployController extends AbstractController
{
    public function __construct(private readonly PlanningDeploymentService $deploymentService) {}

    #[Route('/api/planning/deploy', name: 'api_planning_deploy', methods: ['POST'])]
    public function deploy(Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $data   = json_decode($request->getContent(), true) ?? [];
        $from   = $data['from'] ?? null;
        $to     = $data['to'] ?? null;
        $siteId = isset($data['siteId']) ? (int) $data['siteId'] : null;

        if (!$from || !$to) {
            return $this->json(['error' => ['message' => 'from et to sont requis.']], 400);
        }

        try {
            $result = $this->deploymentService->deploy($from, $to, $siteId, $currentUser);
        } catch (\Exception $e) {
            return $this->json(['error' => ['message' => $e->getMessage()]], 400);
        }

        return $this->json($result);
    }
}
