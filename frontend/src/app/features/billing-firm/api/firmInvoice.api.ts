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

export interface PreviewLine {
  missionId: number;
  missionDate: string;
  interventionId: number | null;
  materialLineId: number | null;
  lineType: "INTERVENTION_FEE" | "MATERIAL_FEE";
  descriptionSnapshot: string;
  firmNameSnapshot: string;
  unitPrice: number;
  quantity: number;
  totalAmount: number;
}

export interface FirmInvoicePreview {
  firm: { id: number; name: string };
  period: { start: string; end: string };
  lines: PreviewLine[];
  totalAmount: number;
}

export async function getFirmInvoices(params?: {
  firmId?: number;
  status?: InvoiceStatus;
  year?: number;
}): Promise<FirmInvoice[]> {
  const res = await apiClient.get("/api/firm-invoices", { params });
  return res.data;
}

export async function previewFirmInvoice(body: {
  firmId: number;
  periodStart: string;
  periodEnd: string;
}): Promise<FirmInvoicePreview> {
  const res = await apiClient.post("/api/firm-invoices/preview", body);
  return res.data;
}

export async function generateFirmInvoice(body: {
  firmId: number;
  periodStart: string;
  periodEnd: string;
  selectedInterventionIds: number[];
  selectedMaterialLineIds: number[];
}): Promise<FirmInvoice> {
  const res = await apiClient.post("/api/firm-invoices", body);
  return res.data;
}

// ── EPIC Exécution & Valorisation, Lot 4 (D-074) — nouveau flux, sourcé sur
// FinancialCalculationLine (calcul verrouillé) plutôt que recalculé à la volée. ────

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
