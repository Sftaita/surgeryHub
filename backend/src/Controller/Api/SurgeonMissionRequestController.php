<?php

namespace App\Controller\Api;

use App\Entity\SurgeonMissionRequest;
use App\Entity\User;
use App\Enum\MissionType;
use App\Security\Voter\SurgeonMissionRequestVoter;
use App\Service\SurgeonMissionRequestService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Lot 5 (D-099) — self-service : le chirurgien crée/consulte SES demandes uniquement.
 * Jamais MissionVoter::CREATE ici — une SurgeonMissionRequest n'est pas une Mission
 * (voir docblock de l'entité). Ownership toujours serveur : `surgeon` vaut
 * systématiquement l'utilisateur authentifié, jamais un `surgeonId` client — aucun code
 * path ne lit ce champ dans le body.
 */
#[Route('/api/surgeon/mission-requests')]
final class SurgeonMissionRequestController extends AbstractController
{
    public function __construct(private readonly SurgeonMissionRequestService $service)
    {
    }

    #[Route('', name: 'api_surgeon_mission_requests_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(SurgeonMissionRequestVoter::SELF_ACCESS);

        $requests = $this->service->findForSurgeon($currentUser);

        return $this->json(array_map(fn (SurgeonMissionRequest $r) => $this->serialize($r), $requests));
    }

    #[Route('', name: 'api_surgeon_mission_requests_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(SurgeonMissionRequestVoter::SELF_ACCESS);

        $data = json_decode($request->getContent(), true) ?? [];

        $siteId = $data['siteId'] ?? null;
        if (!is_int($siteId) && !ctype_digit((string) $siteId)) {
            return $this->json(['error' => ['message' => 'siteId is required.']], 422);
        }

        $type = MissionType::tryFrom((string) ($data['type'] ?? ''));
        if ($type === null) {
            return $this->json(['error' => ['message' => 'type is invalid.']], 422);
        }

        [$start, $end, $error] = $this->parseRange($data['startAt'] ?? null, $data['endAt'] ?? null);
        if ($error !== null) {
            return $this->json(['error' => ['message' => $error]], 422);
        }

        // Jamais $data['surgeonId'] — self-service always means "for myself".
        $created = $this->service->create(
            $currentUser,
            (int) $siteId,
            $type,
            $start,
            $end,
            isset($data['comment']) ? (string) $data['comment'] : null,
        );

        return $this->json($this->serialize($created), 201);
    }

    /** @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable, 2: ?string} */
    private function parseRange(mixed $rawStart, mixed $rawEnd): array
    {
        if (!is_string($rawStart) || $rawStart === '' || !is_string($rawEnd) || $rawEnd === '') {
            return [null, null, 'startAt et endAt sont requis.'];
        }

        try {
            $start = new \DateTimeImmutable($rawStart);
            $end = new \DateTimeImmutable($rawEnd);
        } catch (\Exception) {
            return [null, null, 'Format de date invalide.'];
        }

        if ($end <= $start) {
            return [null, null, 'endAt doit être strictement après startAt.'];
        }

        return [$start, $end, null];
    }

    private function serialize(SurgeonMissionRequest $r): array
    {
        $site = $r->getSite();

        return [
            'id' => $r->getId(),
            'site' => $site ? ['id' => $site->getId(), 'name' => $site->getName()] : null,
            'type' => $r->getType()?->value,
            'startAt' => $r->getStartAt()?->format(\DateTimeInterface::ATOM),
            'endAt' => $r->getEndAt()?->format(\DateTimeInterface::ATOM),
            'comment' => $r->getComment(),
            'status' => $r->getStatus(),
            'createdAt' => $r->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'reviewedAt' => $r->getReviewedAt()?->format(\DateTimeInterface::ATOM),
            'reviewComment' => $r->getReviewComment(),
            'createdMissionId' => $r->getCreatedMission()?->getId(),
        ];
    }
}
