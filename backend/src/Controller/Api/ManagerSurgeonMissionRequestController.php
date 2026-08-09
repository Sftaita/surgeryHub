<?php

namespace App\Controller\Api;

use App\Entity\SurgeonMissionRequest;
use App\Entity\User;
use App\Security\Voter\SurgeonMissionRequestVoter;
use App\Service\SurgeonMissionRequestService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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

        $accepted = $this->service->accept($surgeonMissionRequest, $manager, $reviewComment);

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
        ];
    }
}
