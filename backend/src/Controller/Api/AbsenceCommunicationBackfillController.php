<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Security\Voter\PlanningVoter;
use App\Service\AbsenceCommunicationBackfillService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Communication des absences chirurgiens — Lot C (D-114). Rattrapage des absences déjà
 * existantes. `preview` est strictement en lecture (§5) ; `execute` revalide tout côté
 * serveur et ne fait jamais confiance au contenu d'une preview passée (§9).
 */
#[Route('/api/planning/absence-communications/backfill')]
class AbsenceCommunicationBackfillController extends AbstractController
{
    public function __construct(
        private readonly AbsenceCommunicationBackfillService $backfillService,
    ) {
    }

    #[Route('/preview', name: 'api_absence_communications_backfill_preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $createdFrom = $this->parseCreatedFrom($request);
        if ($createdFrom === null) {
            return $this->json(['error' => ['message' => 'createdFrom (date) est requis.']], 400);
        }

        return $this->json($this->backfillService->preview($createdFrom));
    }

    #[Route('/execute', name: 'api_absence_communications_backfill_execute', methods: ['POST'])]
    public function execute(Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $createdFrom = $this->parseCreatedFrom($request);
        if ($createdFrom === null) {
            return $this->json(['error' => ['message' => 'createdFrom (date) est requis.']], 400);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $absenceIds = $data['absenceIds'] ?? null;
        if (!is_array($absenceIds) || empty($absenceIds)) {
            return $this->json(['error' => ['message' => 'absenceIds (liste non vide) est requis.']], 400);
        }
        foreach ($absenceIds as $id) {
            if (!is_int($id)) {
                return $this->json(['error' => ['message' => 'absenceIds doit être une liste d\'entiers.']], 400);
            }
        }

        return $this->json($this->backfillService->execute($createdFrom, $absenceIds, $currentUser));
    }

    /**
     * Revue finale (§3) — le manager saisit une simple date, interprétée comme minuit dans
     * la timezone métier `Europe/Brussels` (jamais UTC naïvement), puis convertie en UTC
     * avant utilisation : `Absence::createdAt` est posé par `new \DateTimeImmutable()` sans
     * fuseau explicite dans un runtime PHP dont le fuseau par défaut est UTC (vérifié : ce
     * n'est PAS un wall-clock "déjà traité comme Europe/Brussels" à la manière de
     * `Mission.startAt`/`CheckUncoveredEscalationsCommand` — ces deux conventions coexistent
     * dans ce projet et ne doivent jamais être confondues). Comparer un cutoff Brussels non
     * converti à un `createdAt` réellement UTC déciderait faux de 1h ou 2h selon la saison
     * (heure d'été/hiver) autour de minuit.
     */
    private function parseCreatedFrom(Request $request): ?\DateTimeImmutable
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $raw = $data['createdFrom'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            // Borne inclusive dès minuit Europe/Brussels — §2 : une absence créée exactement
            // le jour du cutoff (heure locale) est éligible.
            $brusselsMidnight = new \DateTimeImmutable($raw . ' 00:00:00', new \DateTimeZone('Europe/Brussels'));
            return $brusselsMidnight->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
