<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Security\Voter\MyHoursVoter;
use App\Service\MyHoursService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * D-179 — « Mes heures » : relevé mensuel personnel de l'instrumentiste connectée.
 * Toujours self-scopé : aucun identifiant d'instrumentiste n'est accepté (MyHoursVoter).
 */
#[Route('/api/me/hours')]
final class MyHoursController extends AbstractController
{
    public function __construct(private readonly MyHoursService $myHours)
    {
    }

    #[Route('', name: 'api_me_hours', methods: ['GET'])]
    public function get(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(MyHoursVoter::SELF_ACCESS);

        $month = $request->query->get('month');
        if (!is_string($month) || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m) !== 1) {
            return $this->json(['message' => 'Query parameter "month" is required (YYYY-MM)'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $siteParam = $request->query->get('siteId');
        $siteId = null;
        if ($siteParam !== null && $siteParam !== '') {
            if (!is_string($siteParam) || !ctype_digit($siteParam)) {
                return $this->json(['message' => 'Query parameter "siteId" must be a positive integer'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $siteId = (int) $siteParam;
        }

        return $this->json($this->myHours->statement(
            $user,
            (int) $m[1],
            (int) $m[2],
            $siteId,
            $this->isGranted(MyHoursVoter::VIEW_OWN_ESTIMATE, $user),
        ));
    }
}
