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

/** D-136 — comparaison réel / planifié calculée par le backend (jamais recalculée ici). */
export type HoursComparison = "NO_REAL_HOURS" | "WITHIN_PLAN" | "OVER_PLAN";

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
  /** Instrumentiste/chirurgien uniquement (jamais le site) — chemin brut, à résoudre via
   *  resolveApiAssetUrl(). Absent/null = pas de photo, repli sur les initiales. */
  photoPath?: string | null;
}

export interface EncodingTrackingHours {
  plannedMinutes: number;
  effectiveMinutes: number;
  effectiveSource: EffectiveDurationSource;
  hasRealHours: boolean;
  comparison: HoursComparison;
}

export interface EncodingTrackingEncoding {
  interventionCount: number;
  /** Même définition que `progress.encodedInterventionCount` de GET .../encoding. */
  encodedInterventionCount: number;
  materialLineCount: number;
  submittedWithoutMaterial: boolean;
  hasNoMaterialJustification: boolean;
  isStale: boolean;
}

/** D-138 — un motif d'anomalie et son nombre d'occurrences (ventilation backend). */
export interface FinancialAnomalyReason {
  code: string;
  label: string;
  count: number;
}

export interface EncodingTrackingFinancial {
  state: EncodingFinancialState;
  label: string;
  isBlocking: boolean;
  /** D-138 — 0 hors ANOMALY. */
  anomalyCount: number;
  /** D-138 — vide hors ANOMALY ; le plus fréquent d'abord. */
  anomalyReasons: FinancialAnomalyReason[];
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

/**
 * D-121 — badge de navigation "Suivi des encodages" : nombre exact de missions en
 * attente de validation manager, sans filtre de période (distinct de `summary.submitted`
 * ci-dessus, qui est filtré par période).
 */
export async function getPendingEncodingValidationCount(): Promise<number> {
  const res = await apiClient.get("/api/billing/encoding-tracking/pending-validation-count");
  return res.data.count;
}

/**
 * D-138 — une anomalie du dernier calcul financier échoué, expliquée et localisée par le
 * backend (FinancialCalculationAnomalyExplainer). Le frontend l'affiche telle quelle.
 */
export type FinancialAnomalyCategory = "CONFIGURATION" | "ENCODING" | "TECHNICAL";

export type FinancialAnomalyActionCode =
  | "CONFIGURE_INTERVENTION_RATE"
  | "CONFIGURE_MATERIAL_RATE"
  | "CONFIGURE_INSTRUMENTIST_RATE"
  | "OPEN_MISSION";

export interface FinancialAnomaly {
  code: string;
  category: FinancialAnomalyCategory;
  severity: "BLOCKING";
  title: string;
  explanation: string;
  firm: { id: number; name: string } | null;
  element: { type: "INTERVENTION" | "MATERIAL" | "INSTRUMENTIST"; label: string | null; reference?: string | null } | null;
  action: { code: FinancialAnomalyActionCode; label: string } | null;
  /** La cause n'existe plus dans la configuration actuelle : un nouveau calcul peut aboutir. */
  resolved: boolean;
  /** Intervention concernée — ou celle qui porte la ligne de matériel concernée. */
  missionInterventionId: number | null;
  materialLineId: number | null;
}

/** Relance proposée par le backend (null = aucune relance possible ici). */
export type FinancialRetryAction =
  | { kind: "CALCULATE"; calculationId: null }
  | { kind: "RECALCULATE"; calculationId: number };

export interface MissionFinancialAnomalies {
  missionId: number;
  state: EncodingFinancialState;
  label: string;
  isBlocking: boolean;
  failedAt: string | null;
  effectiveAt: string | null;
  anomalies: FinancialAnomaly[];
  retry: FinancialRetryAction | null;
}

/** D-138 — détail FINANCE d'une mission pour le tiroir du suivi (même état que la liste). */
export async function getMissionFinancialAnomalies(missionId: number): Promise<MissionFinancialAnomalies> {
  const res = await apiClient.get(`/api/billing/encoding-tracking/missions/${missionId}/financial-anomalies`);
  return res.data;
}
