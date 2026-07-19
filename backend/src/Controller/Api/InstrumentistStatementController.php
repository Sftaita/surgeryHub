<?php

namespace App\Controller\Api;

use App\Dto\CorrectionLineInput;
use App\Entity\InstrumentistStatement;
use App\Entity\Payment;
use App\Entity\User;
use App\Enum\CorrectionReasonCode;
use App\Enum\FinancialDocumentType;
use App\Enum\InvoiceStatus;
use App\Enum\PaymentMethod;
use App\Security\Voter\BillingVoter;
use App\Service\DocumentPaymentService;
use App\Service\FinancialCorrectionService;
use App\Service\InstrumentistStatementService;
use App\Service\NotificationService;
use App\Service\PdfService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/instrumentist-statements')]
class InstrumentistStatementController extends AbstractController
{
    public function __construct(
        private readonly InstrumentistStatementService $statementService,
        private readonly DocumentPaymentService $paymentService,
        private readonly FinancialCorrectionService $correctionService,
        private readonly PdfService $pdfService,
        private readonly EntityManagerInterface $em,
        private readonly NotificationService $notificationService,
    ) {}

    #[Route('', name: 'api_statements_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $qb = $this->em->createQueryBuilder()
            ->select('s', 'i')
            ->from(InstrumentistStatement::class, 's')
            ->join('s.instrumentist', 'i')
            ->orderBy('s.periodYear', 'DESC')
            ->addOrderBy('s.periodMonth', 'DESC');

        if ($instrumentistId = $request->query->getInt('instrumentistId')) {
            $qb->andWhere('s.instrumentist = :iid')->setParameter('iid', $instrumentistId);
        }
        if ($status = $request->query->get('status')) {
            $qb->andWhere('s.status = :status')->setParameter('status', InvoiceStatus::from($status));
        }
        if ($year = $request->query->getInt('year')) {
            $qb->andWhere('s.periodYear = :year')->setParameter('year', $year);
        }

        $statements = $qb->getQuery()->getResult();

