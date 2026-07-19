<?php

namespace App\Controller\Api;

use App\Dto\FinancialOverviewDto;
use App\Dto\FinancialPipelineDto;
use App\Dto\FinancialStatisticsDrilldownItemDto;
use App\Dto\FinancialTimeSeriesPointDto;
use App\Dto\FirmStatisticsDto;
use App\Dto\InstrumentistStatisticsDto;
use App\Dto\InterventionStatisticsDto;
use App\Dto\MaterialStatisticsDto;
use App\Dto\SurgeonStatisticsDto;
use App\Security\Voter\BillingVoter;
use App\Service\FinancialStatisticsDrilldownService;
use App\Service\FinancialStatisticsQueryService;
use App\Service\FinancialStatisticsRankingService;
use App\Service\FinancialStatisticsRequestParser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — §23/§26/§27 du lot : contrôleur fin, toute la
 * logique métier (filtres, périodes, agrégats, tri/pagination) est déjà centralisée
 * dans FinancialStatisticsRequestParser/FinancialStatisticsQueryService/
 * FinancialStatisticsRankingService/FinancialStatisticsDrilldownService.
 * `BillingVoter::MANAGE` uniquement sur toutes les routes (§27) — aucun accès
 * instrumentiste à ces agrégats globaux.
 *
 * §28 du lot — la consultation de statistiques n'est volontairement PAS auditée (pas de
 * AuditService ici) : auditer une lecture polluerait le journal d'audit sans valeur
 * métier (seule une future exportation le serait).
 */
#[Route('/api/financial-statistics')]
final class FinancialStatisticsController extends AbstractController
{
    public function __construct(
        private readonly FinancialStatisticsRequestParser $parser,
        private readonly FinancialStatisticsQueryService $queryService,
        private readonly FinancialStatisticsRankingService $rankingService,
        private readonly FinancialStatisticsDrilldownService $drilldownService,
    ) {}

