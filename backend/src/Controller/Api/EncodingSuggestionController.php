<?php

namespace App\Controller\Api;

use App\Entity\Firm;
use App\Entity\Mission;
use App\Entity\User;
use App\Security\Voter\MissionVoter;
use App\Service\EncodingSuggestionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * D-178 — raccourcis « firmes récemment utilisées » et « interventions récemment utilisées avec
 * cette firme et ce chirurgien » des fenêtres d'ajout / de modification d'une intervention.
 * Lecture seule, réservée à qui peut modifier l'encodage de la mission (MissionVoter) ; renvoie
 * uniquement identifiants, codes et libellés (aucune donnée patient ni financière).
 */
#[Route('/api/missions/{missionId}/encoding-suggestions', requirements: ['missionId' => '\d+'])]
class EncodingSuggestionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EncodingSuggestionService $suggestions,
    ) {}

    #[Route('/firms', name: 'api_missions_encoding_suggestions_firms', methods: ['GET'])]
    public function firms(int $missionId, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->em->find(Mission::class, $missionId);
        if (!$mission instanceof Mission) {
            return $this->notFound('Mission introuvable.');
        }
        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);

        return $this->json(['firms' => $this->suggestions->recentFirmsFor($user)]);
    }

    #[Route('/intervention-types', name: 'api_missions_encoding_suggestions_intervention_types', methods: ['GET'])]
    public function interventionTypes(int $missionId, Request $request): JsonResponse
    {
        $mission = $this->em->find(Mission::class, $missionId);
        if (!$mission instanceof Mission) {
            return $this->notFound('Mission introuvable.');
        }
        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);

        $firmId = $request->query->get('firmId');

        // D-178 — « Aucune firme » : interventions récentes sans firme de ce chirurgien.
        if ($firmId === 'none') {
            $surgeon = $mission->getSurgeon();

            return $this->json([
                'firmId' => null,
                'surgeonId' => $surgeon?->getId(),
                'interventionTypes' => $surgeon !== null ? $this->suggestions->recentInterventionTypesWithoutFirmFor($surgeon) : [],
            ]);
        }

        if ($firmId === null || !ctype_digit((string) $firmId)) {
            return $this->json(['error' => ['status' => 400, 'code' => 'FIRM_ID_REQUIRED', 'message' => 'Le paramètre firmId est requis.']], 400);
        }
        $firm = $this->em->find(Firm::class, (int) $firmId);
        if (!$firm instanceof Firm) {
            return $this->notFound('Firme introuvable.');
        }

        // Le chirurgien réellement porté par la mission — jamais déduit de l'utilisateur connecté.
        $surgeon = $mission->getSurgeon();

        return $this->json([
            'firmId' => (int) $firm->getId(),
            'surgeonId' => $surgeon?->getId(),
            'interventionTypes' => $surgeon !== null ? $this->suggestions->recentInterventionTypesFor($firm, $surgeon) : [],
        ]);
    }

    private function notFound(string $message): JsonResponse
    {
        return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => $message]], 404);
    }
}
