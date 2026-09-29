import { apiClient } from "../../../api/apiClient";

/**
 * D-123 — cockpit « Facturation firmes » (GET /api/firm-invoices/cockpit). Tout est classé
 * par le backend à partir des FinancialCalculationLine : ce frontend n'affiche que ce qui
 * est fourni (catégories, raisons, montants, actions autorisées), il ne recalcule rien.
 */

export interface CockpitMission {
  id: number;
  date: string | null;
  status: string;
  site: string | null;
  surgeon: string | null;
}

export interface CockpitLine {
  id: number;
  calculationId: number;
  calculationStatus: string;
  mission: CockpitMission;
  firm: { id: number | null; name: string | null };
  lineType: "FIRM_INTERVENTION_FEE" | "FIRM_MATERIAL_FEE";
  lineTypeLabel: string;
  description: string;
  intervention: { id: number; label: string | null } | null;
  material: { id: number; label: string | null; referenceCode: string | null } | null;
  quantity: string;
  unitAmount: string;
  totalAmount: string;
  currency: string;
}

export interface ToInvoiceLine extends CockpitLine {
  calculationLocked: boolean;
  missionPartiallyInvoiced: boolean;
  /** Message backend quand le calcul est verrouillé par une facture déjà émise. */
  notice: string | null;
}

export interface ToInvoiceGroup {
  firm: { id: number; name: string };
  currency: string;
  lineCount: number;
  totalAmount: string;
  lines: ToInvoiceLine[];
}

export type ToVerifyReason =
  | "ENCODING_NOT_VALIDATED"
  | "CALCULATION_REQUIRED"
  | "CALCULATION_FAILED"
  | "CALCULATION_PENDING_APPROVAL";

export interface ToVerifyItem {
  reason: ToVerifyReason;
  /** Libellé métier fourni par le backend — jamais reformulé côté frontend. */
  reasonLabel: string;
  mission: CockpitMission;
  firms: { id: number; name: string }[];
  calculationId: number | null;
  anomalies: { code: string; message: string }[];
  lines: CockpitLine[];
  totalAmount: string | null;
  currency: string | null;
  allowedActions: ("calculate" | "approve")[];
}

export interface InvoicedLine extends CockpitLine {
  invoice: { id: number; number: string | null; status: string; generatedAt: string | null; invoicedAmount: string };
}

export interface FirmBillingCockpit {
  period: { from: string; to: string };
  kpis: {
    toInvoiceLineCount: number;
    toInvoiceAmounts: { currency: string; amount: string }[];
    toVerifyCount: number;
    invoicedLineCount: number;
    invoices: { generated: number; sent: number; paid: number; cancelled: number };
  };
  toInvoice: ToInvoiceGroup[];
  toVerify: ToVerifyItem[];
  invoiced: InvoicedLine[];
}

export async function getFirmBillingCockpit(params: { from: string; to: string; firmId?: number }): Promise<FirmBillingCockpit> {
  const res = await apiClient.get("/api/firm-invoices/cockpit", { params });
  return res.data;
}

/** Message métier du backend ({error: {message, violations}}), jamais reformulé. */
export function extractBillingError(err: unknown): string {
  const e = err as any;
  const data = e?.response?.data?.error;
  const violations = data?.violations;
  if (Array.isArray(violations) && violations.length > 0) {
    const details = violations.map((v: any) => v?.message).filter(Boolean).join(" · ");
    return data?.message ? `${data.message} ${details}` : details;
  }
  return data?.message ?? e?.message ?? String(err);
}
