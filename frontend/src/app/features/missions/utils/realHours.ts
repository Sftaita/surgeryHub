import type { MissionExecutionInfo } from "../api/missions.api";

/**
 * Ce qui a réellement été déclaré pour les heures prestées, lu tel quel dans
 * `GET /api/missions/{id}/execution` — jamais complété à partir du planning :
 *
 * - `NONE`          : aucune heure réelle (aucun enregistrement, ou enregistrement vide) ;
 * - `TIMES`         : début et fin déclarés (durée calculée par le backend, ACTUAL_TIMES) ;
 * - `DURATION_ONLY` : une durée seule (ACTUAL_EXPLICIT), sans début ni fin ;
 * - `PARTIAL`       : une seule des deux bornes (avec ou sans durée).
 *
 * `minutes` n'est jamais la durée planifiée : quand le backend se replie sur le planifié
 * (`effectiveDurationSource = PLANNED`), aucune durée réelle n'est connue (null).
 */
export type RealHoursKind = "NONE" | "TIMES" | "DURATION_ONLY" | "PARTIAL";

export interface RealHours {
  kind: RealHoursKind;
  start: string | null;
  end: string | null;
  minutes: number | null;
  /** Explication à afficher quand la déclaration n'a pas de début et de fin complets. */
  note: string | null;
}

const fmt = (m: number) => `${Math.floor(m / 60)}h${String(m % 60).padStart(2, "0")}`;
const between = (a: string, b: string) => Math.round((new Date(b).getTime() - new Date(a).getTime()) / 60000);

export function realHoursOf(e: MissionExecutionInfo | null | undefined): RealHours {
  const start = e?.actualStartAt ?? null;
  const end = e?.actualEndAt ?? null;
  const declared = e?.actualDurationMinutes ?? null;

  if (!e || !e.hasExecutionRecord || (!start && !end && declared == null)) {
    return { kind: "NONE", start: null, end: null, minutes: null, note: null };
  }
  if (start && end) {
    const minutes = e.effectiveDurationSource === "ACTUAL_TIMES" ? e.effectiveDurationMinutes : declared ?? between(start, end);
    return { kind: "TIMES", start, end, minutes, note: null };
  }
  if (!start && !end) {
    return {
      kind: "DURATION_ONLY", start: null, end: null, minutes: declared,
      note: `Une durée réelle de ${fmt(declared as number)} a été déclarée, mais les heures de début et de fin n'ont pas été renseignées.`,
    };
  }
  const missing = start ? "l'heure de fin n'a pas été renseignée" : "l'heure de début n'a pas été renseignée";
  return {
    kind: "PARTIAL", start, end, minutes: declared,
    note: `Déclaration incomplète : ${missing}.` + (declared == null ? " Aucune durée réelle ne peut être comparée au planifié." : ""),
  };
}
