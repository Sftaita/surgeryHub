// src/app/features/missions/utils/missions.format.ts
const BRUSSELS_TZ = "Europe/Brussels";

export function formatBrusselsRange(startIso: string, endIso: string) {
  const start = new Date(startIso);
  const end = new Date(endIso);

  const fmt = new Intl.DateTimeFormat("fr-BE", {
    timeZone: BRUSSELS_TZ,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  });

  return `${fmt.format(start)} → ${fmt.format(end)}`;
}

export function formatPersonLabel(
  person:
    | { firstname?: string | null; lastname?: string | null; email: string }
    | null
    | undefined
) {
  if (!person) return "—";
  const fn = (person.firstname ?? "").trim();
  const ln = (person.lastname ?? "").trim();
  const full = `${fn} ${ln}`.trim();
  return full.length > 0 ? full : person.email;
}
