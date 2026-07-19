<?php

namespace App\Controller\Api;

use App\Dto\CorrectionLineInput;
use App\Entity\Firm;
use App\Entity\FirmInvoice;
use App\Entity\Payment;
use App\Entity\User;
use App\Enum\CorrectionReasonCode;
use App\Enum\FinancialDocumentType;
use App\Enum\InvoiceStatus;
use App\Enum\PaymentMethod;
use App\Security\Voter\BillingVoter;
use App\Service\DocumentPaymentService;
use App\Service\FinancialCorrectionService;
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
        private readonly DocumentPaymentService $paymentService,
        private readonly FinancialCorrectionService $correctionService,
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

    #[Route('/{id}', name: 'api_firm_invoices_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $invoice = $this->em->find(FirmInvoice::class, $id);
        if (!$invoice) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Facture introuvable.']], 404);
        }

        return $this->json($this->serializeInvoiceDetail($invoice));
    }

    // ── EPIC Exécution & Valorisation, Lot 4 (D-074) — chemin NOUVEAU ───

    #[Route('/eligible-lines', name: 'api_firm_invoices_eligible_lines', methods: ['GET'])]
    public function eligibleLines(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $firmId = $request->query->getInt('firmId');
        $currency = $request->query->get('currency', 'EUR');
        $periodStart = $request->query->get('periodStart');
        $periodEnd = $request->query->get('periodEnd');

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

        return $this->json($this->invoiceService->previewEligibleLines($firm, $currency, $start, $end));
    }

    #[Route('/from-financial-calculations', name: 'api_firm_invoices_create_from_calculations', methods: ['POST'])]
    public function createFromFinancialCalculations(Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $data = json_decode($request->getContent(), true) ?? [];
        $firmId = $data['firmId'] ?? null;
        $currency = $data['currency'] ?? 'EUR';
        $periodStart = $data['periodStart'] ?? null;
        $periodEnd = $data['periodEnd'] ?? null;
        $selectedLineIds = $data['selectedFinancialCalculationLineIds'] ?? [];

        if (!$firmId || !$periodStart || !$periodEnd || empty($selectedLineIds)) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'firmId, periodStart, periodEnd et selectedFinancialCalculationLineIds sont requis.']], 422);
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

        $invoice = $this->invoiceService->createFromEligibleLines($firm, $currency, $start, $end, $selectedLineIds, $actor);
        return $this->json($this->serializeInvoiceDetail($invoice), 201);
    }

    #[Route('/{id}/cancel', name: 'api_firm_invoices_cancel', methods: ['POST'])]
    public function cancel(int $id, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $invoice = $this->em->find(FirmInvoice::class, $id);
        if (!$invoice) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Facture introuvable.']], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $invoice = $this->invoiceService->cancel($invoice, $actor, $data['reason'] ?? null);

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

        $pdf = $this->pdfService->generateFromTemplate('pdf/firm_invoice.html.twig', [
            'invoice' => $invoice,
            'balance' => $this->paymentService->computeBalance($invoice),
            'payments' => $this->paymentService->getPaymentsFor($invoice),
        ]);

        $filename = sprintf('facture-%s-%s.pdf',
            strtolower(str_replace(' ', '-', $invoice->getFirm()->getName())),
            $invoice->getNumber() ?? $invoice->getId()
        );

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', $filename),
        ]);
    }

    #[Route('/{id}/send', name: 'api_firm_invoices_send', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function send(int $id, Request $request, #[CurrentUser] User $actor): JsonResponse
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
            $invoice = $this->invoiceService->markSent($invoice, $actor);
        } catch (\DomainException $e) {
            return $this->json(['error' => ['status' => 409, 'code' => 'CONFLICT', 'message' => $e->getMessage()]], 409);
        }

        $pdf = $this->pdfService->generateFromTemplate('pdf/firm_invoice.html.twig', ['invoice' => $invoice]);
        $this->notificationService->sendFirmInvoiceEmail($invoice, $emailTo, $emailCc, $pdf);

        return $this->json($this->serializeInvoiceDetail($invoice));
    }

    #[Route('/{id}/mark-paid', name: 'api_firm_invoices_mark_paid', methods: ['POST'], requirements: ['id' => '\d+'])]
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

    // ── EPIC Exécution & Valorisation, Lot 5 (D-075) — émission + paiements ───

    #[Route('/{id}/issue', name: 'api_firm_invoices_issue', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function issue(int $id, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $invoice = $this->em->find(FirmInvoice::class, $id);
        if (!$invoice) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Facture introuvable.']], 404);
        }

        try {
            $invoice = $this->invoiceService->issue($invoice, $actor);
        } catch (\DomainException $e) {
            return $this->json(['error' => ['status' => 409, 'code' => 'CONFLICT', 'message' => $e->getMessage()]], 409);
        }

        return $this->json($this->serializeInvoiceDetail($invoice));
    }

    #[Route('/{id}/payments', name: 'api_firm_invoices_payments_list', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function listPayments(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $invoice = $this->em->find(FirmInvoice::class, $id);
        if (!$invoice) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Facture introuvable.']], 404);
        }

        return $this->json(array_map($this->serializePayment(...), $this->paymentService->getPaymentsFor($invoice)));
    }

    #[Route('/{id}/payments', name: 'api_firm_invoices_payments_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function createPayment(int $id, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $invoice = $this->em->find(FirmInvoice::class, $id);
        if (!$invoice) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Facture introuvable.']], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $amount = $data['amount'] ?? null;
        $currency = $data['currency'] ?? $invoice->getCurrency();
        $paidAtRaw = $data['paidAt'] ?? null;
        $methodRaw = $data['method'] ?? null;

        if ($amount === null || $paidAtRaw === null || $methodRaw === null) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'amount, paidAt et method sont requis.']], 422);
        }

        try {
            $method = PaymentMethod::from($methodRaw);
        } catch (\ValueError) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'method invalide (BANK_TRANSFER, CASH, OTHER).']], 422);
        }

        try {
            $paidAt = new \DateTimeImmutable($paidAtRaw);
        } catch (\Exception) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'Format de paidAt invalide (Y-m-d attendu).']], 422);
        }

        $payment = $this->paymentService->recordPayment(
            $invoice, (string) $amount, (string) $currency, $paidAt, $method,
            $data['reference'] ?? null, $data['comment'] ?? null, $actor,
        );

        return $this->json($this->serializePayment($payment), 201);
    }

    // ── EPIC Exécution & Valorisation, Lot 6 (D-076) — corrections + remboursements ───

    #[Route('/{id}/credit-notes', name: 'api_firm_invoices_credit_notes_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function createCreditNote(int $id, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $root = $this->em->find(FirmInvoice::class, $id);
        if (!$root) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Facture introuvable.']], 404);
        }

        [$lineInputs, $comment, $error] = $this->parseCorrectionRequest($request);
        if ($error !== null) {
            return $this->json($error, 422);
        }

        $correction = $this->correctionService->createCreditNote($root, $lineInputs, $comment, $actor);
        return $this->json($this->serializeInvoiceDetail($correction), 201);
    }

    #[Route('/{id}/debit-notes', name: 'api_firm_invoices_debit_notes_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function createDebitNote(int $id, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $root = $this->em->find(FirmInvoice::class, $id);
        if (!$root) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Facture introuvable.']], 404);
        }

        [$lineInputs, $comment, $error] = $this->parseCorrectionRequest($request);
        if ($error !== null) {
            return $this->json($error, 422);
        }

        $correction = $this->correctionService->createDebitNote($root, $lineInputs, $comment, $actor);
        return $this->json($this->serializeInvoiceDetail($correction), 201);
    }

    #[Route('/{id}/corrections', name: 'api_firm_invoices_corrections_list', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function listCorrections(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $root = $this->em->find(FirmInvoice::class, $id);
        if (!$root) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Facture introuvable.']], 404);
        }

        return $this->json(array_map($this->serializeInvoiceDetail(...), $this->findCorrectionsFor($root)));
    }

    #[Route('/{id}/refunds', name: 'api_firm_invoices_refunds_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function createRefund(int $id, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $root = $this->em->find(FirmInvoice::class, $id);
        if (!$root) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Facture introuvable.']], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $amount = $data['amount'] ?? null;
        $currency = $data['currency'] ?? $root->getCurrency();
        $paidAtRaw = $data['paidAt'] ?? null;
        $methodRaw = $data['method'] ?? null;

        if ($amount === null || $paidAtRaw === null || $methodRaw === null) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'amount, paidAt et method sont requis.']], 422);
        }

        try {
            $method = PaymentMethod::from($methodRaw);
        } catch (\ValueError) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'method invalide (BANK_TRANSFER, CASH, OTHER).']], 422);
        }

        try {
            $paidAt = new \DateTimeImmutable($paidAtRaw);
        } catch (\Exception) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'Format de paidAt invalide (Y-m-d attendu).']], 422);
        }

        $refund = $this->correctionService->recordRefund(
            $root, (string) $amount, (string) $currency, $paidAt, $method,
            $data['reference'] ?? null, $data['comment'] ?? null, $actor,
        );

        return $this->json($this->serializePayment($refund), 201);
    }

    /** @return FirmInvoice[] */
    private function findCorrectionsFor(FirmInvoice $root): array
    {
        return $this->em->getRepository(FirmInvoice::class)->findBy(['correctsDocument' => $root], ['createdAt' => 'ASC']);
    }

    /** @return array{0: CorrectionLineInput[], 1: ?string, 2: ?array} */
    private function parseCorrectionRequest(Request $request): array
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $rawLines = $data['lines'] ?? [];
        $comment = $data['comment'] ?? null;

        if (empty($rawLines)) {
            return [[], null, ['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'Au moins une ligne corrective (lines[]) est requise.']]];
        }

        $lineInputs = [];
        foreach ($rawLines as $raw) {
            try {
                $reasonCode = CorrectionReasonCode::from($raw['reasonCode'] ?? '');
            } catch (\ValueError) {
                return [[], null, ['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'reasonCode invalide.']]];
            }
            if (!isset($raw['description'], $raw['quantity'], $raw['unitAmount'])) {
                return [[], null, ['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'description, quantity et unitAmount sont requis pour chaque ligne.']]];
            }

            $lineInputs[] = new CorrectionLineInput(
                originalDocumentLineId: isset($raw['originalDocumentLineId']) ? (int) $raw['originalDocumentLineId'] : null,
                reasonCode: $reasonCode,
                description: (string) $raw['description'],
                quantity: (string) $raw['quantity'],
                unitAmount: (string) $raw['unitAmount'],
                comment: $raw['comment'] ?? null,
                missionId: isset($raw['missionId']) ? (int) $raw['missionId'] : null,
                financialCalculationLineId: isset($raw['financialCalculationLineId']) ? (int) $raw['financialCalculationLineId'] : null,
            );
        }

        return [$lineInputs, $comment, null];
    }

    // ── Serializers ───────────────────────────────────────────────────

    private function serializeInvoice(FirmInvoice $i): array
    {
        $balance = $this->paymentService->computeBalance($i);

        return [
            'id' => $i->getId(),
            'number' => $i->getNumber(),
            'firm' => ['id' => $i->getFirm()->getId(), 'name' => $i->getFirm()->getName()],
            'status' => $i->getStatus()->value,
            'documentType' => $i->getDocumentType()->value,
            'correctsDocumentId' => $i->getCorrectsDocument()?->getId(),
            'currency' => $i->getCurrency(),
            'legacySource' => $i->isLegacySource(),
            'periodStart' => $i->getPeriodStart()?->format('Y-m-d'),
            'periodEnd' => $i->getPeriodEnd()?->format('Y-m-d'),
            'totalAmount' => $i->getTotalAmount(),
            'generatedAt' => $i->getGeneratedAt()?->format(\DateTimeInterface::ATOM),
            'sentAt' => $i->getSentAt()?->format(\DateTimeInterface::ATOM),
            'paidAt' => $i->getPaidAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $i->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ] + $balance->toArray();
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
            'currency' => $l->getCurrency(),
            'unitSnapshot' => $l->getUnitSnapshot(),
            'financialCalculationLineId' => $l->getFinancialCalculationLine()?->getId(),
            'financialCalculationVersion' => $l->getFinancialCalculationLine()?->getFinancialCalculation()->getVersion(),
            'legacy' => $l->isLegacy(),
            'reasonCode' => $l->getReasonCode()?->value,
            'originalDocumentLineId' => $l->getOriginalDocumentLine()?->getId(),
        ], $i->getLines()->toArray());
        $base['payments'] = array_map($this->serializePayment(...), $this->paymentService->getPaymentsFor($i));
        if ($i->getDocumentType() === FinancialDocumentType::STANDARD) {
            $base['corrections'] = array_map(fn (FirmInvoice $c) => [
                'id' => $c->getId(),
                'documentType' => $c->getDocumentType()->value,
                'status' => $c->getStatus()->value,
                'number' => $c->getNumber(),
                'totalAmount' => $c->getTotalAmount(),
            ], $this->findCorrectionsFor($i));
        }
        return $base;
    }

    private function serializePayment(Payment $p): array
    {
        return [
            'id' => $p->getId(),
            'documentType' => $p->getDocumentType()->value,
            'documentId' => $p->getDocumentId(),
            'amount' => $p->getAmount(),
            'currency' => $p->getCurrency(),
            'paidAt' => $p->getPaidAt()?->format('Y-m-d'),
            'recordedAt' => $p->getRecordedAt()?->format(\DateTimeInterface::ATOM),
            'recordedBy' => $p->getRecordedBy()?->getId(),
            'reference' => $p->getReference(),
            'method' => $p->getMethod()->value,
            'comment' => $p->getComment(),
            'createdAt' => $p->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
