import type { MissionExecutionInfo } from "../api/missions.api";
import type { Mission } from "../api/missions.types";

/**
 * Ce qui a réellement été déclaré pour les heures prestées, lu tel quel dans
 * `GET /api/missions/{id}/execution` — jamais complété à partir du planning :
 *
 * - `NONE`          : aucune heure réelle (aucun enregistrement, ou enregistrement vide) ;
 * - `TIMES`         : début et fin déclarés (durée calculée par le backend, ACTUAL_TIMES) ;
 * - `DURATION_ONLY` : une durée seule (ACTUAL_EXPLICIT), sans début ni fin ;
 * - `PARTIAL`       : une seule des deux bornes (avec ou sans durée) ;
 * - `PRESENCE_SHARE`: D-156 — mission d'une prestation multi-salles : début et fin sont ceux de
 *                     la présence réelle COMMUNE, `minutes` la part imputée à cette mission
 *                     (imputation financière, éventuellement 0) — jamais des heures propres.
 *
 * `minutes` n'est jamais la durée planifiée : quand le backend se replie sur le planifié
 * (`effectiveDurationSource = PLANNED`), aucune durée réelle n'est connue (null).
 */
export type RealHoursKind = "NONE" | "TIMES" | "DURATION_ONLY" | "PARTIAL" | "PRESENCE_SHARE";

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

  // D-156 — heures communes de la prestation (WorkedPresence) : la mission n'a pas d'heures propres.
  if (e?.presence && e.effectiveDurationSource === "PRESENCE_SHARE") {
    const share = e.effectiveDurationMinutes;
    const total = e.presence.totalMinutes;
    return {
      kind: "PRESENCE_SHARE",
      start: e.presence.actualStartAt,
      end: e.presence.actualEndAt,
      minutes: share,
      note: `Présence commune de la prestation multi-salles${total != null ? ` (${fmt(total)})` : ""} : `
        + (share === 0
          ? "imputation financière de 0h00 à cette mission, les heures sont comptées sur une autre mission de la prestation."
          : `imputation financière de ${fmt(share)} à cette mission.`),
    };
  }
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

/**
 * Suite de l'explication sous les heures : ce qu'il est possible de faire, lu uniquement dans
 * ce que sert le backend — jamais une action indisponible.
 *
 * - `edit_hours` servi        → la correction directe ;
 * - `reopen` servi            → la réouverture (jamais servie sous verrou financier, D-148) ;
 * - motif de blocage servi    → ce motif, tel quel (`encodingCorrectionBlockedReason`) ;
 * - verrou sans autre droit   → les heures ne sont plus modifiables, sans action proposée.
 */
export function hoursCorrectionHint(
  mission: Pick<Mission, "allowedActions" | "encodingCorrectionBlockedReason" | "encoding"> | null | undefined,
): string {
  const actions: readonly string[] = mission?.allowedActions ?? [];
  if (actions.includes("edit_hours")) return "Utilisez « Corriger les heures » pour renseigner les horaires.";
  if (actions.includes("reopen")) return "Les heures sont verrouillées avec l'encodage : une correction passe par sa réouverture.";
  const blocked = mission?.encodingCorrectionBlockedReason ?? null;
  if (blocked) return `Les heures ne sont plus modifiables. ${blocked}`;
  if ((mission?.encoding?.locks?.length ?? 0) > 0) return "Les heures ne sont plus modifiables.";
  return "";
}
