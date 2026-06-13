<?php

namespace App\Controller\Api;

use App\Entity\Mission;
use App\Entity\User;
use App\Security\Voter\PlanningVoter;
use App\Service\PlanningGeneratorService;
use App\Service\PlanningScoreService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class PlanningGenerationController extends AbstractController
{
    public function __construct(
        private readonly PlanningGeneratorService $generator,
        private readonly PlanningScoreService $scoreService,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/api/planning/preview', name: 'api_planning_preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $data      = json_decode($request->getContent(), true) ?? [];
        $from      = $data['from'] ?? null;
        $to        = $data['to'] ?? null;
        $siteId    = isset($data['siteId']) ? (int) $data['siteId'] : null;
        $surgeonId = isset($data['surgeonId']) ? (int) $data['surgeonId'] : null;

        if (!$from || !$to) {
            return $this->json(['error' => ['message' => 'from et to sont requis.']], 400);
        }

        try {
            $lines = $this->generator->preview($from, $to, $siteId, $surgeonId);
        } catch (\Exception $e) {
            return $this->json(['error' => ['message' => $e->getMessage()]], 400);
        }

        return $this->json($lines);
    }

    #[Route('/api/planning/generate', name: 'api_planning_generate', methods: ['POST'])]
    public function generate(Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $data      = json_decode($request->getContent(), true) ?? [];
        $from      = $data['from'] ?? null;
        $to        = $data['to'] ?? null;
        $siteId    = isset($data['siteId']) ? (int) $data['siteId'] : null;
        $surgeonId = isset($data['surgeonId']) ? (int) $data['surgeonId'] : null;

        if (!$from || !$to) {
            return $this->json(['error' => ['message' => 'from et to sont requis.']], 400);
        }

        try {
            $stats = $this->generator->generate($from, $to, $siteId, $surgeonId, $currentUser);
        } catch (\Exception $e) {
            return $this->json(['error' => ['message' => $e->getMessage()]], 400);
        }

        return $this->json($stats);
    }

    #[Route('/api/missions/{id}/suggested-instrumentists', name: 'api_missions_suggested_instrumentists', methods: ['GET'])]
    public function suggestedInstrumentists(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $mission = $this->em->find(Mission::class, $id);
        if (!$mission) {
            return $this->json(['error' => ['message' => 'Mission introuvable.']], 404);
        }

        $suggestions = $this->scoreService->suggestForMission($mission);

        return $this->json($suggestions);
    }
}