        return $this->json(array_map(fn($s) => $this->serializeStatement($s), $statements));
    }

    #[Route('/preview', name: 'api_statements_preview', methods: ['POST'])]
    public function preview(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $data = json_decode($request->getContent(), true) ?? [];
        $instrumentistId = $data['instrumentistId'] ?? null;
        $year = (int) ($data['year'] ?? 0);
        $month = (int) ($data['month'] ?? 0);

        if (!$instrumentistId || !$year || !$month || $month < 1 || $month > 12) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'instrumentistId, year et month sont requis.']], 422);
        }

        $instrumentist = $this->em->find(User::class, $instrumentistId);
        if (!$instrumentist || !in_array('ROLE_INSTRUMENTIST', $instrumentist->getRoles(), true)) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Instrumentiste introuvable.']], 404);
        }

        $preview = $this->statementService->preview($instrumentist, $year, $month);
        return $this->json($preview);
    }

    #[Route('', name: 'api_statements_generate', methods: ['POST'])]
    public function generate(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $data = json_decode($request->getContent(), true) ?? [];
        $instrumentistId = $data['instrumentistId'] ?? null;
        $year = (int) ($data['year'] ?? 0);
        $month = (int) ($data['month'] ?? 0);
        $selectedMissionIds = $data['selectedMissionIds'] ?? [];

        if (!$instrumentistId || !$year || !$month || empty($selectedMissionIds)) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'instrumentistId, year, month et selectedMissionIds sont requis.']], 422);
        }

        $instrumentist = $this->em->find(User::class, $instrumentistId);
        if (!$instrumentist || !in_array('ROLE_INSTRUMENTIST', $instrumentist->getRoles(), true)) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Instrumentiste introuvable.']], 404);
        }

        try {
            $statement = $this->statementService->generate($instrumentist, $year, $month, $selectedMissionIds);
        } catch (\DomainException $e) {
            return $this->json(['error' => ['status' => 409, 'code' => 'CONFLICT', 'message' => $e->getMessage()]], 409);
        }

        return $this->json($this->serializeStatementDetail($statement), 201);
    }

    #[Route('/{id}', name: 'api_statements_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $statement = $this->em->find(InstrumentistStatement::class, $id);
        if (!$statement) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Décompte introuvable.']], 404);
        }

        return $this->json($this->serializeStatementDetail($statement));
    }

    // ── EPIC Exécution & Valorisation, Lot 4 (D-074) — chemin NOUVEAU ───

    #[Route('/eligible-lines', name: 'api_statements_eligible_lines', methods: ['GET'])]
    public function eligibleLines(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $instrumentistId = $request->query->getInt('instrumentistId');
        $currency = $request->query->get('currency', 'EUR');
        $year = $request->query->getInt('year');
        $month = $request->query->getInt('month');

        if (!$instrumentistId || !$year || !$month || $month < 1 || $month > 12) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'instrumentistId, year et month sont requis.']], 422);
        }

        $instrumentist = $this->em->find(User::class, $instrumentistId);
        if (!$instrumentist || !in_array('ROLE_INSTRUMENTIST', $instrumentist->getRoles(), true)) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Instrumentiste introuvable.']], 404);
        }

        return $this->json($this->statementService->previewEligibleLines($instrumentist, $currency, $year, $month));
    }

    #[Route('/from-financial-calculations', name: 'api_statements_create_from_calculations', methods: ['POST'])]
    public function createFromFinancialCalculations(Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $data = json_decode($request->getContent(), true) ?? [];
        $instrumentistId = $data['instrumentistId'] ?? null;
        $currency = $data['currency'] ?? 'EUR';
        $year = (int) ($data['year'] ?? 0);
        $month = (int) ($data['month'] ?? 0);
        $selectedLineIds = $data['selectedFinancialCalculationLineIds'] ?? [];

        if (!$instrumentistId || !$year || !$month || empty($selectedLineIds)) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'instrumentistId, year, month et selectedFinancialCalculationLineIds sont requis.']], 422);
        }

        $instrumentist = $this->em->find(User::class, $instrumentistId);
        if (!$instrumentist || !in_array('ROLE_INSTRUMENTIST', $instrumentist->getRoles(), true)) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Instrumentiste introuvable.']], 404);
        }

        $statement = $this->statementService->createFromEligibleLines($instrumentist, $currency, $year, $month, $selectedLineIds, $actor);
        return $this->json($this->serializeStatementDetail($statement), 201);
    }

    #[Route('/{id}/cancel', name: 'api_statements_cancel', methods: ['POST'])]
    public function cancel(int $id, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $statement = $this->em->find(InstrumentistStatement::class, $id);
        if (!$statement) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Décompte introuvable.']], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $statement = $this->statementService->cancel($statement, $actor, $data['reason'] ?? null);

        return $this->json($this->serializeStatementDetail($statement));
    }

    #[Route('/{id}/pdf', name: 'api_statements_pdf', methods: ['GET'])]
    public function pdf(int $id): Response
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $statement = $this->em->find(InstrumentistStatement::class, $id);
        if (!$statement) {
            return new JsonResponse(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Décompte introuvable.']], 404);
        }

        $pdf = $this->pdfService->generateFromTemplate('pdf/instrumentist_statement.html.twig', [
            'statement' => $statement,
            'balance' => $this->paymentService->computeBalance($statement),
            'payments' => $this->paymentService->getPaymentsFor($statement),
        ]);

        $filename = sprintf('decompte-%s-%02d-%d.pdf',
            strtolower(str_replace(' ', '-', $statement->getInstrumentistNameSnapshot() ?? 'instrumentiste')),
            $statement->getPeriodMonth(),
            $statement->getPeriodYear()
        );

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', $filename),
        ]);
    }

    #[Route('/{id}/send', name: 'api_statements_send', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function send(int $id, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $statement = $this->em->find(InstrumentistStatement::class, $id);
        if (!$statement) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Décompte introuvable.']], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $emailTo = $data['emailTo'] ?? $statement->getInstrumentistEmailSnapshot();

        if (!$emailTo) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'emailTo requis.']], 422);
        }

        try {
            $statement = $this->statementService->markSent($statement, $actor);
        } catch (\DomainException $e) {
            return $this->json(['error' => ['status' => 409, 'code' => 'CONFLICT', 'message' => $e->getMessage()]], 409);
        }

        $pdf = $this->pdfService->generateFromTemplate('pdf/instrumentist_statement.html.twig', ['statement' => $statement]);

        $this->notificationService->sendStatementEmail($statement, $emailTo, $pdf);

        return $this->json($this->serializeStatementDetail($statement));
    }

    #[Route('/{id}/mark-paid', name: 'api_statements_mark_paid', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function markPaid(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $statement = $this->em->find(InstrumentistStatement::class, $id);
        if (!$statement) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Décompte introuvable.']], 404);
        }

        $statement = $this->statementService->markPaid($statement);
        return $this->json($this->serializeStatementDetail($statement));
    }

    // ── EPIC Exécution & Valorisation, Lot 5 (D-075) — émission + paiements ───

    #[Route('/{id}/issue', name: 'api_statements_issue', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function issue(int $id, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $statement = $this->em->find(InstrumentistStatement::class, $id);
        if (!$statement) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Décompte introuvable.']], 404);
        }

        try {
            $statement = $this->statementService->issue($statement, $actor);
        } catch (\DomainException $e) {
            return $this->json(['error' => ['status' => 409, 'code' => 'CONFLICT', 'message' => $e->getMessage()]], 409);
        }

        return $this->json($this->serializeStatementDetail($statement));
    }

    #[Route('/{id}/payments', name: 'api_statements_payments_list', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function listPayments(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $statement = $this->em->find(InstrumentistStatement::class, $id);
        if (!$statement) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Décompte introuvable.']], 404);
        }

        return $this->json(array_map($this->serializePayment(...), $this->paymentService->getPaymentsFor($statement)));
    }

    #[Route('/{id}/payments', name: 'api_statements_payments_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function createPayment(int $id, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $statement = $this->em->find(InstrumentistStatement::class, $id);
        if (!$statement) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Décompte introuvable.']], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $amount = $data['amount'] ?? null;
        $currency = $data['currency'] ?? $statement->getCurrency();
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
            $statement, (string) $amount, (string) $currency, $paidAt, $method,
            $data['reference'] ?? null, $data['comment'] ?? null, $actor,
        );

        return $this->json($this->serializePayment($payment), 201);
    }

    // ── EPIC Exécution & Valorisation, Lot 6 (D-076) — corrections + remboursements ───

    #[Route('/{id}/credit-notes', name: 'api_statements_credit_notes_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function createCreditNote(int $id, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $root = $this->em->find(InstrumentistStatement::class, $id);
        if (!$root) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Décompte introuvable.']], 404);
        }

        [$lineInputs, $comment, $error] = $this->parseCorrectionRequest($request);
        if ($error !== null) {
            return $this->json($error, 422);
        }

        $correction = $this->correctionService->createCreditNote($root, $lineInputs, $comment, $actor);
        return $this->json($this->serializeStatementDetail($correction), 201);
    }

    #[Route('/{id}/debit-notes', name: 'api_statements_debit_notes_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function createDebitNote(int $id, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $root = $this->em->find(InstrumentistStatement::class, $id);
        if (!$root) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Décompte introuvable.']], 404);
        }

        [$lineInputs, $comment, $error] = $this->parseCorrectionRequest($request);
        if ($error !== null) {
            return $this->json($error, 422);
        }

        $correction = $this->correctionService->createDebitNote($root, $lineInputs, $comment, $actor);
        return $this->json($this->serializeStatementDetail($correction), 201);
    }

    #[Route('/{id}/corrections', name: 'api_statements_corrections_list', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function listCorrections(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $root = $this->em->find(InstrumentistStatement::class, $id);
        if (!$root) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Décompte introuvable.']], 404);
        }

        return $this->json(array_map($this->serializeStatementDetail(...), $this->findCorrectionsFor($root)));
    }

    #[Route('/{id}/refunds', name: 'api_statements_refunds_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function createRefund(int $id, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $root = $this->em->find(InstrumentistStatement::class, $id);
        if (!$root) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Décompte introuvable.']], 404);
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

    /** @return InstrumentistStatement[] */
    private function findCorrectionsFor(InstrumentistStatement $root): array
    {
        return $this->em->getRepository(InstrumentistStatement::class)->findBy(['correctsDocument' => $root], ['createdAt' => 'ASC']);
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

    private function serializeStatement(InstrumentistStatement $s): array
    {
        $balance = $this->paymentService->computeBalance($s);

        return [
            'id' => $s->getId(),
            'number' => $s->getNumber(),
            'instrumentist' => [
                'id' => $s->getInstrumentist()->getId(),
                'displayName' => $s->getInstrumentistNameSnapshot(),
                'email' => $s->getInstrumentistEmailSnapshot(),
            ],
            'periodYear' => $s->getPeriodYear(),
            'periodMonth' => $s->getPeriodMonth(),
            'status' => $s->getStatus()->value,
            'documentType' => $s->getDocumentType()->value,
            'correctsDocumentId' => $s->getCorrectsDocument()?->getId(),
            'currency' => $s->getCurrency(),
            'legacySource' => $s->isLegacySource(),
            'totalAmount' => $s->getTotalAmount(),
            'sentAt' => $s->getSentAt()?->format(\DateTimeInterface::ATOM),
            'paidAt' => $s->getPaidAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $s->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ] + $balance->toArray();
    }

    private function serializeStatementDetail(InstrumentistStatement $s): array
    {
        $base = $this->serializeStatement($s);
        $base['lines'] = array_map(fn($l) => [
            'id' => $l->getId(),
            'missionId' => $l->getMission()->getId(),
            'missionDate' => $l->getMissionDateSnapshot()?->format('Y-m-d'),
            'lineType' => $l->getLineType()->value,
            'descriptionSnapshot' => $l->getDescriptionSnapshot(),
            'durationMinutesRaw' => $l->getDurationMinutesRaw(),
            'durationMinutesRounded' => $l->getDurationMinutesRounded(),
            'rateSnapshot' => $l->getRateSnapshot(),
            'quantity' => $l->getQuantity(),
            'totalAmount' => $l->getTotalAmount(),
            'currency' => $l->getCurrency(),
            'unitSnapshot' => $l->getUnitSnapshot(),
            'surgeonName' => $l->getSurgeonNameSnapshot(),
            'siteName' => $l->getSiteNameSnapshot(),
            'financialCalculationLineId' => $l->getFinancialCalculationLine()?->getId(),
            'financialCalculationVersion' => $l->getFinancialCalculationLine()?->getFinancialCalculation()->getVersion(),
            'legacy' => $l->isLegacy(),
            'reasonCode' => $l->getReasonCode()?->value,
            'originalDocumentLineId' => $l->getOriginalDocumentLine()?->getId(),
        ], $s->getLines()->toArray());
        $base['payments'] = array_map($this->serializePayment(...), $this->paymentService->getPaymentsFor($s));
        if ($s->getDocumentType() === FinancialDocumentType::STANDARD) {
            $base['corrections'] = array_map(fn (InstrumentistStatement $c) => [
                'id' => $c->getId(),
                'documentType' => $c->getDocumentType()->value,
                'status' => $c->getStatus()->value,
                'number' => $c->getNumber(),
                'totalAmount' => $c->getTotalAmount(),
            ], $this->findCorrectionsFor($s));
        }
        return $base;
    }

    private function serializePayment(Payment $p): array
    {
        return [
            'id' => $p->getId(),
            'documentType' => $p->getDocumentType()->value,
            'documentId' => $p->getDocumentId(),
            'direction' => $p->getDirection()->value,
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
