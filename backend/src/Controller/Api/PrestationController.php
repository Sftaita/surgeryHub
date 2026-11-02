<?php

namespace App\Controller\Api;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Security\Voter\MissionExecutionVoter;
use App\Security\Voter\MissionVoter;
use App\Service\MissionService;
use App\Service\Prestation\PrestationEncodingService;
use App\Service\Prestation\WorkedPresenceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * D-156 — prestation regroupée multi-salles (encodage commun). Toujours adressée par
 * l'une de ses missions : le groupe est recalculé à chaque appel depuis les missions
 * réelles (jamais un identifiant de groupe persisté). Une prestation d'une seule mission
 * est une prestation ordinaire (même contrat, size = 1).
 *
 * Autorisation : chaque mission du groupe est soumise à son Voter (VIEW, UPDATE des heures,
 * SUBMIT) — jamais une permission de groupe inventée.
 */
#[Route('/api/missions/{missionId}', requirements: ['missionId' => '\d+'])]
final class PrestationController extends AbstractController
{
    public function __construct(
        private readonly MissionService $missionService,
        private readonly PrestationEncodingService $prestations,
        private readonly WorkedPresenceService $presences,
    ) {}

    #[Route('/prestation', name: 'api_missions_prestation_get', methods: ['GET'])]
    public function get(int $missionId, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($missionId);
        $group = $this->viewableGroup($mission);

        return $this->json($this->prestations->view($group, $user, $this->groupRights($group)));
    }

    /**
     * Heures réelles de la prestation, saisies UNE fois. Corps : actualStartAt, actualEndAt
     * (ISO 8601, fin optionnelle = brouillon), expectedMissionIds, expectedRevision?,
     * convertOwnHours? (confirmation explicite de reprendre des heures propres déjà saisies).
     */
    #[Route('/prestation/presence', name: 'api_missions_prestation_presence', methods: ['PUT'])]
    public function presence(int $missionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($missionId);
        $group = $this->viewableGroup($mission);
        foreach ($group as $member) {
            $this->denyAccessUnlessGranted(MissionExecutionVoter::UPDATE, $member);
        }

        $data = $this->body($request);
        if (!isset($data['expectedMissionIds']) || !is_array($data['expectedMissionIds'])) {
            throw new UnprocessableEntityHttpException('expectedMissionIds est obligatoire.');
        }

        $this->presences->declare(
            $mission,
            $user,
            self::date($data['actualStartAt'] ?? null),
            self::date($data['actualEndAt'] ?? null),
            array_map('intval', $data['expectedMissionIds']),
            isset($data['expectedRevision']) ? (int) $data['expectedRevision'] : null,
            (bool) ($data['convertOwnHours'] ?? false),
        );

        $group = $this->prestations->groupOf($mission);

        return $this->json($this->prestations->view($group, $user, $this->groupRights($group)));
    }

    /**
     * Soumission commune. Corps : fingerprint (renvoyé par GET), noMaterialComment? (une seule
     * justification, posée sur les missions BLOC sans matériel — D-080).
     */
    #[Route('/prestation/submit', name: 'api_missions_prestation_submit', methods: ['POST'])]
    public function submit(int $missionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($missionId);
        $group = $this->viewableGroup($mission);
        foreach ($group as $member) {
            if ($member->getStatus() !== MissionStatus::SUBMITTED) {
                $this->denyAccessUnlessGranted(MissionVoter::SUBMIT, $member);
            }
        }

        $data = $this->body($request);
        $fingerprint = isset($data['fingerprint']) ? (string) $data['fingerprint'] : '';
        if ($fingerprint === '') {
            throw new UnprocessableEntityHttpException('fingerprint est obligatoire (renvoyé par GET /prestation).');
        }

        $this->prestations->submit(
            $mission,
            $user,
            $fingerprint,
            isset($data['noMaterialComment']) ? (string) $data['noMaterialComment'] : null,
        );

        $group = $this->prestations->groupOf($mission);

        return $this->json($this->prestations->view($group, $user, $this->groupRights($group)));
    }

    /** Correction contrôlée (manager) : détacher la mission de sa présence partagée. Corps : reason. */
    #[Route('/encoding/detach-presence', name: 'api_missions_encoding_detach_presence', methods: ['POST'])]
    public function detachPresence(int $missionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($missionId);
        $this->denyAccessUnlessGranted(MissionVoter::PRESENCE_DETACH, $mission);

        $data = $this->body($request);
        $this->presences->detach($mission, $user, (string) ($data['reason'] ?? ''));

        $group = $this->prestations->groupOf($mission);

        return $this->json($this->prestations->view($group, $user, $this->groupRights($group)));
    }

    /**
     * D-156 — libellé de salle facultatif (« Salle 3 »). Manager, mission non annulée.
     * Purement descriptif : aucune règle métier ne le lit. Corps : roomLabel (null/"" = retiré).
     */
    #[Route('/room-label', name: 'api_missions_room_label', methods: ['PATCH'])]
    public function roomLabel(int $missionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($missionId);
        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ROOM_LABEL, $mission);

        $data = $this->body($request);
        $this->missionService->updateRoomLabel($mission, $user, isset($data['roomLabel']) ? (string) $data['roomLabel'] : null);

        return $this->json(['id' => $mission->getId(), 'roomLabel' => $mission->getRoomLabel()]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return list<Mission> */
    private function viewableGroup(Mission $mission): array
    {
        $this->denyAccessUnlessGranted(MissionVoter::VIEW, $mission);
        $group = $this->prestations->groupOf($mission);
        foreach ($group as $member) {
            $this->denyAccessUnlessGranted(MissionVoter::VIEW, $member);
        }

        return $group;
    }

    /**
     * @param list<Mission> $group
     * @return array{editPresence: bool, submit: bool, hoursLockedByMissionIds: list<int>}
     */
    private function groupRights(array $group): array
    {
        $lockedBy = [];
        $submit = false;
        $allSubmittable = true;
        foreach ($group as $member) {
            if (!$this->isGranted(MissionExecutionVoter::UPDATE, $member)) {
                $lockedBy[] = (int) $member->getId();
            }
            if ($member->getStatus() !== MissionStatus::SUBMITTED) {
                $submit = true;
                $allSubmittable = $allSubmittable && $this->isGranted(MissionVoter::SUBMIT, $member);
            }
        }

        return ['editPresence' => $lockedBy === [], 'submit' => $submit && $allSubmittable, 'hoursLockedByMissionIds' => $lockedBy];
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $content = $request->getContent();
        $data = $content === '' ? [] : json_decode($content, true);
        if (!is_array($data)) {
            throw new UnprocessableEntityHttpException('Corps JSON invalide.');
        }

        return $data;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Exception) {
            throw new UnprocessableEntityHttpException('Date invalide (ISO 8601 attendu).');
        }
    }
}
