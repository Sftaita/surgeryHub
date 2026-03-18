<?php

namespace App\Controller\Api;

use App\Entity\Firm;
use App\Entity\FirmInvoice;
use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Security\Voter\BillingVoter;
use App\Service\FirmInvoiceService;
use App\Service\NotificationService;
use App\Service\PdfService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/firm-invoices')]
class FirmInvoiceController extends AbstractController
{
    public function __construct(
        private readonly FirmInvoiceService $invoiceService,
        private readonly PdfService $pdfService,
        private readonly EntityManagerInterface $em,
        private readonly NotificationService $notificationService,
    ) {}

    #[Route('', name: 'api_firm_invoices_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $qb = $this->em->createQueryBuilder()
            ->select('i', 'f')
            ->from(FirmInvoice::class, 'i')
            ->join('i.firm', 'f')
            ->orderBy('i.createdAt', 'DESC');

        if ($firmId = $request->query->getInt('firmId')) {
            $qb->andWhere('i.firm = :fid')->setParameter('fid', $firmId);
        }
        if ($status = $request->query->get('status')) {
            $qb->andWhere('i.status = :status')->setParameter('status', InvoiceStatus::from($status));
        }
        if ($year = $request->query->getInt('year')) {
            $qb->andWhere('YEAR(i.periodStart) = :year')->setParameter('year', $year);
        }

