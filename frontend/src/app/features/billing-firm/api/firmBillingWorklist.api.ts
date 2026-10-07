import { apiClient } from "../../../api/apiClient";

/**
 * D-133 — worklist « Facturation firmes » (GET /api/firm-billing/worklist). Le backend part
 * de l'activité validée et fournit, pour chaque intervention / matériel, son état de
 * facturation, son motif et son montant. Ce frontend AFFICHE ces valeurs : il ne décide
 * jamais si un élément est facturable, ne recalcule aucun montant ni aucune tuile.
 */

export type BillingStatus = "BILLABLE" | "NOT_BILLABLE" | "TO_REVIEW" | "INVOICED";
export type SourceType = "INTERVENTION" | "MATERIAL";

export interface WorklistMission {
  id: number;
  date: string;
  status: string;
  site: string | null;
  surgeon: string | null;
}

export interface WorklistRow {
  /** Identifiant stable de la ligne (sélection, export). */
  key: string;
  sourceType: SourceType;
  sourceId: number | null;
  mission: WorklistMission;
  firm: { id: number; name: string } | null;
  label: string | null;
  reference: string | null;
  quantity: string | null;
  billingStatus: BillingStatus;
  billingStatusLabel: string;
  /** Code stable du catalogue FirmBillingReason. */
  reasonCode: string;
  reasonLabel: string;
  reasonDetail: string;
  amount: string | null;
  currency: string | null;
  invoice: { id: number; number: string | null; status: string; statusLabel: string } | null;
  financialLineId: number | null;
  calculationId: number | null;
  /** Fourni par le backend : la ligne peut être placée sur une facture. */
  canInvoice: boolean;
}

export type AnomalyActionCode =
  | "CONFIGURE_INTERVENTION_RATE"
  | "CONFIGURE_MATERIAL_RATE"
  | "CONFIGURE_INSTRUMENTIST_RATE"
  | "OPEN_MISSION"
  | "CALCULATE"
  | "APPROVE"
  | "RECALCULATE";

export interface WorklistAnomaly {
  key: string;
  code: string;
  title: string;
  explanation: string;
  mission: WorklistMission;
  firm: { id: number; name: string } | null;
  element: { type: string; label: string | null; reference?: string | null } | null;
  action: { code: AnomalyActionCode; label: string } | null;
  /** La cause n'existe plus dans la configuration actuelle : la mission peut être recalculée. */
  resolved: boolean;
  calculationId: number | null;
  rowKey: string | null;
  calculationLocked: boolean;
}

export interface Amount {
  currency: string;
  amount: string;
}

export interface WorklistSummary {
  lineCount: number;
  billable: { lineCount: number; amounts: Amount[] };
  notBillable: { lineCount: number };
  toReview: { lineCount: number };
  invoiced: { lineCount: number; amounts: Amount[] };
  anomalyCount: number;
  pendingValidationMissionCount: number;
  invoices: { generated: number; sent: number; paid: number; cancelled: number };
}

export interface FirmBillingWorklist {
  period: { from: string; to: string };
  summary: WorklistSummary;
  rows: WorklistRow[];
  anomalies: WorklistAnomaly[];
  bulkActions: { recalculateFixed: number[]; calculatePending: number[] };
}

export interface WorklistFilters {
  from: string;
  to: string;
  firmIds: number[];
  type?: SourceType;
  status?: BillingStatus;
}

export async function getFirmBillingWorklist(filters: WorklistFilters): Promise<FirmBillingWorklist> {
  const res = await apiClient.get("/api/firm-billing/worklist", {
    params: {
      from: filters.from,
      to: filters.to,
      firmIds: filters.firmIds.length ? filters.firmIds : undefined,
      type: filters.type,
      status: filters.status,
    },
  });
  return res.data;
}

export interface CalculationRunResult {
  results: { missionId: number; outcome: "CALCULATED" | "FAILED" | "SKIPPED" | "NOT_PROCESSED"; message: string; issues: string[]; calculationId: number | null }[];
  calculated: number;
  failed: number;
  skipped: number;
}

export async function runFirmBillingCalculations(missionIds: number[]): Promise<CalculationRunResult> {
  const res = await apiClient.post("/api/firm-billing/calculations", { missionIds }, { timeout: 120_000 });
  return res.data;
}

/** Exporte EXACTEMENT les lignes `keys` (le backend refuse une clé hors période/firmes). */
export async function exportFirmBillingSelection(params: {
  from: string;
  to: string;
  firmIds: number[];
  keys: string[];
  format: "pdf" | "xlsx";
}): Promise<{ blob: Blob; filename: string }> {
  const res = await apiClient.post("/api/firm-billing/worklist/export", params, { responseType: "blob", timeout: 60_000 });
  const disposition = String(res.headers?.["content-disposition"] ?? "");
  const match = /filename="([^"]+)"/.exec(disposition);
  return { blob: res.data as Blob, filename: match?.[1] ?? `facturation-firmes.${params.format}` };
}

/** Message métier du backend ({error: {message, violations}}), jamais reformulé. */
export async function extractBillingErrorAsync(err: unknown): Promise<string> {
  const data = (err as any)?.response?.data;
  if (data instanceof Blob) {
    try {
      const parsed = JSON.parse(await data.text());
      return extractBillingError({ response: { data: parsed } });
    } catch {
      return "Export impossible.";
    }
  }
  return extractBillingError(err);
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
