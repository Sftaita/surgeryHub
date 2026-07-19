import { apiClient } from "../../../api/apiClient";

/**
 * EPIC Exécution & Valorisation (Lots 4-6) — client générique pour les deux familles
 * de documents financiers (FirmInvoice / InstrumentistStatement), qui partagent
 * exactement la même forme d'endpoints côté backend. `resource` sélectionne le préfixe
 * de route ; `correctionResource` sélectionne le contrôleur de correction dédié
 * (/api/firm-invoice-corrections, /api/instrumentist-statement-corrections) car une
 * correction partage l'id de son document racine et n'est donc pas adressable sous le
 * préfixe du document lui-même.
 */
export type DocumentResource = "firm-invoices" | "instrumentist-statements";

export type DocumentType = "STANDARD" | "CREDIT_NOTE" | "DEBIT_NOTE";
export type PaymentDirection = "INBOUND" | "OUTBOUND";
export type PaymentMethod = "BANK_TRANSFER" | "CASH" | "OTHER";
export type PaymentStatus = "UNPAID" | "PARTIALLY_PAID" | "PAID";

export type CorrectionReasonCode =
  | "WRONG_QUANTITY"
  | "WRONG_RATE"
  | "WRONG_DURATION"
  | "DUPLICATE_LINE"
  | "OMITTED_LINE"
  | "WRONG_BENEFICIARY"
  | "COMMERCIAL_ADJUSTMENT"
  | "OTHER";

export const CORRECTION_REASON_LABELS: Record<CorrectionReasonCode, string> = {
  WRONG_QUANTITY: "Quantité erronée",
  WRONG_RATE: "Tarif erroné",
  WRONG_DURATION: "Durée erronée",
  DUPLICATE_LINE: "Ligne en double",
  OMITTED_LINE: "Ligne oubliée",
  WRONG_BENEFICIARY: "Bénéficiaire erroné",
  COMMERCIAL_ADJUSTMENT: "Ajustement commercial",
  OTHER: "Autre",
};

export interface Payment {
  id: number;
  documentType: string;
  documentId: number;
  direction: PaymentDirection;
  amount: string;
  currency: string;
  paidAt: string;
  recordedAt: string | null;
  recordedBy: number | null;
  reference: string | null;
  method: PaymentMethod;
  comment: string | null;
  createdAt: string | null;
}

/** Résumé d'une correction telle qu'imbriquée dans le document racine (`corrections[]`). */
export interface CorrectionSummary {
  id: number;
  documentType: DocumentType;
  status: "DRAFT" | "GENERATED" | "SENT" | "PAID" | "CANCELLED";
  number: string | null;
  totalAmount: string;
}

/** Détail complet d'une correction (GET .../{id} ou POST .../issue sur le contrôleur dédié). */
export interface CorrectionDetail {
  id: number;
  number: string | null;
  documentType: DocumentType;
  status: string;
  currency: string;
  totalAmount: string;
  correctsDocument: { id: number | null; number: string | null };
  firm?: { id: number | null; name: string | null };
  instrumentist?: { id: number | null };
  generatedAt: string | null;
  sentAt: string | null;
  createdAt: string | null;
  lines: {
    id: number;
    reasonCode: CorrectionReasonCode | null;
    originalDocumentLineId: number | null;
    descriptionSnapshot: string;
    quantity: string;
    unitPrice?: string;
    rateSnapshot?: string;
    totalAmount: string;
    currency: string;
  }[];
}

export interface CorrectionLineInput {
  originalDocumentLineId?: number | null;
  reasonCode: CorrectionReasonCode;
  description: string;
  quantity: string;
  unitAmount: string;
  comment?: string | null;
  missionId?: number | null;
  financialCalculationLineId?: number | null;
}

function base(resource: DocumentResource, id: number): string {
  return `/api/${resource}/${id}`;
}

function correctionsControllerBase(resource: DocumentResource, id: number): string {
  const prefix = resource === "firm-invoices" ? "firm-invoice-corrections" : "instrumentist-statement-corrections";
  return `/api/${prefix}/${id}`;
}

// ── Émission (Lot 5) ─────────────────────────────────────────────────────

export async function issueDocument(resource: DocumentResource, id: number): Promise<unknown> {
  const res = await apiClient.post(`${base(resource, id)}/issue`);
  return res.data;
}

// ── Paiements / remboursements (Lot 5-6) ────────────────────────────────

export async function listPayments(resource: DocumentResource, id: number): Promise<Payment[]> {
  const res = await apiClient.get(`${base(resource, id)}/payments`);
  return res.data;
}

export async function recordPayment(
  resource: DocumentResource,
  id: number,
  body: { amount: string; currency: string; paidAt: string; method: PaymentMethod; reference?: string; comment?: string }
): Promise<Payment> {
  const res = await apiClient.post(`${base(resource, id)}/payments`, body);
  return res.data;
}

export async function recordRefund(
  resource: DocumentResource,
  id: number,
  body: { amount: string; currency: string; paidAt: string; method: PaymentMethod; reference?: string; comment?: string }
): Promise<Payment> {
  const res = await apiClient.post(`${base(resource, id)}/refunds`, body);
  return res.data;
}

// ── Corrections (Lot 6) ──────────────────────────────────────────────────

export async function listCorrections(resource: DocumentResource, rootId: number): Promise<CorrectionDetail[]> {
  const res = await apiClient.get(`${base(resource, rootId)}/corrections`);
  return res.data;
}

export async function createCreditNote(
  resource: DocumentResource,
  rootId: number,
  lines: CorrectionLineInput[],
  comment?: string
): Promise<CorrectionDetail> {
  const res = await apiClient.post(`${base(resource, rootId)}/credit-notes`, { lines, comment });
  return res.data;
}

export async function createDebitNote(
  resource: DocumentResource,
  rootId: number,
  lines: CorrectionLineInput[],
  comment?: string
): Promise<CorrectionDetail> {
  const res = await apiClient.post(`${base(resource, rootId)}/debit-notes`, { lines, comment });
  return res.data;
}

export async function getCorrection(resource: DocumentResource, correctionId: number): Promise<CorrectionDetail> {
  const res = await apiClient.get(correctionsControllerBase(resource, correctionId));
  return res.data;
}

export async function issueCorrection(resource: DocumentResource, correctionId: number): Promise<CorrectionDetail> {
  const res = await apiClient.post(`${correctionsControllerBase(resource, correctionId)}/issue`);
  return res.data;
}

export function getCorrectionPdfUrl(resource: DocumentResource, correctionId: number): string {
  return `${import.meta.env.VITE_API_BASE_URL}/api/${resource}/${correctionId}/pdf`;
}
