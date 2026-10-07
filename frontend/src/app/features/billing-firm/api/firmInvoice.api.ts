import { apiClient } from "../../../api/apiClient";
import type { CorrectionSummary, DocumentType, PaymentStatus } from "../../billing-shared/api/documentFinance.api";

/** D-137 — ABANDONED = brouillon abandonné (jamais une facture), distinct de CANCELLED (facture annulée). */
export type InvoiceStatus = "DRAFT" | "GENERATED" | "SENT" | "PAID" | "CANCELLED" | "ABANDONED";

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
  /** D-123 — nombre de lignes snapshotées. */
  lineCount?: number;
  /** D-123 — actions permises par le backend (GENERATED → send/cancel, SENT → markPaid) :
   *  un bouton n'est affiché que s'il figure ici. */
  allowedActions?: ("send" | "cancel" | "markPaid" | "editLines" | "generate" | "abandon")[];
}

export interface FirmInvoiceLine {
  id: number;
  missionId: number;
  missionDate: string;
  interventionId: number | null;
  materialLineId: number | null;
  /** D-134 — « INTERVENTION:12 » / « MATERIAL:34 », clé partagée avec la worklist (deep-link ?focusLine=). */
  sourceKey?: string | null;
  /** D-135 — ligne de brouillon dont le calcul a changé depuis l'ajout (génération refusée). */
  stale?: boolean;
  lineType: "INTERVENTION_FEE" | "MATERIAL_FEE";
  descriptionSnapshot: string;
  firmNameSnapshot: string;
  unitPrice: string;
  quantity: string;
  totalAmount: string;
  currency?: string;
  financialCalculationLineId?: number | null;
  /** D-123 — contexte (aucune donnée patient) et lien vers la source métier. */
  financialCalculationId?: number | null;
  siteName?: string | null;
  surgeonName?: string | null;
  interventionLabel?: string | null;
  materialLabel?: string | null;
  materialReferenceCode?: string | null;
  legacy?: boolean;
  reasonCode?: string | null;
  originalDocumentLineId?: number | null;
}

export async function getFirmInvoices(params?: {
  firmId?: number;
  /** D-133 — plusieurs firmes (OU). */
  firmIds?: number[];
  /** D-137 — les brouillons abandonnés sont masqués par défaut. */
  includeAbandoned?: boolean;
  status?: InvoiceStatus;
  year?: number;
  /** D-123 — période (dates AAAA-MM-JJ inclusives, sur le début de période de la facture). */
  from?: string;
  to?: string;
  documentType?: "STANDARD";
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

// ── D-135 — brouillon de facture (endpoints de transition dédiés) ──────────

export async function createFirmInvoiceDraft(body: {
  firmId: number;
  currency: string;
  periodStart: string;
  periodEnd: string;
  financialLineIds: number[];
}): Promise<FirmInvoice> {
  const res = await apiClient.post("/api/firm-invoices/drafts", body);
  return res.data;
}

export async function addLinesToFirmInvoiceDraft(draftId: number, financialLineIds: number[]): Promise<FirmInvoice> {
  const res = await apiClient.post(`/api/firm-invoices/${draftId}/lines`, { financialLineIds });
  return res.data;
}

export async function moveLinesToFirmInvoiceDraft(draftId: number, financialLineIds: number[]): Promise<FirmInvoice> {
  const res = await apiClient.post(`/api/firm-invoices/${draftId}/lines/move`, { financialLineIds });
  return res.data;
}

export async function removeFirmInvoiceDraftLine(draftId: number, invoiceLineId: number): Promise<FirmInvoice> {
  const res = await apiClient.delete(`/api/firm-invoices/${draftId}/lines/${invoiceLineId}`);
  return res.data;
}

export async function generateFirmInvoiceDraft(draftId: number): Promise<FirmInvoice> {
  const res = await apiClient.post(`/api/firm-invoices/${draftId}/generate`);
  return res.data;
}

export async function abandonFirmInvoiceDraft(draftId: number, reason?: string): Promise<FirmInvoice> {
  const res = await apiClient.post(`/api/firm-invoices/${draftId}/abandon`, { reason });
  return res.data;
}

/** D-137 — prestations ajoutables à ce brouillon (règles et filtre : backend uniquement). */
export async function getFirmInvoiceDraftCandidates(draftId: number): Promise<import("./firmBillingWorklist.api").WorklistRow[]> {
  const res = await apiClient.get(`/api/firm-invoices/${draftId}/candidate-lines`);
  return res.data.rows;
}
