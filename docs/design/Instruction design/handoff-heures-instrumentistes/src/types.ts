// Heures instrumentistes (manager) — contrat API PROPOSÉ, à valider puis inscrire dans docs/api.md.
// Le serveur calcule tout (périmètre, durées, montants, totaux, filtres, pourcentages, tri) ;
// le front n'affiche que ces valeurs (CLAUDE.md § 0).

export type HoursStatus = "pending" | "validated";
export type RateType = "HOURLY_RATE" | "CONSULTATION_FEE";
/** RATED : au moins une prestation valorisée · UNRATED : prestations, aucune valorisable (« Tarif manquant ») · NO_PRESTATION : aucune prestation (« — »). */
export type EstimateState = "RATED" | "UNRATED" | "NO_PRESTATION";
/** ZERO_REFERENCE : 0 h validée sur la référence · NO_REFERENCE : référence antérieure à l'historique observable (CLAUDE.md § 8). */
export type ComparisonUnavailableReason = "ZERO_REFERENCE" | "NO_REFERENCE";

export interface HoursComparison {
  /** Heures validées de la référence (même site ; à date équivalente si provisoire). */
  validatedMinutes: number;
  /** Arrondi à l'entier ; null quand available = false (affiché « — », jamais un pourcentage artificiel). */
  pct: number | null;
  available: boolean;
  reason: ComparisonUnavailableReason | null;
}

export interface PersonalAverageComparison extends HoursComparison {
  /** Mois réellement comptés (≤ 6) ; < 6 → affiché « sur N mois » (CLAUDE.md § 8). */
  monthsCounted: number;
  /** YYYY-MM, null si monthsCounted = 0. */
  from: string | null;
  to: string | null;
  /**
   * YYYY-MM du mois de la première prestation CONNUE (périmètre D-179, tous sites), null si aucune.
   * Jamais une date d'embauche ou d'activation (CLAUDE.md § 8).
   */
  observableSince: string | null;
}

export interface InstrumentistRef {
  id: number;
  firstname: string;
  lastname: string;
  /** false → étiquette « Inactive » (présente uniquement si elle a presté dans le périmètre). */
  active: boolean;
}

export interface InstrumentistHoursEstimate {
  currency: string;
  /** Décimaux en chaîne ("3419.00"). validatedAmount null = aucune prestation validée valorisée (« — »). */
  encodedAmount: string;
  validatedAmount: string | null;
  consultationFeeCount: number;
  /** Prestations non valorisables (tarif personnel absent) — « + n sans tarif ». */
  unratedCount: number;
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
  /** null sauf estimateState = RATED. */
  estimate: InstrumentistHoursEstimate | null;
  /** validatedMinutes / max(validatedMinutes) du périmètre, 0–1 — largeur de la barre du Top 5. */
  ratio: number;
  comparison: {
    previousMonth: HoursComparison;
    personalAverage: PersonalAverageComparison;
  };
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
  // Décision 5 : aucune évolution globale d'équipe.
}

/** GET /api/manager/instrumentist-hours?month=YYYY-MM[&siteId=][&q=][&sort=][&direction=][&limit=] */
export interface InstrumentistHoursList {
  month: string;
  provisional: boolean;
  /** Jour J de la comparaison à date équivalente (mois en cours), sinon null. */
  asOfDay: number | null;
  siteId: number | null;
  q: string | null;
  sort: string;
  direction: "asc" | "desc";
  /** Sites du mois ENTIER (options du Select), indépendants du filtre. */
  sites: { id: number; name: string }[];
  reference: { previousMonth: string };
  /** Périmètre filtré (site + q), jamais limité par `limit`. */
  totals: InstrumentistHoursTotals;
  items: InstrumentistHoursRow[];
}

/** Ligne de prestation — identique à MyHoursItem (frontend/src/app/features/my-hours/api/myHours.types.ts). */
export interface HoursItem {
  missionId: number;
  date: string;
  start: string | null;
  end: string | null;
  endsNextDay: boolean;
  minutes: number | null;
  durationSource: "ACTUAL_TIMES" | "ACTUAL_EXPLICIT" | "PRESENCE_SHARE" | null;
  missionType: "BLOCK" | "CONSULTATION" | null;
  status: HoursStatus;
  surgeonName: string | null;
  site: { id: number; name: string };
  estimate: { rateType: RateType; unitAmount: string; amount: string; currency: string } | null;
}

/** Ligne de tarif — identique à MyHoursEstimateLine (groupée par site × type × tarif). */
export interface HoursEstimateLine {
  siteName: string;
  rateType: RateType;
  unitAmount: string;
  minutes: number | null;
  count: number;
  amount: string;
}

/** GET /api/manager/instrumentists/{id}/hours?month=YYYY-MM[&siteId=] — MyHoursStatement + contexte manager. */
export interface InstrumentistHoursDetail {
  month: string;
  provisional: boolean;
  asOfDay: number | null;
  siteId: number | null;
  instrumentist: InstrumentistRef;
  sites: { id: number; name: string; count: number }[];
  summary: {
    count: number;
    encodedMinutes: number;
    validatedMinutes: number;
    pendingMinutes: number;
    validatedPct: number;
    missingHoursCount: number;
  };
  estimate: {
    currency: string;
    encodedAmount: string;
    validatedAmount: string;
    hourlyRateMin: string | null;
    hourlyRateMax: string | null;
    unratedCount: number;
    lines: HoursEstimateLine[];
  } | null;
  comparison: InstrumentistHoursRow["comparison"];
  items: HoursItem[];
}
