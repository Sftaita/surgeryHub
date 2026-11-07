/**
 * D-147 — raccourcis de période de la vue Missions manager, traduits en paramètres de
 * GET /api/missions (`from` inclus / `to` exclu, intersection côté serveur — Lot P1B ;
 * `order` sur startAt). Bornes en minuit LOCAL (jamais de round-trip "yyyy-MM-dd" en UTC,
 * voir encoding-tracking/period.ts) puis sérialisées en ISO : l'instant est exact.
 */
export type MissionsPeriod = "upcoming" | "today" | "week" | "past" | "all";

export const MISSIONS_PERIODS: { value: MissionsPeriod; label: string }[] = [
  { value: "upcoming", label: "À venir" },
  { value: "today", label: "Aujourd'hui" },
  { value: "week", label: "7 prochains jours" },
  { value: "past", label: "Passées" },
  { value: "all", label: "Toutes les dates" },
];

export function isMissionsPeriod(v: string | null): v is MissionsPeriod {
  return MISSIONS_PERIODS.some((p) => p.value === v);
}

export interface PeriodParams {
  from?: string;
  to?: string;
  order: "asc" | "desc";
}

function localMidnight(reference: Date, plusDays = 0): Date {
  return new Date(reference.getFullYear(), reference.getMonth(), reference.getDate() + plusDays);
}

export function periodParams(period: MissionsPeriod, reference: Date = new Date()): PeriodParams {
  switch (period) {
    case "upcoming":
      return { from: localMidnight(reference).toISOString(), order: "asc" };
    case "today":
      return { from: localMidnight(reference).toISOString(), to: localMidnight(reference, 1).toISOString(), order: "asc" };
    case "week":
      return { from: localMidnight(reference).toISOString(), to: localMidnight(reference, 7).toISOString(), order: "asc" };
    case "past":
      return { to: localMidnight(reference).toISOString(), order: "desc" };
    case "all":
      return { order: "desc" };
  }
}
