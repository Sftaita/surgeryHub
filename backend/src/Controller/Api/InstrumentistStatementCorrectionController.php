<?php

namespace App\Controller\Api;

use App\Entity\InstrumentistStatement;
use App\Entity\User;
use App\Security\Voter\BillingVoter;
use App\Service\FinancialCorrectionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * EPIC Exécution & Valorisation, Lot 6 (D-076) — voir FirmInvoiceCorrectionController,
 * même contrat pour la famille documentaire InstrumentistStatement.
 */
#[Route('/api/instrumentist-statement-corrections')]
final class InstrumentistStatementCorrectionController extends AbstractController
{
    public function __construct(
        private readonly FinancialCorrectionService $correctionService,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/{id}', name: 'api_instrumentist_statement_corrections_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $correction = $this->findCorrectionOr404($id);
        if ($correction instanceof JsonResponse) {
            return $correction;
        }

        return $this->json($this->serialize($correction));
    }

    #[Route('/{id}/issue', name: 'api_instrumentist_statement_corrections_issue', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function issue(int $id, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $correction = $this->findCorrectionOr404($id);
        if ($correction instanceof JsonResponse) {
            return $correction;
        }

        $issued = $this->correctionService->issueCorrection($correction, $actor);

        return $this->json($this->serialize($issued));
    }

    private function findCorrectionOr404(int $id): InstrumentistStatement|JsonResponse
    {
        $correction = $this->em->find(InstrumentistStatement::class, $id);
        if (!$correction || $correction->getCorrectsDocument() === null) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Correction introuvable.']], 404);
        }
        return $correction;
    }

    private function serialize(InstrumentistStatement $c): array
    {
        return [
            'id' => $c->getId(),
            'number' => $c->getNumber(),
            'documentType' => $c->getDocumentType()->value,
            'status' => $c->getStatus()->value,
            'currency' => $c->getCurrency(),
            'totalAmount' => $c->getTotalAmount(),
            'correctsDocument' => [
                'id' => $c->getCorrectsDocument()?->getId(),
                'number' => $c->getCorrectsDocument()?->getNumber(),
            ],
            'instrumentist' => ['id' => $c->getInstrumentist()?->getId()],
            'sentAt' => $c->getSentAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $c->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'lines' => array_map(static fn ($l) => [
                'id' => $l->getId(),
                'reasonCode' => $l->getReasonCode()?->value,
                'originalDocumentLineId' => $l->getOriginalDocumentLine()?->getId(),
                'descriptionSnapshot' => $l->getDescriptionSnapshot(),
                'quantity' => $l->getQuantity(),
                'rateSnapshot' => $l->getRateSnapshot(),
                'totalAmount' => $l->getTotalAmount(),
                'currency' => $l->getCurrency(),
            ], $c->getLines()->toArray()),
        ];
    }
}
