// src/app/features/missions/utils/missions.format.ts

import type { MissionExecutionInfo } from "../api/missions.api";

const BRUSSELS_TZ = "Europe/Brussels";

/**
 * Heures prestées — source de vérité unique : MissionExecutionInfo
 * (GET/PATCH /api/missions/{id}/execution), jamais `mission.service?.hours` (le backend
 * n'expose plus ce champ depuis le renommage InstrumentistService -> MissionExecution,
 * D-071 ; voir EditServiceHoursDialog.tsx). hasExecutionRecord distingue "jamais saisi"
 * de "saisi à une valeur qui vaut 0". Centralisée ici pour que MissionEncodingPage.tsx,
 * SubmitDialog.tsx et instrumentist/MissionDetailPage.tsx affichent toujours exactement
 * la même valeur, au même format, jamais trois logiques locales divergentes.
 */
export function formatExecutionHours(execution: MissionExecutionInfo | null | undefined): string {
  if (!execution?.hasExecutionRecord || execution.actualDurationMinutes == null) {
    return "Non renseigné";
  }
  const hours = execution.actualDurationMinutes / 60;
  return `${Number.isInteger(hours) ? hours : hours.toFixed(2).replace(/\.?0+$/, "")} h`;
}

/**
 * Format une plage horaire en timezone Europe/Brussels
 */
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

/**
 * Affiche un utilisateur de manière robuste
 */
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

/**
 * Mapping UI de la précision horaire
 * (cosmétique uniquement)
 */
export function formatSchedulePrecision(value?: string | null): string {
  if (!value) return "—";

  switch (value) {
    case "EXACT":
      return "Horaire confirmé";
    case "APPROXIMATE":
      return "Horaire estimé";
    default:
      return value; // fallback volontaire
  }
}

/**
 * Mapping UI du type de mission
 * (cosmétique uniquement)
 */
export function formatMissionType(value?: string | null): string {
  if (!value) return "—";

  switch (value) {
    case "BLOCK":
      return "Bloc opératoire";
    case "CONSULTATION":
      return "Consultation";
    default:
      return value;
  }
}

/**
 * Mapping UI du statut de mission
 *
 * ⚠️ Source de vérité : backend uniquement
 * ⚠️ Aucun statut frontend ajouté
 * ⚠️ “Publiée” = libellé UI de OPEN
 */
export function formatMissionStatus(value?: string | null): string {
  if (!value) return "—";

  switch (value) {
    case "DRAFT":
      return "Brouillon";
    case "OPEN":
      return "Publiée";
    case "IN_PROGRESS":
      return "En cours";
    case "ENCODING_IN_PROGRESS":
      return "Encodage en cours";
    case "ASSIGNED":
      return "Assignée";
    case "SUBMITTED":
      return "Soumise";
    case "VALIDATED":
      return "Validée";
    case "CLOSED":
      return "Clôturée";
    default:
      return value; // fallback volontaire, aucune correction frontend
  }
}
