/**
 * D-147 — regroupement par jour civil Europe/Brussels (timezone métier, D-066), pour la
 * vue Missions « par jour » et le journal d'activité. Purement présentationnel : l'ordre
 * des éléments est celui du serveur, conservé à l'intérieur de chaque jour.
 */
const BRUSSELS_TZ = "Europe/Brussels";

// en-CA formate en yyyy-MM-dd — clé de jour stable, triable, sans round-trip UTC.
const dayKeyFormat = new Intl.DateTimeFormat("en-CA", {
  timeZone: BRUSSELS_TZ,
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
});

const dayLabelFormat = new Intl.DateTimeFormat("fr-BE", {
  timeZone: BRUSSELS_TZ,
  weekday: "long",
  day: "numeric",
  month: "long",
  year: "numeric",
});

const timeFormat = new Intl.DateTimeFormat("fr-BE", {
  timeZone: BRUSSELS_TZ,
  hour: "2-digit",
  minute: "2-digit",
});

export function brusselsDayKey(iso: string | Date): string {
  return dayKeyFormat.format(typeof iso === "string" ? new Date(iso) : iso);
}

export function formatBrusselsTime(iso: string): string {
  return timeFormat.format(new Date(iso));
}

export interface DayGroup<T> {
  key: string; // yyyy-MM-dd
  items: T[];
}

/** Groupes dans l'ordre d'apparition (donc l'ordre serveur), éléments dans l'ordre reçu. */
export function groupByDay<T>(items: T[], dateOf: (item: T) => string | null | undefined): DayGroup<T>[] {
  const groups: DayGroup<T>[] = [];
  const byKey = new Map<string, DayGroup<T>>();
  for (const item of items) {
    const iso = dateOf(item);
    const key = iso ? brusselsDayKey(iso) : "—";
    let group = byKey.get(key);
    if (!group) {
      group = { key, items: [] };
      byKey.set(key, group);
      groups.push(group);
    }
    group.items.push(item);
  }
  return groups;
}

function shiftDayKey(key: string, days: number): string {
  const [y, m, d] = key.split("-").map(Number);
  // Midi UTC : aucun changement d'heure ne peut faire basculer le jour.
  return new Date(Date.UTC(y, m - 1, d + days, 12)).toISOString().slice(0, 10);
}

/** « Aujourd'hui », « Demain », « Hier » ou null. */
export function relativeDayLabel(key: string, now: Date = new Date()): string | null {
  const today = brusselsDayKey(now);
  if (key === today) return "Aujourd'hui";
  if (key === shiftDayKey(today, 1)) return "Demain";
  if (key === shiftDayKey(today, -1)) return "Hier";
  return null;
}

/** « lundi 13 octobre 2026 », capitalisé. */
export function formatDayLabel(key: string): string {
  if (key === "—") return "Date inconnue";
  const [y, m, d] = key.split("-").map(Number);
  const label = dayLabelFormat.format(new Date(Date.UTC(y, m - 1, d, 12)));
  return label.charAt(0).toUpperCase() + label.slice(1);
}
