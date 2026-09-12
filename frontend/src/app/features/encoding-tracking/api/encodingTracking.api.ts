import { apiClient } from "../../../api/apiClient";

/**
 * Suivi des encodages (D-118) — client pour GET /api/billing/encoding-tracking et
 * .../summary. Toutes les formes ci-dessous reproduisent EXACTEMENT le contrat JSON
 * backend (voir docs/api.md) — aucun champ n'est renommé, recalculé ou dérivé ici.
 */

/**
 * État dérivé — définition canonique unique côté backend (EncodingStateResolver, D-118).
 * Le frontend ne fait jamais que mapper ces 7 valeurs vers un libellé/une couleur
 * (voir encodingStateMeta.ts) : il ne redéfinit jamais leur logique.
 */
export type EncodingState =
  | "UPCOMING"
  | "TO_ENCODE"
  | "IN_PROGRESS"
  | "SUBMITTED"
  | "VALIDATED"
  | "LOCKED"
  | "NOT_APPLICABLE";

export type EncodingFinancialState =
  | "NOT_CALCULABLE"
  | "TO_CALCULATE"
  | "CALCULATED"
  | "DOCUMENTED"
  | "PAID"
  | "ANOMALY";

export type EffectiveDurationSource = "PLANNED" | "ACTUAL_TIMES" | "ACTUAL_EXPLICIT";

export type MissionType = "BLOCK" | "CONSULTATION";

export interface EncodingTrackingFilter {
  from?: string;
  to?: string;
  siteId?: number;
  surgeonId?: number;
  instrumentistId?: number;
  firmId?: number;
  interventionTypeId?: number;
  missionType?: MissionType;
  /** Liste vide = tous les états — sérialisée en liste séparée par des virgules. */
  encodingState?: EncodingState[];
}

export interface EncodingTrackingSummary {
  totalMissions: number;
  encodingExpected: number;
  upcoming: number;
  toEncode: number;
  inProgress: number;
  submitted: number;
  validated: number;
  locked: number;
  notApplicable: number;
  staleInProgress: number;
  financialAnomalies: number;
  encoded: number;
  missingEncoding: number;
  toTreat: number;
  hasFinanciallyEligibleMissions: boolean;
}

export interface EncodingTrackingPerson {
  id: number;
  name: string | null;
}

export interface EncodingTrackingHours {
  plannedMinutes: number;
  effectiveMinutes: number;
  effectiveSource: EffectiveDurationSource;
  hasRealHours: boolean;
}

export interface EncodingTrackingEncoding {
  interventionCount: number;
  materialLineCount: number;
  submittedWithoutMaterial: boolean;
  hasNoMaterialJustification: boolean;
  isStale: boolean;
}

export interface EncodingTrackingFinancial {
  state: EncodingFinancialState;
  label: string;
  isBlocking: boolean;
}

export interface EncodingTrackingItem {
  missionId: number;
  startAt: string | null;
  endAt: string | null;
  missionType: MissionType | null;
  missionStatus: string;
  encodingState: EncodingState;
  encodingStateLabel: string;
  instrumentist: EncodingTrackingPerson | null;
  surgeon: EncodingTrackingPerson | null;
  site: EncodingTrackingPerson | null;
  hours: EncodingTrackingHours;
  encoding: EncodingTrackingEncoding;
  financial: EncodingTrackingFinancial;
}

export interface EncodingTrackingPeriod {
  from: string;
  to: string;
}

export interface EncodingTrackingResponse {
  period: EncodingTrackingPeriod;
  summary: EncodingTrackingSummary;
  items: EncodingTrackingItem[];
  total: number;
  page: number;
  limit: number;
}

export interface EncodingTrackingSummaryResponse {
  period: EncodingTrackingPeriod;
  summary: EncodingTrackingSummary;
}

function toParams(filter: EncodingTrackingFilter, extra?: Record<string, string | number | undefined>) {
  return {
    from: filter.from,
    to: filter.to,
    siteId: filter.siteId,
    surgeonId: filter.surgeonId,
    instrumentistId: filter.instrumentistId,
    firmId: filter.firmId,
    interventionTypeId: filter.interventionTypeId,
    missionType: filter.missionType,
    encodingState: filter.encodingState && filter.encodingState.length > 0 ? filter.encodingState.join(",") : undefined,
    ...extra,
  };
}

export async function getEncodingTracking(
  filter: EncodingTrackingFilter,
  pagination: { page: number; limit: number },
): Promise<EncodingTrackingResponse> {
  const res = await apiClient.get("/api/billing/encoding-tracking", {
    params: toParams(filter, { page: pagination.page, limit: pagination.limit }),
  });
  return res.data;
}

export async function getEncodingTrackingSummary(filter: EncodingTrackingFilter): Promise<EncodingTrackingSummaryResponse> {
  const res = await apiClient.get("/api/billing/encoding-tracking/summary", { params: toParams(filter) });
  return res.data;
}
