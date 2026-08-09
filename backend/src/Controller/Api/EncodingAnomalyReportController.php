<?php

namespace App\Controller\Api;

use App\Entity\EncodingAnomalyReport;
use App\Entity\User;
use App\Security\Voter\EncodingAnomalyReportVoter;
use App\Service\EncodingAnomalyReportService;
use App\Service\MissionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Lot 6 (D-100) — signalement chirurgien d'une anomalie d'encodage, résolution
 * manager-only en V1. Toujours rattaché à une Mission précise — jamais un listing
 * top-level (§15 : anomalies rares, intrinsèquement liées à une Mission).
 */
final class EncodingAnomalyReportController extends AbstractController
{
    public function __construct(
        private readonly MissionService $missionService,
        private readonly EncodingAnomalyReportService $service,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/missions/{id}/encoding-anomaly-reports', name: 'api_missions_encoding_anomaly_reports_list', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function list(int $id, #[CurrentUser] User $currentUser): JsonResponse
    {
        $mission = $this->missionService->getOr404($id);

        // Visible par le chirurgien de la mission (self-scopé) ou par un manager/admin —
        // jamais un instrumentiste, jamais un autre chirurgien.
        $isManager = in_array('ROLE_MANAGER', $currentUser->getRoles(), true) || in_array('ROLE_ADMIN', $currentUser->getRoles(), true);
        $isOwnMissionSurgeon = $mission->getSurgeon()?->getId() === $currentUser->getId();
        if (!$isManager && !$isOwnMissionSurgeon) {
            throw $this->createAccessDeniedException('You cannot view anomaly reports for this mission.');
        }

        $reports = $this->service->findForMission($mission);

        return $this->json(array_map(fn (EncodingAnomalyReport $r) => $this->serialize($r), $reports));
    }

    #[Route('/api/missions/{id}/encoding-anomaly-reports', name: 'api_missions_encoding_anomaly_reports_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function create(int $id, Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(EncodingAnomalyReportVoter::SELF_ACCESS);

        $mission = $this->missionService->getOr404($id);

        $data = json_decode($request->getContent(), true) ?? [];
        $type = (string) ($data['type'] ?? '');
        $comment = (string) ($data['comment'] ?? '');

        // Jamais $data['reporterId'] / $data['missionSurgeonId'] — self-service always
        // means "for myself", l'appartenance à la mission est revérifiée dans le service.
        $report = $this->service->create($mission, $currentUser, $type, $comment);

        return $this->json($this->serialize($report), Response::HTTP_CREATED);
    }

    #[Route('/api/encoding-anomaly-reports/{id}/resolve', name: 'api_encoding_anomaly_reports_resolve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resolve(int $id, Request $request, #[CurrentUser] User $manager): JsonResponse
    {
        $this->denyAccessUnlessGranted(EncodingAnomalyReportVoter::MANAGE);

        $report = $this->getReportOr404($id);

        $data = json_decode($request->getContent(), true) ?? [];
        $resolutionComment = (string) ($data['resolutionComment'] ?? '');
        if (trim($resolutionComment) === '') {
            return $this->json(['error' => ['message' => 'resolutionComment is required.']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $resolved = $this->service->resolve($report, $manager, $resolutionComment);

        return $this->json($this->serialize($resolved));
    }

    private function getReportOr404(int $id): EncodingAnomalyReport
    {
        $report = $this->em->find(EncodingAnomalyReport::class, $id);
        if (!$report instanceof EncodingAnomalyReport) {
            throw $this->createNotFoundException('Encoding anomaly report not found');
        }
        return $report;
    }

    private function serialize(EncodingAnomalyReport $r): array
    {
        $reporter = $r->getReporter();
        $resolvedBy = $r->getResolvedBy();

        return [
            'id' => $r->getId(),
            'missionId' => $r->getMission()?->getId(),
            'reporter' => $reporter ? [
                'id' => $reporter->getId(),
                'displayName' => trim(($reporter->getFirstname() ?? '') . ' ' . ($reporter->getLastname() ?? '')),
            ] : null,
            'type' => $r->getType(),
            'comment' => $r->getComment(),
            'status' => $r->getStatus(),
            'createdAt' => $r->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'resolvedBy' => $resolvedBy ? [
                'id' => $resolvedBy->getId(),
                'displayName' => trim(($resolvedBy->getFirstname() ?? '') . ' ' . ($resolvedBy->getLastname() ?? '')),
            ] : null,
            'resolvedAt' => $r->getResolvedAt()?->format(\DateTimeInterface::ATOM),
            'resolutionComment' => $r->getResolutionComment(),
        ];
    }
}
