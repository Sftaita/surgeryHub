import { apiClient } from "../../../api/apiClient";
import type { InvoiceStatus } from "../../billing-firm/api/firmInvoice.api";
import type { CorrectionSummary, DocumentType, PaymentStatus } from "../../billing-shared/api/documentFinance.api";

export interface InstrumentistStatement {
  id: number;
  number: string | null;
  instrumentist: { id: number; displayName: string | null; email: string | null };
  periodYear: number;
  periodMonth: number;
  status: InvoiceStatus;
  documentType: DocumentType;
  correctsDocumentId: number | null;
  totalAmount: string;
  sentAt: string | null;
  paidAt: string | null;
  createdAt: string | null;
  currency: string;
  legacySource: boolean;
  lines?: StatementLine[];
  grossAmount: string;
  originalGrossAmount: string;
  creditNotesAmount: string;
  debitNotesAmount: string;
  netDocumentAmount: string;
  paidAmount: string;
  refundedAmount: string;
  remainingAmount: string;
  overpaidAmount: string;
  paymentStatus: PaymentStatus;
  corrections?: CorrectionSummary[];
}

export interface StatementLine {
  id: number;
  missionId: number;
  missionDate: string | null;
  lineType: "BLOC" | "CONSULTATION";
  descriptionSnapshot?: string | null;
  durationMinutesRaw: number | null;
  durationMinutesRounded: number | null;
  rateSnapshot: string;
  quantity: string;
  totalAmount: string;
  surgeonName: string | null;
  siteName: string | null;
  reasonCode?: string | null;
  originalDocumentLineId?: number | null;
}

export interface StatementPreviewLine {
  missionId: number;
  missionDate: string;
  lineType: "BLOC" | "CONSULTATION";
  durationMinutesRaw: number | null;
  durationMinutesRounded: number | null;
  rateSnapshot: number;
  quantity: number;
  totalAmount: number;
  surgeonName: string | null;
  siteName: string | null;
}

export interface StatementPreview {
  instrumentist: {
    id: number;
    displayName: string;
    email: string;
    hourlyRate: string | null;
    consultationFee: string | null;
  };
  period: { year: number; month: number };
  lines: StatementPreviewLine[];
  totalAmount: number;
  alreadyBilledMissionIds: number[];
}

export async function getStatements(params?: {
  instrumentistId?: number;
  status?: InvoiceStatus;
  year?: number;
}): Promise<InstrumentistStatement[]> {
  const res = await apiClient.get("/api/instrumentist-statements", { params });
  return res.data;
}

export async function previewStatement(body: {
  instrumentistId: number;
  year: number;
  month: number;
}): Promise<StatementPreview> {
  const res = await apiClient.post("/api/instrumentist-statements/preview", body);
  return res.data;
}

export async function generateStatement(body: {
  instrumentistId: number;
  year: number;
  month: number;
  selectedMissionIds: number[];
}): Promise<InstrumentistStatement> {
  const res = await apiClient.post("/api/instrumentist-statements", body);
  return res.data;
}

// ── EPIC Exécution & Valorisation, Lot 4 (D-074) — nouveau flux, sourcé sur
// FinancialCalculationLine (calcul verrouillé) plutôt que recalculé à la volée. ────

export interface EligibleStatementCalculationLine {
  id: number;
  financialCalculationId: number;
  financialCalculationVersion: number;
  missionId: number;
  lineType: "INSTRUMENTIST_HOURLY" | "INSTRUMENTIST_CONSULTATION_FEE";
  descriptionSnapshot: string;
  durationMinutes: number | null;
  quantity: string;
  unitAmount: string;
  totalAmount: string;
  currency: string;
  effectiveAt: string | null;
}

export interface StatementEligibleLinesPreview {
  instrumentist: { id: number; displayName: string };
  currency: string;
  period: { year: number; month: number };
  lines: EligibleStatementCalculationLine[];
  totalAmount: string;
}

export async function getStatementEligibleLines(params: {
  instrumentistId: number;
  currency: string;
  year: number;
  month: number;
}): Promise<StatementEligibleLinesPreview> {
  const res = await apiClient.get("/api/instrumentist-statements/eligible-lines", { params });
  return res.data;
}

export async function createStatementFromCalculations(body: {
  instrumentistId: number;
  currency: string;
  year: number;
  month: number;
  selectedFinancialCalculationLineIds: number[];
}): Promise<InstrumentistStatement> {
  const res = await apiClient.post("/api/instrumentist-statements/from-financial-calculations", body);
  return res.data;
}

export async function getStatement(id: number): Promise<InstrumentistStatement> {
  const res = await apiClient.get(`/api/instrumentist-statements/${id}`);
  return res.data;
}

export async function sendStatement(
  id: number,
  body: { emailTo: string }
): Promise<InstrumentistStatement> {
  const res = await apiClient.post(`/api/instrumentist-statements/${id}/send`, body);
  return res.data;
}

export async function markStatementPaid(id: number): Promise<InstrumentistStatement> {
  const res = await apiClient.post(`/api/instrumentist-statements/${id}/mark-paid`);
  return res.data;
}

export function getStatementPdfUrl(id: number): string {
  return `${import.meta.env.VITE_API_BASE_URL}/api/instrumentist-statements/${id}/pdf`;
}
