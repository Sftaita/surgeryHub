<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Security\Voter\MissionVoter;
use App\Service\MissionHoursReminderService;
use App\Service\MissionMapper;
use App\Service\MissionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * D-133 — "Rappeler les heures" (cockpit Suivi des encodages). Distinct de
 * POST /api/missions/{id}/encoding/remind (D-120) : demande à l'instrumentiste ses heures
 * réellement prestées, pas la finalisation de son encodage. Ne mute aucun statut — une
 * notification, pas une transition. Rattaché au domaine "exécution" (le réalisé), d'où le
 * chemin /execution/remind.
 */
final class MissionHoursReminderController extends AbstractController
{
    public function __construct(
        private readonly MissionService $missionService,
        private readonly MissionHoursReminderService $hoursReminder,
        private readonly MissionMapper $mapper,
    ) {
    }

    #[Route('/api/missions/{missionId}/execution/remind', name: 'api_missions_execution_remind', methods: ['POST'], requirements: ['missionId' => '\d+'])]
    public function remind(int $missionId, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($missionId);
        $this->denyAccessUnlessGranted(MissionVoter::HOURS_REMIND, $mission);

        $this->hoursReminder->request($mission, $user);

        return $this->json($this->mapper->toDetailDto($mission, $user), Response::HTTP_OK);
    }
}
