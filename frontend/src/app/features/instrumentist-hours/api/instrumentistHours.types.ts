// D-180 — suivi manager des heures instrumentistes (docs/api.md § 51).
// Le serveur calcule tout (périmètre, durées, montants, totaux, filtres, pourcentages, tri) ;
// le front n'affiche que ces valeurs.
import type { MyHoursEstimate, MyHoursItem, MyHoursSite, MyHoursSummary } from "../../my-hours/api/myHours.types";

export type ComparisonReason = "ZERO_REFERENCE" | "NO_REFERENCE";
export type EstimateState = "RATED" | "UNRATED" | "NO_PRESTATION";
export type HoursSort = "name" | "encodedMinutes" | "validatedMinutes" | "encodedAmount" | "validatedAmount" | "previousMonthPct" | "personalAveragePct";
export type SortDirection = "asc" | "desc";

export interface HoursComparison {
  validatedMinutes: number;
  /** Arrondi par le serveur ; null = indisponible (« — »). */
  pct: number | null;
  available: boolean;
  reason: ComparisonReason | null;
}

export interface PersonalAverageComparison extends HoursComparison {
  /** < 6 = période raccourcie (« sur N mois »). */
  monthsCounted: number;
  from: string | null;
  to: string | null;
  /** Mois de la première prestation CONNUE — jamais une date d'entrée en activité. */
  observableSince: string | null;
}

export interface InstrumentistComparison {
  previousMonth: HoursComparison;
  personalAverage: PersonalAverageComparison;
}

export interface InstrumentistRef {
  id: number;
  firstname: string | null;
  lastname: string | null;
  active: boolean;
}

export interface InstrumentistHoursRow {
  instrumentist: InstrumentistRef;
  count: number;
  pendingCount: number;
  missingHoursCount: number;
  encodedMinutes: number;
  validatedMinutes: number;
  pendingMinutes: number;
  estimateState: EstimateState;
  estimate: {
    currency: string;
    encodedAmount: string;
    /** null = aucune prestation validée valorisée. */
    validatedAmount: string | null;
    consultationFeeCount: number;
    unratedCount: number;
  } | null;
  /** Largeur de la barre du Top 5 (0–1). */
  ratio: number;
  comparison: InstrumentistComparison;
}

export interface InstrumentistHoursTotals {
  instrumentistCount: number;
  count: number;
  pendingCount: number;
  encodedMinutes: number;
  validatedMinutes: number;
  pendingMinutes: number;
  validatedPct: number;
  currency: string | null;
  encodedAmount: string | null;
  validatedAmount: string | null;
  unratedInstrumentistCount: number;
  unratedCount: number;
}

export interface InstrumentistHoursList {
  month: string;
  provisional: boolean;
  asOfDay: number | null;
  siteId: number | null;
  q: string | null;
  sort: HoursSort;
  direction: SortDirection;
  sites: { id: number; name: string }[];
  reference: { previousMonth: string };
  totals: InstrumentistHoursTotals;
  items: InstrumentistHoursRow[];
}

export interface InstrumentistHoursQuery {
  month: string;
  siteId?: number | null;
  q?: string | null;
  sort?: HoursSort;
  direction?: SortDirection;
  limit?: number;
}

/** Relevé « Mes heures » de l'instrumentiste + contexte manager. */
export interface InstrumentistHoursDetail {
  month: string;
  provisional: boolean;
  asOfDay: number | null;
  siteId: number | null;
  instrumentist: InstrumentistRef;
  sites: MyHoursSite[];
  summary: MyHoursSummary;
  estimate: MyHoursEstimate | null;
  comparison: InstrumentistComparison;
  items: MyHoursItem[];
}
