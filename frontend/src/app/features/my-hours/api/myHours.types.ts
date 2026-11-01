// D-179 — GET /api/me/hours : relevé mensuel personnel de l'instrumentiste.
// Le serveur calcule tout (durées, totaux, montants, filtre par site) ; le front n'affiche.

export type MyHoursStatus = "pending" | "validated";
export type MyHoursRateType = "HOURLY_RATE" | "CONSULTATION_FEE";

export interface MyHoursItemEstimate {
  rateType: MyHoursRateType;
  /** Tarif horaire (HOURLY_RATE) ou forfait (CONSULTATION_FEE), décimal en chaîne ("42.00"). */
  unitAmount: string;
  amount: string;
  currency: string;
}

export interface MyHoursItem {
  missionId: number;
  /** Jour de la prestation (YYYY-MM-DD, heure de Bruxelles). */
  date: string;
  /** Heures RÉELLEMENT encodées (ISO, heure murale de Bruxelles), null si durée déclarée seule ou absente. */
  start: string | null;
  end: string | null;
  endsNextDay: boolean;
  /** Durée = fin − début (ou durée déclarée seule) ; null = aucune heure réelle (jamais le planifié). */
  minutes: number | null;
  durationSource: "ACTUAL_TIMES" | "ACTUAL_EXPLICIT" | null;
  missionType: "BLOCK" | "CONSULTATION" | null;
  status: MyHoursStatus;
  surgeonName: string | null;
  site: { id: number; name: string };
  /** Absent si l'instrumentiste n'a pas accès à l'estimation ; null = non valorisée. */
  estimate?: MyHoursItemEstimate | null;
}

export interface MyHoursSite {
  id: number;
  name: string;
  count: number;
}

export interface MyHoursSummary {
  count: number;
  encodedMinutes: number;
  validatedMinutes: number;
  pendingMinutes: number;
  validatedPct: number;
  missingHoursCount: number;
}

export interface MyHoursEstimateLine {
  siteName: string;
  rateType: MyHoursRateType;
  unitAmount: string;
  /** Minutes valorisées (tarif horaire) ; null pour un forfait consultation. */
  minutes: number | null;
  count: number;
  amount: string;
}

export interface MyHoursEstimate {
  currency: string;
  encodedAmount: string;
  validatedAmount: string;
  hourlyRateMin: string | null;
  hourlyRateMax: string | null;
  unratedCount: number;
  lines: MyHoursEstimateLine[];
}

export interface MyHoursStatement {
  month: string;
  siteId: number | null;
  /** Sites du mois ENTIER (puces de filtre), indépendants du filtre courant. */
  sites: MyHoursSite[];
  summary: MyHoursSummary;
  /** null = aucune section financière (pas de tarif applicable, ou pas d'accès). */
  estimate: MyHoursEstimate | null;
  items: MyHoursItem[];
}
