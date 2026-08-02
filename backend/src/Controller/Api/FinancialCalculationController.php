<?php

namespace App\Controller\Api;

use App\Entity\FinancialCalculation;
use App\Entity\FinancialCalculationLine;
use App\Entity\Mission;
use App\Entity\User;
use App\Security\Voter\BillingVoter;
use App\Service\FinancialCalculationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * EPIC Exécution & Valorisation, Lot 3 (D-073) — §23/§24 du lot. Manager/admin
 * uniquement (BillingVoter::MANAGE, même périmètre que /api/instrumentists/{id}/rates
 * et /api/firms/{id}/pricing-rules — pas de nouveau Voter redondant). Ce contrôleur ne
 * construit jamais de ligne lui-même : tout passe par FinancialCalculationService
 * (§16 du lot). Deux racines de ressource (mission-scopée pour create/list, calcul-
 * scopée pour le reste) — pas de préfixe de classe commun, chaque route porte son
 * chemin complet.
 */
final class FinancialCalculationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FinancialCalculationService $service,
    ) {}

    #[Route('/api/missions/{missionId}/financial-calculations', name: 'api_missions_financial_calculations_create', methods: ['POST'])]
    public function create(int $missionId, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $mission = $this->getMissionOr404($missionId);

        $calculation = $this->service->calculate($mission, $actor);

        return $this->json($this->serialize($calculation), Response::HTTP_CREATED);
    }

    #[Route('/api/missions/{missionId}/financial-calculations', name: 'api_missions_financial_calculations_list', methods: ['GET'])]
    public function list(int $missionId): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $mission = $this->getMissionOr404($missionId);

        $calculations = $this->em->getRepository(FinancialCalculation::class)->findBy(
            ['mission' => $mission],
            ['version' => 'ASC'],
        );

        return $this->json(array_map($this->serialize(...), $calculations));
    }

    #[Route('/api/financial-calculations/{id}', name: 'api_financial_calculations_show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $calculation = $this->getCalculationOr404($id);

        return $this->json($this->serialize($calculation));
    }

    #[Route('/api/financial-calculations/{id}/recalculate', name: 'api_financial_calculations_recalculate', methods: ['POST'])]
    public function recalculate(int $id, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $calculation = $this->getCalculationOr404($id);

        $new = $this->service->recalculate($calculation->getMission(), $actor);

        return $this->json($this->serialize($new), Response::HTTP_CREATED);
    }

    #[Route('/api/financial-calculations/{id}/approve', name: 'api_financial_calculations_approve', methods: ['POST'])]
    public function approve(int $id, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $calculation = $this->getCalculationOr404($id);

        $calculation = $this->service->approve($calculation, $actor);

        return $this->json($this->serialize($calculation));
    }

    #[Route('/api/financial-calculations/{id}/lock', name: 'api_financial_calculations_lock', methods: ['POST'])]
    public function lock(int $id, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $calculation = $this->getCalculationOr404($id);

        $calculation = $this->service->lock($calculation, $actor);

        return $this->json($this->serialize($calculation));
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function getMissionOr404(int $id): Mission
    {
        $mission = $this->em->find(Mission::class, $id);
        if (!$mission instanceof Mission) {
            throw new NotFoundHttpException('Mission not found');
        }
        return $mission;
    }

    private function getCalculationOr404(int $id): FinancialCalculation
    {
        $calculation = $this->em->find(FinancialCalculation::class, $id);
        if (!$calculation instanceof FinancialCalculation) {
            throw new NotFoundHttpException('Financial calculation not found');
        }
        return $calculation;
    }

    /** @return array<string, mixed> */
    private function serialize(FinancialCalculation $c): array
    {
        return [
            'id' => $c->getId(),
            'missionId' => $c->getMission()?->getId(),
            'version' => $c->getVersion(),
            'status' => $c->getStatus()->value,
            'effectiveAt' => $c->getEffectiveAt()?->format('Y-m-d'),
            'currencyPolicy' => $c->getCurrencyPolicy()->value,
            'calculatedAt' => $c->getCalculatedAt()?->format(DATE_ATOM),
            'calculatedBy' => $c->getCalculatedBy()?->getId(),
            'approvedAt' => $c->getApprovedAt()?->format(DATE_ATOM),
            'approvedBy' => $c->getApprovedBy()?->getId(),
            'lockedAt' => $c->getLockedAt()?->format(DATE_ATOM),
            'cancelledAt' => $c->getCancelledAt()?->format(DATE_ATOM),
            'cancelledBy' => $c->getCancelledBy()?->getId(),
            'supersededAt' => $c->getSupersededAt()?->format(DATE_ATOM),
            'supersededByCalculationId' => $c->getSupersededByCalculation()?->getId(),
            'totalsByCurrency' => $c->totalsByCurrency(),
            'lines' => array_map($this->serializeLine(...), $c->getLines()->toArray()),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeLine(FinancialCalculationLine $l): array
    {
        return [
            'id' => $l->getId(),
            'beneficiaryType' => $l->getBeneficiaryType()->value,
            'beneficiaryFirmId' => $l->getBeneficiaryFirm()?->getId(),
            'beneficiaryInstrumentistId' => $l->getBeneficiaryInstrumentist()?->getId(),
            'lineType' => $l->getLineType()->value,
            'sourceType' => $l->getSourceType()->value,
            'descriptionSnapshot' => $l->getDescriptionSnapshot(),
            'quantity' => $l->getQuantity(),
            'durationMinutes' => $l->getDurationMinutes(),
            'unitAmount' => $l->getUnitAmount(),
            'grossAmount' => $l->getGrossAmount(),
            'adjustmentAmount' => $l->getAdjustmentAmount(),
            'totalAmount' => $l->getTotalAmount(),
            'currency' => $l->getCurrency(),
            'effectiveAt' => $l->getEffectiveAt()?->format('Y-m-d'),
            'snapshot' => $l->getSnapshot(),
            'warnings' => $l->getWarnings(),
        ];
    }
}
