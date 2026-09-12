/**
 * Suivi des encodages (D-118) — calcul de bornes de période côté client, purement
 * calendaire (jour civil local). Aucune règle métier ici : `from` inclusif / `to`
 * exclusif est une convention de sérialisation, pas une dérivation d'état (voir D-077).
 *
 * ATTENTION timezone : toute conversion Date <-> "yyyy-MM-dd" DOIT passer par les
 * composants LOCAUX (getFullYear/getMonth/getDate), jamais par toISOString() ni par
 * `new Date("yyyy-MM-dd")`. Les deux passent par UTC en interne — pour un utilisateur en
 * Europe/Brussels (UTC+1/+2, la timezone métier réelle de SurgicalHub, D-066), minuit
 * local converti en UTC retombe la veille au soir, décalant "Aujourd'hui" d'un jour en
 * arrière. Bug réel détecté par les tests de ce module, corrigé ici — jamais de
 * round-trip UTC dans tout ce fichier.
 */

export type PeriodShortcut = "today" | "yesterday" | "last7Days" | "thisMonth";

export interface Period {
  from: string; // yyyy-MM-dd
  to: string; // yyyy-MM-dd, exclusif
}

function pad2(n: number): string {
  return String(n).padStart(2, "0");
}

/** yyyy-MM-dd à partir des composants LOCAUX — jamais toISOString() (voir avertissement en tête de fichier). */
function toDateOnly(d: Date): string {
  return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
}

/**
 * Parse "yyyy-MM-dd" en minuit LOCAL — jamais `new Date(s)`, qui parse en UTC (correct
 * "par chance" pour un runtime en Europe/Brussels — UTC+1/+2 pousse la date affichée en
 * avant, jamais en arrière — mais faux dans un fuseau à l'ouest de UTC ; ne pas s'y fier).
 * Exportée pour que tout code qui reçoit une date ISO tronquée en "yyyy-MM-dd" (ex.
 * `item.startAt.slice(0, 10)`) la reparse de la même façon, sans réintroduire le bug.
 */
export function parseDateOnly(s: string): Date {
  const [y, m, d] = s.split("-").map(Number);
  return new Date(y, m - 1, d);
}

function addDays(d: Date, days: number): Date {
  const copy = new Date(d);
  copy.setDate(copy.getDate() + days);
  return copy;
}

export function periodForShortcut(shortcut: PeriodShortcut, reference: Date = new Date()): Period {
  const today = new Date(reference.getFullYear(), reference.getMonth(), reference.getDate());

  switch (shortcut) {
    case "today":
      return { from: toDateOnly(today), to: toDateOnly(addDays(today, 1)) };
    case "yesterday": {
      const y = addDays(today, -1);
      return { from: toDateOnly(y), to: toDateOnly(today) };
    }
    case "last7Days":
      return { from: toDateOnly(addDays(today, -6)), to: toDateOnly(addDays(today, 1)) };
    case "thisMonth": {
      const first = new Date(today.getFullYear(), today.getMonth(), 1);
      const firstNext = new Date(today.getFullYear(), today.getMonth() + 1, 1);
      return { from: toDateOnly(first), to: toDateOnly(firstNext) };
    }
  }
}

/** Longueur en jours de la période (utilisée pour préc./suiv. — décale d'un bloc identique). */
export function periodLengthDays(period: Period): number {
  const from = parseDateOnly(period.from);
  const to = parseDateOnly(period.to);
  return Math.max(1, Math.round((to.getTime() - from.getTime()) / 86_400_000));
}

export function shiftPeriod(period: Period, direction: -1 | 1): Period {
  const days = periodLengthDays(period);
  const from = addDays(parseDateOnly(period.from), direction * days);
  const to = addDays(parseDateOnly(period.to), direction * days);
  return { from: toDateOnly(from), to: toDateOnly(to) };
}

/** Libellé lisible d'une période — jour unique affiché sans borne de fin. */
export function formatPeriodLabel(period: Period): string {
  const from = parseDateOnly(period.from);
  const lastIncludedDay = addDays(parseDateOnly(period.to), -1);
  const fmt = (d: Date) => d.toLocaleDateString("fr-BE", { day: "numeric", month: "short" });

  return periodLengthDays(period) === 1 ? fmt(from) : `${fmt(from)} → ${fmt(lastIncludedDay)}`;
}
