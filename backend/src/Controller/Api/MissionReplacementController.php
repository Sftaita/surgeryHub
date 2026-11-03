<?php

namespace App\Controller\Api;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Security\Voter\MissionVoter;
use App\Service\MissionReplacementService;
use App\Service\MissionService;
use App\Service\Prestation\MissionEncodingDataGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * D-182 — « Mission remplacée par une autre mission » : les missions existantes pouvant être
 * conservées à la place d'une mission, et l'annulation de celle-ci comme doublon. Aucune
 * mutation ici : MissionReplacementService ; droits via MissionVoter::CANCEL (mission annulée)
 * et MissionVoter::APPROVE_DECLARED (déclaration approuvée par la même opération).
 */
#[Route(path: '/api/missions')]
final class MissionReplacementController extends AbstractController
{
    public function __construct(
        private readonly MissionService $missions,
        private readonly MissionReplacementService $replacements,
    ) {
    }

    #[Route(path: '/{id}/replacement-candidates', name: 'api_missions_replacement_candidates', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function candidates(int $id): JsonResponse
    {
        $mission = $this->missions->getOr404($id);
        $this->denyAccessUnlessGranted(MissionVoter::CANCEL, $mission);

        return $this->json(['items' => array_map(static fn (Mission $m) => [
            'id'                => $m->getId(),
            'status'            => $m->getStatus()->value,
            'startAt'           => $m->getStartAt()?->format(\DateTimeInterface::ATOM),
            'endAt'             => $m->getEndAt()?->format(\DateTimeInterface::ATOM),
            'siteName'          => $m->getSite()?->getName(),
            'roomLabel'         => $m->getRoomLabel(),
            'instrumentist'     => $m->getInstrumentist() !== null ? [
                'id'        => $m->getInstrumentist()->getId(),
                'firstname' => $m->getInstrumentist()->getFirstname(),
                'lastname'  => $m->getInstrumentist()->getLastname(),
            ] : null,
            'declared'          => $m->getStatus() === MissionStatus::DECLARED,
            'hasEncodingData'   => MissionEncodingDataGuard::hasEncodingData($m),
        ], $this->replacements->candidates($mission))]);
    }

    #[Route(path: '/{id}/cancel-as-replaced', name: 'api_missions_cancel_as_replaced', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancelAsReplaced(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missions->getOr404($id);
        $this->denyAccessUnlessGranted(MissionVoter::CANCEL, $mission);

        $data          = json_decode($request->getContent(), true);
        $data          = is_array($data) ? $data : [];
        $replacementId = $data['replacementMissionId'] ?? null;
        if (!is_int($replacementId) && !(is_string($replacementId) && ctype_digit($replacementId))) {
            return $this->json(
                ['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'replacementMissionId est requis.', 'violations' => []]],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $approve = ($data['approveReplacement'] ?? false) === true;
        if ($approve) {
            $replacement = $this->missions->getOr404((int) $replacementId);
            if ($replacement->getStatus() === MissionStatus::DECLARED) {
                $this->denyAccessUnlessGranted(MissionVoter::APPROVE_DECLARED, $replacement);
            }
        }

        return $this->json($this->replacements->cancelAsReplaced(
            $user,
            $mission,
            (int) $replacementId,
            $approve,
            isset($data['expectedReplacementStatus']) && is_string($data['expectedReplacementStatus']) ? $data['expectedReplacementStatus'] : null,
            isset($data['internalComment']) && is_string($data['internalComment']) ? $data['internalComment'] : null,
        ));
    }
}
