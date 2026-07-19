import { apiClient } from "../../../api/apiClient";

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — client pour les 11 endpoints statistiques
 * manager. Filtres communs partagés par tous les endpoints (§6 du lot) — un filtre
 * absent signifie "tous", jamais une valeur devinée côté frontend.
 */
export interface StatisticsFilter {
  from?: string;
  to?: string;
  siteId?: number;
  surgeonId?: number;
  instrumentistId?: number;
  firmId?: number;
  interventionTypeId?: number;
  currency?: string;
}

export interface PaginationParams {
  page?: number;
  limit?: number;
  sortBy?: string;
  sortDirection?: "ASC" | "DESC";
}

export type StatisticsGranularity = "DAY" | "WEEK" | "MONTH";

export interface OverviewActivity {
  missionCount: number;
  executedMissionCount: number;
  validatedMissionCount: number;
  averageExecutionDurationMinutes: number;
}

export interface OverviewCurrency {
  currency: string;
  generatedFirmRevenue: string;
  generatedInstrumentistCompensation: string;
  generatedTotalValue: string;
  generatedContributionMargin: string;
  invoicedGrossAmount: string;
  invoiceCreditNotesAmount: string;
  invoiceDebitNotesAmount: string;
  invoicedNetAmount: string;
  statementGrossAmount: string;
  statementCreditNotesAmount: string;
  statementDebitNotesAmount: string;
  statementNetAmount: string;
  paymentsIn: string;
  paymentsOut: string;
  netCashFlow: string;
  openFirmBalance: string;
  openInstrumentistBalance: string;
  averageMissionValue: string;
}

export interface FinancialOverview {
  from: string;
  to: string;
  activity: OverviewActivity;
  currencies: OverviewCurrency[];
}

export interface TimeSeriesCurrencyAmounts {
  currency: string;
  generatedFirmRevenue: string;
  generatedInstrumentistCompensation: string;
  invoicedNetAmount: string;
  statementNetAmount: string;
  paymentsIn: string;
  paymentsOut: string;
}

export interface TimeSeriesPoint {
  periodStart: string;
  periodEnd: string;
  missionCount: number;
  currencies: TimeSeriesCurrencyAmounts[];
}

export interface TimeSeriesResponse {
  granularity: StatisticsGranularity;
  points: TimeSeriesPoint[];
}

export interface FinancialPipeline {
  validatedMissionsWithoutCalculation: number;
  calculationsAwaitingApproval: number;
  approvedCalculationsWithoutDocuments: number;
  partiallyDocumentedCalculations: number;
  generatedInvoicesNotIssued: number;
  generatedStatementsNotIssued: number;
  issuedInvoicesWithOpenBalance: number;
  issuedStatementsWithOpenBalance: number;
  overpaidDocumentsAwaitingRefund: number;
}

export interface FirmStatistic {
  firmId: number | null;
  firmNameSnapshot: string;
  currency: string;
  missionCount: number;
  interventionRevenue: string;
  materialRevenue: string;
  generatedRevenue: string;
  invoicedNetAmount: string;
  paidAmount: string;
  remainingAmount: string;
  averageRevenuePerMission: string;
}

export interface InstrumentistStatistic {
  instrumentistId: number | null;
  instrumentistNameSnapshot: string;
  currency: string;
  missionCount: number;
  executedMinutes: number;
  hourlyCompensation: string;
  consultationFees: string;
  generatedCompensation: string;
  statementNetAmount: string;
  paidAmount: string;
  remainingAmount: string;
  averageCompensationPerMission: string;
}

export interface SurgeonStatistic {
  surgeonId: number | null;
  surgeonNameSnapshot: string;
  currency: string;
  missionCount: number;
  executedMissionCount: number;
  generatedFirmRevenue: string;
  generatedInstrumentistCompensation: string;
  averageMissionValue: string;
}