    #[Route('/overview', name: 'api_financial_statistics_overview', methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $filter = $this->parser->parseFilter($request);
        return $this->json($this->serializeOverview($this->queryService->overview($filter)));
    }

    #[Route('/timeseries', name: 'api_financial_statistics_timeseries', methods: ['GET'])]
    public function timeseries(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $filter = $this->parser->parseFilter($request);
        $granularity = $this->parser->parseGranularity($request);
        $points = $this->queryService->timeseries($filter, $granularity);
        return $this->json([
            'granularity' => $granularity->value,
            'points' => array_map($this->serializeTimeSeriesPoint(...), $points),
        ]);
    }

    #[Route('/pipeline', name: 'api_financial_statistics_pipeline', methods: ['GET'])]
    public function pipeline(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $filter = $this->parser->parseFilter($request);
        return $this->json($this->serializePipeline($this->queryService->pipeline($filter)));
    }

    #[Route('/by-firm', name: 'api_financial_statistics_by_firm', methods: ['GET'])]
    public function byFirm(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $filter = $this->parser->parseFilter($request);
        $pagination = $this->parser->parsePagination($request, ['generatedRevenue', 'invoicedNetAmount', 'remainingAmount', 'missionCount', 'firmNameSnapshot'], 'generatedRevenue');
        $result = $this->rankingService->byFirm($filter, $pagination['page'], $pagination['limit'], $pagination['sortBy'], $pagination['sortDirection']);
        return $this->json($this->serializePage($result, $this->serializeFirm(...)));
    }

    #[Route('/by-instrumentist', name: 'api_financial_statistics_by_instrumentist', methods: ['GET'])]
    public function byInstrumentist(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $filter = $this->parser->parseFilter($request);
        $pagination = $this->parser->parsePagination($request, ['generatedCompensation', 'statementNetAmount', 'remainingAmount', 'missionCount', 'instrumentistNameSnapshot'], 'generatedCompensation');
        $result = $this->rankingService->byInstrumentist($filter, $pagination['page'], $pagination['limit'], $pagination['sortBy'], $pagination['sortDirection']);
        return $this->json($this->serializePage($result, $this->serializeInstrumentist(...)));
    }

    #[Route('/by-surgeon', name: 'api_financial_statistics_by_surgeon', methods: ['GET'])]
    public function bySurgeon(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $filter = $this->parser->parseFilter($request);
        $pagination = $this->parser->parsePagination($request, ['generatedFirmRevenue', 'generatedInstrumentistCompensation', 'missionCount', 'surgeonNameSnapshot'], 'generatedFirmRevenue');
        $result = $this->rankingService->bySurgeon($filter, $pagination['page'], $pagination['limit'], $pagination['sortBy'], $pagination['sortDirection']);
        return $this->json($this->serializePage($result, $this->serializeSurgeon(...)));
    }

    #[Route('/by-intervention', name: 'api_financial_statistics_by_intervention', methods: ['GET'])]
    public function byIntervention(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $filter = $this->parser->parseFilter($request);
        $pagination = $this->parser->parsePagination($request, ['interventionRevenue', 'materialRevenue', 'missionCount', 'interventionCodeSnapshot'], 'interventionRevenue');
        $result = $this->rankingService->byIntervention($filter, $pagination['page'], $pagination['limit'], $pagination['sortBy'], $pagination['sortDirection']);
        return $this->json($this->serializePage($result, $this->serializeIntervention(...)));
    }

    #[Route('/top-materials', name: 'api_financial_statistics_top_materials', methods: ['GET'])]
    public function topMaterials(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $filter = $this->parser->parseFilter($request);
        $pagination = $this->parser->parsePagination($request, ['generatedRevenue', 'quantity', 'missionCount', 'averageUnitRevenue'], 'generatedRevenue', maxLimit: 100);
        $result = $this->rankingService->topMaterials($filter, $pagination['limit'], $pagination['sortBy'], $pagination['sortDirection']);
        return $this->json($this->serializePage($result, $this->serializeMaterial(...)));
    }

    #[Route('/missions', name: 'api_financial_statistics_missions', methods: ['GET'])]
    public function missions(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $filter = $this->parser->parseFilter($request);
        $pagination = $this->parser->parsePagination($request, ['date'], 'date');
        $result = $this->drilldownService->missions($filter, $pagination['page'], $pagination['limit'], $pagination['sortDirection']);
        return $this->json($this->serializePage($result, $this->serializeDrilldownItem(...)));
    }

    #[Route('/calculations', name: 'api_financial_statistics_calculations', methods: ['GET'])]
    public function calculations(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $filter = $this->parser->parseFilter($request);
        $pagination = $this->parser->parsePagination($request, ['date'], 'date');
        $result = $this->drilldownService->calculations($filter, $pagination['page'], $pagination['limit'], $pagination['sortDirection']);
        return $this->json($this->serializePage($result, $this->serializeDrilldownItem(...)));
    }

    #[Route('/documents', name: 'api_financial_statistics_documents', methods: ['GET'])]
    public function documents(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $filter = $this->parser->parseFilter($request);
        $pagination = $this->parser->parsePagination($request, ['date'], 'date');
        $result = $this->drilldownService->documents($filter, $pagination['page'], $pagination['limit'], $pagination['sortDirection']);
        return $this->json($this->serializePage($result, $this->serializeDrilldownItem(...)));
    }

    // ── Serializers ───────────────────────────────────────────────────

    private function serializeOverview(FinancialOverviewDto $dto): array
    {
        return [
            'from' => $dto->from->format(\DateTimeInterface::ATOM),
            'to' => $dto->to->format(\DateTimeInterface::ATOM),
            'activity' => [
                'missionCount' => $dto->activity->missionCount,
                'executedMissionCount' => $dto->activity->executedMissionCount,
                'validatedMissionCount' => $dto->activity->validatedMissionCount,
                'averageExecutionDurationMinutes' => $dto->activity->averageExecutionDurationMinutes,
            ],
            'currencies' => array_map(static fn ($c) => [
                'currency' => $c->currency,
                'generatedFirmRevenue' => $c->generatedFirmRevenue,
                'generatedInstrumentistCompensation' => $c->generatedInstrumentistCompensation,
                'generatedTotalValue' => $c->generatedTotalValue,
                'generatedContributionMargin' => $c->generatedContributionMargin,
                'invoicedGrossAmount' => $c->invoicedGrossAmount,
                'invoiceCreditNotesAmount' => $c->invoiceCreditNotesAmount,
                'invoiceDebitNotesAmount' => $c->invoiceDebitNotesAmount,
                'invoicedNetAmount' => $c->invoicedNetAmount,
                'statementGrossAmount' => $c->statementGrossAmount,
                'statementCreditNotesAmount' => $c->statementCreditNotesAmount,
                'statementDebitNotesAmount' => $c->statementDebitNotesAmount,
                'statementNetAmount' => $c->statementNetAmount,
                'paymentsIn' => $c->paymentsIn,
                'paymentsOut' => $c->paymentsOut,
                'netCashFlow' => $c->netCashFlow,
                'openFirmBalance' => $c->openFirmBalance,
                'openInstrumentistBalance' => $c->openInstrumentistBalance,
                'averageMissionValue' => $c->averageMissionValue,
            ], $dto->currencies),
        ];
    }

    private function serializeTimeSeriesPoint(FinancialTimeSeriesPointDto $dto): array
    {
        return [
            'periodStart' => $dto->periodStart->format(\DateTimeInterface::ATOM),
            'periodEnd' => $dto->periodEnd->format(\DateTimeInterface::ATOM),
            'missionCount' => $dto->missionCount,
            'currencies' => array_map(static fn ($c) => [
                'currency' => $c->currency,
                'generatedFirmRevenue' => $c->generatedFirmRevenue,
                'generatedInstrumentistCompensation' => $c->generatedInstrumentistCompensation,
                'invoicedNetAmount' => $c->invoicedNetAmount,
                'statementNetAmount' => $c->statementNetAmount,
                'paymentsIn' => $c->paymentsIn,
                'paymentsOut' => $c->paymentsOut,
            ], $dto->currencies),
        ];
    }

    private function serializePipeline(FinancialPipelineDto $dto): array
    {
        return [
            'validatedMissionsWithoutCalculation' => $dto->validatedMissionsWithoutCalculation,
            'calculationsAwaitingApproval' => $dto->calculationsAwaitingApproval,
            'approvedCalculationsWithoutDocuments' => $dto->approvedCalculationsWithoutDocuments,
            'partiallyDocumentedCalculations' => $dto->partiallyDocumentedCalculations,
            'generatedInvoicesNotIssued' => $dto->generatedInvoicesNotIssued,
            'generatedStatementsNotIssued' => $dto->generatedStatementsNotIssued,
            'issuedInvoicesWithOpenBalance' => $dto->issuedInvoicesWithOpenBalance,
            'issuedStatementsWithOpenBalance' => $dto->issuedStatementsWithOpenBalance,
            'overpaidDocumentsAwaitingRefund' => $dto->overpaidDocumentsAwaitingRefund,
        ];
    }

    private function serializeFirm(FirmStatisticsDto $dto): array
    {
        return [
            'firmId' => $dto->firmId,
            'firmNameSnapshot' => $dto->firmNameSnapshot,
            'currency' => $dto->currency,
            'missionCount' => $dto->missionCount,
            'interventionRevenue' => $dto->interventionRevenue,
            'materialRevenue' => $dto->materialRevenue,
            'generatedRevenue' => $dto->generatedRevenue,
            'invoicedNetAmount' => $dto->invoicedNetAmount,
            'paidAmount' => $dto->paidAmount,
            'remainingAmount' => $dto->remainingAmount,
            'averageRevenuePerMission' => $dto->averageRevenuePerMission,
        ];
    }

    private function serializeInstrumentist(InstrumentistStatisticsDto $dto): array
    {
        return [
            'instrumentistId' => $dto->instrumentistId,
            'instrumentistNameSnapshot' => $dto->instrumentistNameSnapshot,
            'currency' => $dto->currency,
            'missionCount' => $dto->missionCount,
            'executedMinutes' => $dto->executedMinutes,
            'hourlyCompensation' => $dto->hourlyCompensation,
            'consultationFees' => $dto->consultationFees,
            'generatedCompensation' => $dto->generatedCompensation,
            'statementNetAmount' => $dto->statementNetAmount,
            'paidAmount' => $dto->paidAmount,
            'remainingAmount' => $dto->remainingAmount,
            'averageCompensationPerMission' => $dto->averageCompensationPerMission,
        ];
    }

    private function serializeSurgeon(SurgeonStatisticsDto $dto): array
    {
        return [
            'surgeonId' => $dto->surgeonId,
            'surgeonNameSnapshot' => $dto->surgeonNameSnapshot,
            'currency' => $dto->currency,
            'missionCount' => $dto->missionCount,
            'executedMissionCount' => $dto->executedMissionCount,
            'generatedFirmRevenue' => $dto->generatedFirmRevenue,
            'generatedInstrumentistCompensation' => $dto->generatedInstrumentistCompensation,
            'averageMissionValue' => $dto->averageMissionValue,
        ];
    }

    private function serializeIntervention(InterventionStatisticsDto $dto): array
    {
        return [
            'interventionTypeId' => $dto->interventionTypeId,
            'interventionCodeSnapshot' => $dto->interventionCodeSnapshot,
            'interventionNameSnapshot' => $dto->interventionNameSnapshot,
            'currency' => $dto->currency,
            'missionCount' => $dto->missionCount,
            'interventionRevenue' => $dto->interventionRevenue,
            'materialRevenue' => $dto->materialRevenue,
            'instrumentistCompensation' => $dto->instrumentistCompensation,
            'averageMissionValue' => $dto->averageMissionValue,
            'averageDurationMinutes' => $dto->averageDurationMinutes,
        ];
    }

    private function serializeMaterial(MaterialStatisticsDto $dto): array
    {
        return [
            'materialId' => $dto->materialId,
            'materialReferenceSnapshot' => $dto->materialReferenceSnapshot,
            'materialNameSnapshot' => $dto->materialNameSnapshot,
            'firmSnapshot' => $dto->firmSnapshot,
            'currency' => $dto->currency,
            'quantity' => $dto->quantity,
            'missionCount' => $dto->missionCount,
            'generatedRevenue' => $dto->generatedRevenue,
            'averageUnitRevenue' => $dto->averageUnitRevenue,
        ];
    }

    private function serializeDrilldownItem(FinancialStatisticsDrilldownItemDto $dto): array
    {
        return [
            'id' => $dto->id,
            'date' => $dto->date->format(\DateTimeInterface::ATOM),
            'beneficiary' => $dto->beneficiary,
            'currency' => $dto->currency,
            'amount' => $dto->amount,
            'status' => $dto->status,
            'sourceType' => $dto->sourceType,
            'sourceId' => $dto->sourceId,
        ];
    }

    /** @param array{items: object[], total: int, page: int, limit: int} $result */
    private function serializePage(array $result, callable $itemSerializer): array
    {
        return [
            'items' => array_map($itemSerializer, $result['items']),
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
        ];
    }
}
