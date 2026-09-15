import { apiClient } from "../../../api/apiClient";
import type { CorrectionSummary, DocumentType, PaymentStatus } from "../../billing-shared/api/documentFinance.api";

export type InvoiceStatus = "DRAFT" | "GENERATED" | "SENT" | "PAID" | "CANCELLED";

export interface FirmInvoice {
  id: number;
  number: string | null;
  firm: { id: number; name: string };
  status: InvoiceStatus;
  /** EPIC Exécution & Valorisation, Lot 6 — STANDARD pour un document racine, CREDIT_NOTE/DEBIT_NOTE pour une correction. */
  documentType: DocumentType;
  correctsDocumentId: number | null;
  periodStart: string;
  periodEnd: string;
  totalAmount: string;
  billingEmailTo?: string | null;
  billingEmailCc?: string[];
  generatedAt: string | null;
  sentAt: string | null;
  paidAt: string | null;
  createdAt: string | null;
  currency: string;
  legacySource: boolean;
  lines?: FirmInvoiceLine[];
  /** Modèle financier net dérivé (Lot 5-6) — jamais stocké, toujours recalculé côté backend. */
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
  /** Uniquement présent sur un document STANDARD (jamais sur une correction elle-même). */
  corrections?: CorrectionSummary[];
}

export interface FirmInvoiceLine {
  id: number;
  missionId: number;
  missionDate: string;
  interventionId: number | null;
  materialLineId: number | null;
  lineType: "INTERVENTION_FEE" | "MATERIAL_FEE";
  descriptionSnapshot: string;
  firmNameSnapshot: string;
  unitPrice: string;
  quantity: string;
  totalAmount: string;
  currency?: string;
  financialCalculationLineId?: number | null;
  legacy?: boolean;
  reasonCode?: string | null;
  originalDocumentLineId?: number | null;
}

export async function getFirmInvoices(params?: {
  firmId?: number;
  status?: InvoiceStatus;
  year?: number;
}): Promise<FirmInvoice[]> {
  const res = await apiClient.get("/api/firm-invoices", { params });
  return res.data;
}

// ── EPIC Exécution & Valorisation, Lot 4 (D-074), nettoyage architectural (D-121) —
// unique flux : sourcé sur FinancialCalculationLine (calcul verrouillé), jamais
// recalculé à la volée. ────────────────────────────────────────────────────────

/**
 * Diagnostic explicatif (D-121, §6) — présent uniquement quand `lines` est vide.
 * Codes stables renvoyés par le backend, jamais un message déduit côté frontend.
 */
export interface EligibleLinesDiagnostic {
  code: "NO_ELIGIBLE_LINES";
  validatedMissionCount: number;
  calculationCount: number;
  calculatedCount: number;
  approvedCount: number;
  lockedCount: number;
  missingPricingCount: number;
  currencyMismatchCount: number;
  alreadyInvoicedCount: number;
  reasons: (
    | "NO_VALIDATED_MISSIONS"
    | "NO_FINANCIAL_CALCULATIONS"
    | "CALCULATIONS_PENDING_APPROVAL"
    | "MISSING_PRICING"
    | "CURRENCY_MISMATCH"
    | "ALREADY_INVOICED"
    | "NO_LINES_FOR_BENEFICIARY"
  )[];
}

export interface EligibleCalculationLine {
  id: number;
  financialCalculationId: number;
  financialCalculationVersion: number;
  missionId: number;
  lineType: "FIRM_INTERVENTION_FEE" | "FIRM_MATERIAL_FEE";
  descriptionSnapshot: string;
  quantity: string;
  unitAmount: string;
  totalAmount: string;
  currency: string;
  effectiveAt: string | null;
}

export interface FirmEligibleLinesPreview {
  firm: { id: number; name: string };
  currency: string;
  period: { start: string; end: string };
  lines: EligibleCalculationLine[];
  totalAmount: string;
  diagnostic?: EligibleLinesDiagnostic;
}

export async function getFirmEligibleLines(params: {
  firmId: number;
  currency: string;
  periodStart: string;
  periodEnd: string;
}): Promise<FirmEligibleLinesPreview> {
  const res = await apiClient.get("/api/firm-invoices/eligible-lines", { params });
  return res.data;
}

export async function createFirmInvoiceFromCalculations(body: {
  firmId: number;
  currency: string;
  periodStart: string;
  periodEnd: string;
  selectedFinancialCalculationLineIds: number[];
}): Promise<FirmInvoice> {
  const res = await apiClient.post("/api/firm-invoices/from-financial-calculations", body);
  return res.data;
}

export async function getFirmInvoice(id: number): Promise<FirmInvoice> {
  const res = await apiClient.get(`/api/firm-invoices/${id}`);
  return res.data;
}

export async function sendFirmInvoice(
  id: number,
  body: { emailTo: string; emailCc?: string[] }
): Promise<FirmInvoice> {
  const res = await apiClient.post(`/api/firm-invoices/${id}/send`, body);
  return res.data;
}

export async function markFirmInvoicePaid(id: number): Promise<FirmInvoice> {
  const res = await apiClient.post(`/api/firm-invoices/${id}/mark-paid`);
  return res.data;
}

export function getFirmInvoicePdfUrl(id: number): string {
  return `${import.meta.env.VITE_API_BASE_URL}/api/firm-invoices/${id}/pdf`;
}
