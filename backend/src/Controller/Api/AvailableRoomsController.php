<?php

namespace App\Controller\Api;

use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\User;
use App\Repository\ReleasedOperatingRoomSlotRepository;
use App\Security\Voter\PlanningVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * « Salles libérées » (Lot D, post D-114) — lecture seule de `ReleasedOperatingRoomSlot`,
 * indépendante des emails Room Release (voir `ReleasedOperatingRoomSlotService`). Deux vues :
 * manager (tous les sites, comme `PlanningVoter::PLANNING_MANAGE` partout ailleurs — aucun
 * scoping par site n'existe pour ce rôle dans ce projet) et chirurgien (strictement scopée à
 * ses propres affiliations `SiteMembership`, jamais un `siteId` client de confiance au-delà de
 * cette intersection — §10 de la demande).
 */
class AvailableRoomsController extends AbstractController
{
    public function __construct(
        private readonly ReleasedOperatingRoomSlotRepository $slots,
    ) {
    }

    #[Route('/api/planning/available-rooms', name: 'api_available_rooms_manager_list', methods: ['GET'])]
    public function managerList(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        [$siteId, $status, $surgeonId, $includePast, $page, $limit] = $this->parseFilters($request, allowSurgeonFilter: true);

        $result = $this->slots->findForList(
            siteIds: null,
            siteId: $siteId,
            status: $status,
            surgeonId: $surgeonId,
            includePast: $includePast,
            page: $page,
            limit: $limit,
        );

        return $this->json([
            'items' => array_map([$this, 'toPayload'], $result['items']),
            'page' => $page,
            'limit' => $limit,
            'total' => $result['total'],
        ]);
    }

    #[Route('/api/me/available-rooms', name: 'api_me_available_rooms_list', methods: ['GET'])]
    public function myList(Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        if (!in_array('ROLE_SURGEON', $currentUser->getRoles(), true)) {
            throw $this->createAccessDeniedException();
        }

        $siteIds = [];
        foreach ($currentUser->getSiteMemberships() as $membership) {
            $site = $membership->getSite();
            if ($site !== null && $site->getId() !== null) {
                $siteIds[] = $site->getId();
            }
        }

        [$siteId, $status, , $includePast, $page, $limit] = $this->parseFilters($request, allowSurgeonFilter: false);

        $result = $this->slots->findForList(
            siteIds: array_values(array_unique($siteIds)),
            siteId: $siteId,
            status: $status,
            surgeonId: null,
            includePast: $includePast,
            page: $page,
            limit: $limit,
        );

        return $this->json([
            'items' => array_map([$this, 'toPayload'], $result['items']),
            'page' => $page,
            'limit' => $limit,
            'total' => $result['total'],
        ]);
    }

    /** @return array{0: ?int, 1: ?string, 2: ?int, 3: bool, 4: int, 5: int} */
    private function parseFilters(Request $request, bool $allowSurgeonFilter): array
    {
        $siteId = $request->query->getInt('siteId', 0) ?: null;
        $status = $request->query->getString('status', '') ?: null;
        $surgeonId = $allowSurgeonFilter ? ($request->query->getInt('surgeonId', 0) ?: null) : null;
        $includePast = $request->query->getBoolean('includePast', false);
        $page = max($request->query->getInt('page', 1), 1);
        $limit = min(max($request->query->getInt('limit', 25), 1), 100);

        return [$siteId, $status, $surgeonId, $includePast, $page, $limit];
    }

    /** @return array<string, mixed> */
    private function toPayload(ReleasedOperatingRoomSlot $s): array
    {
        return [
            'id' => $s->getId(),
            'site' => $s->getSite() !== null ? [
                'id' => $s->getSite()->getId(),
                'name' => $s->getSite()->getName(),
            ] : null,
            'occurrenceDate' => $s->getOccurrenceDate()->format('Y-m-d'),
            'period' => $s->getPeriod()->value,
            'startTime' => $s->getStartTime()?->format('H:i'),
            'endTime' => $s->getEndTime()?->format('H:i'),
            'surgeon' => $s->getSurgeon() !== null ? [
                'id' => $s->getSurgeon()->getId(),
                'name' => $s->getSurgeon()->getDrName(),
            ] : null,
            'status' => $s->getStatus()->value,
            'createdAt' => $s->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
