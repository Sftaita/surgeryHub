<?php

namespace App\Controller\Api;

use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\User;
use App\Enum\ShiftPeriod;
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
 *
 * Intégration planning chirurgien (revue 2026-09-07) : `dateFrom`/`dateTo`/`period` permettent
 * au calendrier de ne requêter que la fenêtre visible (mois/semaine affiché), jamais tout
 * l'historique — §13. `includePast` n'est **jamais** lu côté chirurgien (`myList()`/`myCount()`
 * l'ignorent structurellement, toujours `false`) — un client ne doit jamais pouvoir demander le
 * passé sur son propre espace (§9), contrairement au manager qui garde ce contrôle.
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

        $f = $this->parseFilters($request, allowSurgeonFilter: true, allowIncludePast: true);
        if ($f instanceof JsonResponse) {
            return $f;
        }

        $result = $this->slots->findForList(
            siteIds: null,
            siteId: $f['siteId'],
            status: $f['status'],
            surgeonId: $f['surgeonId'],
            includePast: $f['includePast'],
            page: $f['page'],
            limit: $f['limit'],
            dateFrom: $f['dateFrom'],
            dateTo: $f['dateTo'],
            period: $f['period'],
        );

        return $this->json([
            'items' => array_map([$this, 'toPayload'], $result['items']),
            'page' => $f['page'],
            'limit' => $f['limit'],
            'total' => $result['total'],
        ]);
    }

    #[Route('/api/me/available-rooms', name: 'api_me_available_rooms_list', methods: ['GET'])]
    public function myList(Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        if (!in_array('ROLE_SURGEON', $currentUser->getRoles(), true)) {
            throw $this->createAccessDeniedException();
        }

        $f = $this->parseFilters($request, allowSurgeonFilter: false, allowIncludePast: false);
        if ($f instanceof JsonResponse) {
            return $f;
        }

        $siteIds = $this->affiliatedSiteIds($currentUser);

        $result = $this->slots->findForList(
            siteIds: $siteIds,
            siteId: $f['siteId'],
            status: $f['status'],
            surgeonId: null,
            includePast: false,
            page: $f['page'],
            limit: $f['limit'],
            dateFrom: $f['dateFrom'],
            dateTo: $f['dateTo'],
            period: $f['period'],
        );

        return $this->json([
            'items' => array_map([$this, 'toPayload'], $result['items']),
            'page' => $f['page'],
            'limit' => $f['limit'],
            'total' => $result['total'],
        ]);
    }

    /**
     * Endpoint léger pour le badge CTA du planning chirurgien (revue 2026-09-07) — un seul
     * `SELECT COUNT`, jamais la liste complète. Mêmes filtres/scoping que `myList()`.
     */
    #[Route('/api/me/available-rooms/count', name: 'api_me_available_rooms_count', methods: ['GET'])]
    public function myCount(Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        if (!in_array('ROLE_SURGEON', $currentUser->getRoles(), true)) {
            throw $this->createAccessDeniedException();
        }

        $f = $this->parseFilters($request, allowSurgeonFilter: false, allowIncludePast: false);
        if ($f instanceof JsonResponse) {
            return $f;
        }

        $count = $this->slots->countForList(
            siteIds: $this->affiliatedSiteIds($currentUser),
            siteId: $f['siteId'],
            status: $f['status'],
            surgeonId: null,
            includePast: false,
            dateFrom: $f['dateFrom'],
            dateTo: $f['dateTo'],
            period: $f['period'],
        );

        return $this->json(['count' => $count]);
    }

    /** @return list<int> */
    private function affiliatedSiteIds(User $surgeon): array
    {
        $siteIds = [];
        foreach ($surgeon->getSiteMemberships() as $membership) {
            $site = $membership->getSite();
            if ($site !== null && $site->getId() !== null) {
                $siteIds[] = $site->getId();
            }
        }

        return array_values(array_unique($siteIds));
    }

    /**
     * @return array{siteId: ?int, status: ?string, surgeonId: ?int, includePast: bool, page: int, limit: int, dateFrom: ?\DateTimeImmutable, dateTo: ?\DateTimeImmutable, period: ?string}|JsonResponse
     */
    private function parseFilters(Request $request, bool $allowSurgeonFilter, bool $allowIncludePast): array|JsonResponse
    {
        $siteId = $request->query->getInt('siteId', 0) ?: null;
        $status = $request->query->getString('status', '') ?: null;
        $surgeonId = $allowSurgeonFilter ? ($request->query->getInt('surgeonId', 0) ?: null) : null;
        // §9 : le chirurgien ne peut jamais demander le passé sur son propre espace — le
        // paramètre n'est même pas lu si allowIncludePast est faux, jamais une simple
        // vérification a posteriori qu'un futur refactor pourrait manquer.
        $includePast = $allowIncludePast && $request->query->getBoolean('includePast', false);
        $page = max($request->query->getInt('page', 1), 1);
        $limit = min(max($request->query->getInt('limit', 25), 1), 100);

        $dateFrom = null;
        $rawDateFrom = $request->query->getString('dateFrom', '');
        if ($rawDateFrom !== '') {
            $dateFrom = \DateTimeImmutable::createFromFormat('!Y-m-d', $rawDateFrom) ?: null;
            if ($dateFrom === null) {
                return $this->json(['error' => ['message' => 'dateFrom invalide, format attendu Y-m-d.']], 400);
            }
        }

        $dateTo = null;
        $rawDateTo = $request->query->getString('dateTo', '');
        if ($rawDateTo !== '') {
            $dateTo = \DateTimeImmutable::createFromFormat('!Y-m-d', $rawDateTo) ?: null;
            if ($dateTo === null) {
                return $this->json(['error' => ['message' => 'dateTo invalide, format attendu Y-m-d.']], 400);
            }
        }

        $period = null;
        $rawPeriod = $request->query->getString('period', '');
        if ($rawPeriod !== '') {
            if (ShiftPeriod::tryFrom($rawPeriod) === null) {
                return $this->json(['error' => ['message' => 'period invalide.']], 400);
            }
            $period = $rawPeriod;
        }

        return [
            'siteId' => $siteId,
            'status' => $status,
            'surgeonId' => $surgeonId,
            'includePast' => $includePast,
            'page' => $page,
            'limit' => $limit,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'period' => $period,
        ];
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