        $invoices = $qb->getQuery()->getResult();
        return $this->json(array_map(fn($i) => $this->serializeInvoice($i), $invoices));
    }

    #[Route('/preview', name: 'api_firm_invoices_preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $data = json_decode($request->getContent(), true) ?? [];
        $firmId = $data['firmId'] ?? null;
        $periodStart = $data['periodStart'] ?? null;
        $periodEnd = $data['periodEnd'] ?? null;

        if (!$firmId || !$periodStart || !$periodEnd) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'firmId, periodStart et periodEnd sont requis.']], 422);
        }

        $firm = $this->em->find(Firm::class, $firmId);
        if (!$firm) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Firme introuvable.']], 404);
        }

        try {
            $start = new \DateTimeImmutable($periodStart);
            $end = new \DateTimeImmutable($periodEnd);
        } catch (\Exception) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'Format de date invalide (ISO 8601 attendu).']], 422);
        }

        $preview = $this->invoiceService->preview($firm, $start, $end);
        return $this->json($preview);
    }

    #[Route('', name: 'api_firm_invoices_generate', methods: ['POST'])]
    public function generate(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $data = json_decode($request->getContent(), true) ?? [];
        $firmId = $data['firmId'] ?? null;
        $periodStart = $data['periodStart'] ?? null;
        $periodEnd = $data['periodEnd'] ?? null;
        $selectedInterventionIds = $data['selectedInterventionIds'] ?? [];
        $selectedMaterialLineIds = $data['selectedMaterialLineIds'] ?? [];

        if (!$firmId || !$periodStart || !$periodEnd) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'firmId, periodStart et periodEnd sont requis.']], 422);
        }

        if (empty($selectedInterventionIds) && empty($selectedMaterialLineIds)) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'Sélectionnez au moins une ligne facturable.']], 422);
        }

        $firm = $this->em->find(Firm::class, $firmId);
        if (!$firm) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Firme introuvable.']], 404);
        }

        try {
            $start = new \DateTimeImmutable($periodStart);
            $end = new \DateTimeImmutable($periodEnd);
        } catch (\Exception) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'Format de date invalide.']], 422);
        }

        $invoice = $this->invoiceService->generate($firm, $start, $end, $selectedInterventionIds, $selectedMaterialLineIds);
        return $this->json($this->serializeInvoiceDetail($invoice), 201);
    }

    #[Route('/{id}', name: 'api_firm_invoices_get', methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $invoice = $this->em->find(FirmInvoice::class, $id);
        if (!$invoice) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Facture introuvable.']], 404);
        }

        return $this->json($this->serializeInvoiceDetail($invoice));
    }

    #[Route('/{id}/pdf', name: 'api_firm_invoices_pdf', methods: ['GET'])]
    public function pdf(int $id): Response
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $invoice = $this->em->find(FirmInvoice::class, $id);
        if (!$invoice) {
            return new JsonResponse(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Facture introuvable.']], 404);
        }

        $pdf = $this->pdfService->generateFromTemplate('pdf/firm_invoice.html.twig', ['invoice' => $invoice]);

        $filename = sprintf('facture-%s-%s.pdf',
            strtolower(str_replace(' ', '-', $invoice->getFirm()->getName())),
            $invoice->getNumber() ?? $invoice->getId()
        );

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', $filename),
        ]);
    }

    #[Route('/{id}/send', name: 'api_firm_invoices_send', methods: ['POST'])]
    public function send(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $invoice = $this->em->find(FirmInvoice::class, $id);
        if (!$invoice) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Facture introuvable.']], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $emailTo = $data['emailTo'] ?? $invoice->getBillingEmailTo();
        $emailCc = $data['emailCc'] ?? $invoice->getBillingEmailCc() ?? [];

        if (!$emailTo) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'emailTo requis.']], 422);
        }

        // Snapshot l'email au moment de l'envoi
        $invoice->setBillingEmailTo($emailTo);
        $invoice->setBillingEmailCc($emailCc ?: null);

        try {
            $invoice = $this->invoiceService->markSent($invoice);
        } catch (\DomainException $e) {
            return $this->json(['error' => ['status' => 409, 'code' => 'CONFLICT', 'message' => $e->getMessage()]], 409);
        }

        $pdf = $this->pdfService->generateFromTemplate('pdf/firm_invoice.html.twig', ['invoice' => $invoice]);
        $this->notificationService->sendFirmInvoiceEmail($invoice, $emailTo, $emailCc, $pdf);

        return $this->json($this->serializeInvoiceDetail($invoice));
    }

    #[Route('/{id}/mark-paid', name: 'api_firm_invoices_mark_paid', methods: ['POST'])]
    public function markPaid(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $invoice = $this->em->find(FirmInvoice::class, $id);
        if (!$invoice) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Facture introuvable.']], 404);
        }

        $invoice = $this->invoiceService->markPaid($invoice);
        return $this->json($this->serializeInvoiceDetail($invoice));
    }

    // ── Serializers ───────────────────────────────────────────────────

    private function serializeInvoice(FirmInvoice $i): array
    {
        return [
            'id' => $i->getId(),
            'number' => $i->getNumber(),
            'firm' => ['id' => $i->getFirm()->getId(), 'name' => $i->getFirm()->getName()],
            'status' => $i->getStatus()->value,
            'periodStart' => $i->getPeriodStart()?->format('Y-m-d'),
            'periodEnd' => $i->getPeriodEnd()?->format('Y-m-d'),
            'totalAmount' => $i->getTotalAmount(),
            'generatedAt' => $i->getGeneratedAt()?->format(\DateTimeInterface::ATOM),
            'sentAt' => $i->getSentAt()?->format(\DateTimeInterface::ATOM),
            'paidAt' => $i->getPaidAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $i->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function serializeInvoiceDetail(FirmInvoice $i): array
    {
        $base = $this->serializeInvoice($i);
        $base['billingEmailTo'] = $i->getBillingEmailTo();
        $base['billingEmailCc'] = $i->getBillingEmailCc() ?? [];
        $base['lines'] = array_map(fn($l) => [
            'id' => $l->getId(),
            'missionId' => $l->getMission()->getId(),
            'missionDate' => $l->getMission()->getStartAt()->format('Y-m-d'),
            'interventionId' => $l->getMissionIntervention()?->getId(),
            'materialLineId' => $l->getMaterialLine()?->getId(),
            'lineType' => $l->getLineType()->value,
            'descriptionSnapshot' => $l->getDescriptionSnapshot(),
            'firmNameSnapshot' => $l->getFirmNameSnapshot(),
            'unitPrice' => $l->getUnitPrice(),
            'quantity' => $l->getQuantity(),
            'totalAmount' => $l->getTotalAmount(),
        ], $i->getLines()->toArray());
        return $base;
    }
}
