<?php

namespace App\Controller\Api;

use App\Entity\SurgeonMissionRequest;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionDispatchMode;
use App\Security\Voter\SurgeonMissionRequestVoter;
use App\Service\AuditJournalService;
use App\Service\ReleasedRoomRequestMatcher;
use App\Service\RoomSlotDecision;
use App\Service\SurgeonMissionRequestService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Lot 5 (D-099) — revue manager des demandes de mission chirurgien. Voter dédié
 * (SurgeonMissionRequestVoter::MANAGE), jamais BillingVoter::MANAGE (domaine
 * planning/mission, pas catalogue/facturation).
 */
#[Route('/api/manager/surgeon-mission-requests')]
final class ManagerSurgeonMissionRequestController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SurgeonMissionRequestService $service,
        private readonly ReleasedRoomRequestMatcher $roomMatcher,
    ) {
    }

    #[Route('', name: 'api_manager_surgeon_mission_requests_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(SurgeonMissionRequestVoter::MANAGE);

        $status = $request->query->get('status');

        $qb = $this->em->createQueryBuilder()
            ->select('r')
            ->from(SurgeonMissionRequest::class, 'r')
            ->orderBy('r.createdAt', 'DESC');

        if ($status !== null) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        /** @var SurgeonMissionRequest[] $requests */
        $requests = $qb->getQuery()->getResult();

        return $this->json([
            'items' => array_map(fn (SurgeonMissionRequest $r) => $this->serialize($r), $requests),
            'total' => count($requests),
        ]);
    }

    /**
     * D-147 — compteurs par statut (en-tête de l'onglet Demandes), en une requête.
     * Tous les statuts sont toujours présents (0 si aucune demande).
     */
    #[Route('/counts', name: 'api_manager_surgeon_mission_requests_counts', methods: ['GET'])]
    public function counts(): JsonResponse
    {
        $this->denyAccessUnlessGranted(SurgeonMissionRequestVoter::MANAGE);

        $rows = $this->em->createQueryBuilder()
            ->select('r.status AS status', 'COUNT(r.id) AS n')
            ->from(SurgeonMissionRequest::class, 'r')
            ->groupBy('r.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [
            SurgeonMissionRequest::STATUS_PENDING => 0,
            SurgeonMissionRequest::STATUS_ACCEPTED => 0,
            SurgeonMissionRequest::STATUS_REJECTED => 0,
        ];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $this->json($counts);
    }

    /**
     * D-147 — historique des demandes : toutes les actions (création par le chirurgien,
     * acceptation, refus), les plus récentes d'abord, lues dans AuditEvent.
     */
    #[Route('/history', name: 'api_manager_surgeon_mission_requests_history', methods: ['GET'])]
    public function history(Request $request, AuditJournalService $journal): JsonResponse
    {
        $this->denyAccessUnlessGranted(SurgeonMissionRequestVoter::MANAGE);

        return $this->json($journal->search([
            'category' => AuditEventType::CATEGORY_SURGEON_REQUEST,
            'page' => $request->query->getInt('page', 1),
            'limit' => $request->query->getInt('limit', 50),
        ]));
    }

    #[Route('/{id}/accept', name: 'api_manager_surgeon_mission_requests_accept', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function accept(int $id, Request $request, #[CurrentUser] User $manager): JsonResponse
    {
        $this->denyAccessUnlessGranted(SurgeonMissionRequestVoter::MANAGE);

        $surgeonMissionRequest = $this->em->find(SurgeonMissionRequest::class, $id);
        if (!$surgeonMissionRequest instanceof SurgeonMissionRequest) {
            return $this->json(['error' => ['message' => 'Request not found']], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $reviewComment = isset($data['reviewComment']) ? (string) $data['reviewComment'] : null;

        // D-125 — optional `dispatch: {mode: POOL|TARGETED|DIRECT, instrumentistId?}`.
        $dispatch = is_array($data['dispatch'] ?? null) ? $data['dispatch'] : null;
        $mode = null;
        if ($dispatch !== null) {
            $mode = MissionDispatchMode::tryFrom((string) ($dispatch['mode'] ?? ''))
                ?? throw new UnprocessableEntityHttpException('dispatch.mode must be POOL, TARGETED or DIRECT');
        }
        $instrumentistId = isset($dispatch['instrumentistId']) ? (int) $dispatch['instrumentistId'] : null;

        // D-177 — optional `roomSlot: {decision: TAKE_OVER|NONE, slotId?, justification?}`: the
        // manager's explicit choice when the request designates released room(s).
        $roomSlot = RoomSlotDecision::fromArray(is_array($data['roomSlot'] ?? null) ? $data['roomSlot'] : null);

        $accepted = $this->service->accept($surgeonMissionRequest, $manager, $reviewComment, $mode, $instrumentistId, $roomSlot);

        return $this->json($this->serialize($accepted));
    }

    #[Route('/{id}/reject', name: 'api_manager_surgeon_mission_requests_reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reject(int $id, Request $request, #[CurrentUser] User $manager): JsonResponse
    {
        $this->denyAccessUnlessGranted(SurgeonMissionRequestVoter::MANAGE);

        $surgeonMissionRequest = $this->em->find(SurgeonMissionRequest::class, $id);
        if (!$surgeonMissionRequest instanceof SurgeonMissionRequest) {
            return $this->json(['error' => ['message' => 'Request not found']], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $reviewComment = (string) ($data['reviewComment'] ?? '');
        if (trim($reviewComment) === '') {
            return $this->json(['error' => ['message' => 'reviewComment is required.']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $rejected = $this->service->reject($surgeonMissionRequest, $manager, $reviewComment);

        return $this->json($this->serialize($rejected));
    }

    private function serialize(SurgeonMissionRequest $r): array
    {
        $site = $r->getSite();
        $surgeon = $r->getSurgeon();
        $reviewedBy = $r->getReviewedBy();

        return [
            'id' => $r->getId(),
            'surgeon' => $surgeon ? [
                'id' => $surgeon->getId(),
                'displayName' => trim(($surgeon->getFirstname() ?? '') . ' ' . ($surgeon->getLastname() ?? '')),
            ] : null,
            'site' => $site ? ['id' => $site->getId(), 'name' => $site->getName()] : null,
            'type' => $r->getType()?->value,
            'startAt' => $r->getStartAt()?->format(\DateTimeInterface::ATOM),
            'endAt' => $r->getEndAt()?->format(\DateTimeInterface::ATOM),
            'comment' => $r->getComment(),
            'status' => $r->getStatus(),
            'createdAt' => $r->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'reviewedBy' => $reviewedBy ? [
                'id' => $reviewedBy->getId(),
                'displayName' => trim(($reviewedBy->getFirstname() ?? '') . ' ' . ($reviewedBy->getLastname() ?? '')),
            ] : null,
            'reviewedAt' => $r->getReviewedAt()?->format(\DateTimeInterface::ATOM),
            'reviewComment' => $r->getReviewComment(),
            'createdMissionId' => $r->getCreatedMission()?->getId(),
            'createdMissionStatus' => $r->getCreatedMission()?->getStatus()?->value,
            // D-177 — released room(s) this pending request designates (initial surgeon, hours,
            // room status), re-read on every load; drives the acceptance form.
            'releasedRoomMatch' => $r->getStatus() === SurgeonMissionRequest::STATUS_PENDING
                ? $this->roomMatcher->matchRequest($r)->toArray()
                : null,
        ];
    }
}
