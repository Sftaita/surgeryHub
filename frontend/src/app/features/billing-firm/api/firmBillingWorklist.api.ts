import { apiClient } from "../../../api/apiClient";

/**
 * D-133 — worklist « Facturation firmes » (GET /api/firm-billing/worklist). Le backend part
 * de l'activité validée et fournit, pour chaque intervention / matériel, son état de
 * facturation, son motif et son montant. Ce frontend AFFICHE ces valeurs : il ne décide
 * jamais si un élément est facturable, ne recalcule aucun montant ni aucune tuile.
 */

export type BillingStatus = "BILLABLE" | "NOT_BILLABLE" | "TO_REVIEW" | "INVOICED";
export type SourceType = "INTERVENTION" | "MATERIAL";
export type InvoiceState = "FREE" | "IN_DRAFT" | "GENERATED" | "SENT" | "PAID";

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
  /** D-134 — appartenance ACTUELLE à un document (l'historique se charge à part). */
  currentInvoice: { id: number; number: string | null; status: string; statusLabel: string; firmName: string | null; editable: boolean } | null;
  invoiceState: InvoiceState;
  invoiceStateLabel: string;
  /** « INTERVENTION:12 » / « MATERIAL:34 » — clé du journal et du deep-link ?focusLine=. */
  sourceKey: string | null;
  /** La ligne a déjà un passé documentaire (une ligne « Libre » peut en avoir un). */
  hasHistory: boolean;
  financialLineId: number | null;
  calculationId: number | null;
  /** Fourni par le backend : la ligne peut être placée sur une facture. */
  canInvoice: boolean;
  /** D-135 — la ligne est dans un brouillon : elle ne s'ajoute nulle part ailleurs, elle se déplace. */
  canMoveToDraft: boolean;
}

export type AnomalyActionCode =
  | "CONFIGURE_INTERVENTION_RATE"
  | "CONFIGURE_MATERIAL_RATE"
  | "CONFIGURE_INSTRUMENTIST_RATE"
  | "OPEN_MISSION"
  | "CALCULATE"
  | "APPROVE"
  | "RECALCULATE"
  | "OPEN_DRAFT";

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
  /** D-141 — issue actuelle de l'élément selon le moteur (null pour une anomalie de workflow). */
  currentResolution: { kind: string | null; label: string | null } | null;
  referenceDate: string;
  /** D-141 — anomalie qu'un recalcul produirait, absente de l'échec audité. */
  detectedAfterFailure: boolean;
  calculationId: number | null;
  rowKey: string | null;
  calculationLocked: boolean;
  /** OPEN_DRAFT : brouillon à ouvrir et ligne à y cibler. */
  invoiceId?: number;
  focusLine?: string;
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
  /** D-141 — anomalies qui bloquent encore (hors `resolved`). */
  anomalyCount: number;
  /** D-141 — anomalies du dernier échec déjà corrigées, en attente de recalcul. */
  resolvedAnomalyCount: number;
  pendingValidationMissionCount: number;
  invoices: { draft: number; generated: number; sent: number; paid: number; cancelled: number; abandoned: number };
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

// ── D-134 — historique append-only d'une ligne ─────────────────────────────

export interface LineHistoryEvent {
  id: number;
  eventType: string;
  label: string;
  description: string;
  occurredAt: string;
  actorName: string | null;
  firmName: string | null;
  invoice: { id: number; number: string | null; statusAtEvent: string | null } | null;
  amount: string | null;
  currency: string | null;
}

export interface LineHistory {
  sourceKey: string;
  sourceType: SourceType;
  sourceId: number;
  label: string | null;
  currentInvoice: { id: number; number: string | null; status: string; firmName: string | null; editable: boolean } | null;
  history: LineHistoryEvent[];
}

export async function getFirmBillingLineHistory(sourceKey: string): Promise<LineHistory> {
  const [type, id] = sourceKey.split(":");
  const res = await apiClient.get(`/api/firm-billing/lines/${type}/${id}/history`);
  return res.data;
}

/** Lien direct vers le document qui contient la ligne, avec la ligne ciblée (survit au refresh). */
export function invoiceFocusUrl(invoiceId: number, sourceKey: string | null): string {
  return `/app/m/billing/firm-invoices/${invoiceId}${sourceKey ? `?focusLine=${encodeURIComponent(sourceKey)}` : ""}`;
}
