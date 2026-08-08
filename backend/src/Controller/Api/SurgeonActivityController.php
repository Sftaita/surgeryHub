<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Security\Voter\SurgeonActivityVoter;
use App\Service\SurgeonActivityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Activité personnelle du chirurgien connecté (Lot 4, D-098) — jamais un classement entre
 * chirurgiens : toujours self-scopé sur l'utilisateur authentifié, aucun `surgeonId` accepté
 * en paramètre (voir SurgeonActivityVoter::SELF_ACCESS, rôle-only, sans sujet).
 */
#[Route('/api/surgeon/activity')]
final class SurgeonActivityController extends AbstractController
{
    public function __construct(private readonly SurgeonActivityService $activity)
    {
    }

    #[Route('', name: 'api_surgeon_activity', methods: ['GET'])]
    public function get(Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(SurgeonActivityVoter::SELF_ACCESS);

        $fromStr = $request->query->get('from');
        $toStr = $request->query->get('to');

        if (!is_string($fromStr) || $fromStr === '') {
            return $this->json(['message' => 'Query parameter "from" is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!is_string($toStr) || $toStr === '') {
            return $this->json(['message' => 'Query parameter "to" is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $from = new \DateTimeImmutable($fromStr);
        } catch (\Exception) {
            return $this->json(['message' => 'Invalid from datetime format (ISO 8601 expected)'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $to = new \DateTimeImmutable($toStr);
        } catch (\Exception) {
            return $this->json(['message' => 'Invalid to datetime format (ISO 8601 expected)'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($from >= $to) {
            return $this->json(['message' => 'from must be strictly before to'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->activity->getActivity($currentUser, $from, $to);

        return $this->json([
            'period' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
            'missionCount' => $result['missionCount'],
            'interventionCount' => $result['interventionCount'],
            'interventions' => $result['interventions'],
        ]);
    }
}
