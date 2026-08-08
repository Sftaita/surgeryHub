/**
 * Période Mois/Année pour l'Activité chirurgien (Lot 4, D-098) — pas de date range picker,
 * juste un mode + une date de référence + une navigation < > (§8). Demi-ouvert [from, to),
 * même convention que getRange() dans mobile-planning/planningPrimitives.
 */
export type PeriodMode = "month" | "year";

function pad2(value: number): string {
  return String(value).padStart(2, "0");
}

export function formatDateToYmd(date: Date): string {
  return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`;
}

function parseYmdToLocalDate(ymd: string): Date {
  const [y, m, d] = ymd.split("-").map(Number);
  return new Date(y ?? 1970, (m ?? 1) - 1, d ?? 1);
}

export function todayYmd(): string {
  return formatDateToYmd(new Date());
}

export function getActivityRange(mode: PeriodMode, referenceYmd: string): { from: string; to: string } {
  const ref = parseYmdToLocalDate(referenceYmd);

  if (mode === "year") {
    const from = new Date(ref.getFullYear(), 0, 1);
    const to = new Date(ref.getFullYear() + 1, 0, 1);
    return { from: formatDateToYmd(from), to: formatDateToYmd(to) };
  }

  const from = new Date(ref.getFullYear(), ref.getMonth(), 1);
  const to = new Date(ref.getFullYear(), ref.getMonth() + 1, 1);
  return { from: formatDateToYmd(from), to: formatDateToYmd(to) };
}

export function shiftActivityPeriod(referenceYmd: string, mode: PeriodMode, direction: -1 | 1): string {
  const ref = parseYmdToLocalDate(referenceYmd);
  if (mode === "year") {
    return formatDateToYmd(new Date(ref.getFullYear() + direction, 0, 1));
  }
  return formatDateToYmd(new Date(ref.getFullYear(), ref.getMonth() + direction, 1));
}

function capitalize(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1);
}

export function formatActivityPeriodLabel(referenceYmd: string, mode: PeriodMode): string {
  const ref = parseYmdToLocalDate(referenceYmd);
  if (mode === "year") return String(ref.getFullYear());
  return capitalize(ref.toLocaleDateString("fr-BE", { month: "long", year: "numeric" }));
}