export interface InterventionStatistic {
  interventionTypeId: number | null;
  interventionCodeSnapshot: string;
  interventionNameSnapshot: string;
  currency: string;
  missionCount: number;
  interventionRevenue: string;
  materialRevenue: string;
  instrumentistCompensation: string;
  averageMissionValue: string;
  averageDurationMinutes: number;
}

export interface MaterialStatistic {
  materialId: number | null;
  materialReferenceSnapshot: string | null;
  materialNameSnapshot: string;
  firmSnapshot: string;
  currency: string;
  quantity: string;
  missionCount: number;
  generatedRevenue: string;
  averageUnitRevenue: string;
}

export interface DrilldownItem {
  id: number;
  date: string;
  beneficiary: string;
  currency: string | null;
  amount: string | null;
  status: string;
  sourceType: string;
  sourceId: number;
}

export interface Page<T> {
  items: T[];
  total: number;
  page: number;
  limit: number;
}

function toParams(filter: StatisticsFilter, pagination?: PaginationParams, extra?: Record<string, string | number | undefined>) {
  return { ...filter, ...pagination, ...extra };
}

export async function getOverview(filter: StatisticsFilter): Promise<FinancialOverview> {
  const res = await apiClient.get("/api/financial-statistics/overview", { params: toParams(filter) });
  return res.data;
}

export async function getTimeseries(filter: StatisticsFilter, granularity: StatisticsGranularity): Promise<TimeSeriesResponse> {
  const res = await apiClient.get("/api/financial-statistics/timeseries", { params: toParams(filter, undefined, { granularity }) });
  return res.data;
}

export async function getPipeline(filter: StatisticsFilter): Promise<FinancialPipeline> {
  const res = await apiClient.get("/api/financial-statistics/pipeline", { params: toParams(filter) });
  return res.data;
}

export async function getByFirm(filter: StatisticsFilter, pagination: PaginationParams): Promise<Page<FirmStatistic>> {
  const res = await apiClient.get("/api/financial-statistics/by-firm", { params: toParams(filter, pagination) });
  return res.data;
}

export async function getByInstrumentist(filter: StatisticsFilter, pagination: PaginationParams): Promise<Page<InstrumentistStatistic>> {
  const res = await apiClient.get("/api/financial-statistics/by-instrumentist", { params: toParams(filter, pagination) });
  return res.data;
}

export async function getBySurgeon(filter: StatisticsFilter, pagination: PaginationParams): Promise<Page<SurgeonStatistic>> {
  const res = await apiClient.get("/api/financial-statistics/by-surgeon", { params: toParams(filter, pagination) });
  return res.data;
}

export async function getByIntervention(filter: StatisticsFilter, pagination: PaginationParams): Promise<Page<InterventionStatistic>> {
  const res = await apiClient.get("/api/financial-statistics/by-intervention", { params: toParams(filter, pagination) });
  return res.data;
}

export async function getTopMaterials(filter: StatisticsFilter, pagination: PaginationParams): Promise<Page<MaterialStatistic>> {
  const res = await apiClient.get("/api/financial-statistics/top-materials", { params: toParams(filter, pagination) });
  return res.data;
}

export async function getMissionsDrilldown(filter: StatisticsFilter, pagination: PaginationParams): Promise<Page<DrilldownItem>> {
  const res = await apiClient.get("/api/financial-statistics/missions", { params: toParams(filter, pagination) });
  return res.data;
}

export async function getCalculationsDrilldown(filter: StatisticsFilter, pagination: PaginationParams): Promise<Page<DrilldownItem>> {
  const res = await apiClient.get("/api/financial-statistics/calculations", { params: toParams(filter, pagination) });
  return res.data;
}

export async function getDocumentsDrilldown(filter: StatisticsFilter, pagination: PaginationParams): Promise<Page<DrilldownItem>> {
  const res = await apiClient.get("/api/financial-statistics/documents", { params: toParams(filter, pagination) });
  return res.data;
}
