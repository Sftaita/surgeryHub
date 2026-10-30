import type { RoomHistoryEvent, RoomOperationalStateName } from "./roomTakeover.types";

const TZ = "Europe/Brussels";

/** « mer. 04/11 à 07:45 » — heure de Bruxelles, quel que soit le fuseau du navigateur. */
export function formatDeadline(iso: string | null | undefined): string {
  if (!iso) return "—";
  const d = new Date(iso);
  const day = d.toLocaleDateString("fr-BE", { weekday: "short", day: "2-digit", month: "2-digit", timeZone: TZ });
  const time = d.toLocaleTimeString("fr-BE", { hour: "2-digit", minute: "2-digit", timeZone: TZ });
  return `${day} à ${time}`;
}

export function formatDateTime(iso: string): string {
  return new Date(iso).toLocaleString("fr-BE", { dateStyle: "short", timeStyle: "short", timeZone: TZ });
}

export const OPERATIONAL_STATE_LABELS: Record<RoomOperationalStateName, string> = {
  AVAILABLE: "Disponible",
  PRIORITY_PENDING: "Proposition prioritaire en attente",
  POOL_OPEN: "Pool ouvert — à pourvoir",
  INSTRUMENTIST_CONFIRMED: "Instrumentiste confirmé",
  IN_PROGRESS_OR_DONE: "Mission en cours ou terminée",
  MISSION_CANCELLED: "Mission annulée",
  DRAFT: "Brouillon",
};

const POOL_REASONS: Record<string, string> = {
  NO_USUAL_INSTRUMENTIST: "aucune instrumentiste habituelle identifiable",
  USUAL_INSTRUMENTIST_ABSENT: "instrumentiste habituelle absente",
  USUAL_INSTRUMENTIST_INELIGIBLE: "instrumentiste habituelle inéligible",
  PRIORITY_DECLINED: "priorité refusée",
  PRIORITY_EXPIRED: "priorité expirée sans réponse",
  RECIPIENT_UNAVAILABLE: "destinataire devenue indisponible (congé)",
  LIFTED_BY_MANAGER: "priorité levée par un manager",
};

const END_REASONS: Record<string, string> = {
  ACCEPTED: "acceptée",
  DECLINED: "refusée",
  EXPIRED: "expirée sans réponse (48 h écoulées)",
  RECIPIENT_UNAVAILABLE: "close : destinataire en congé",
  LIFTED_BY_MANAGER: "levée par un manager",
  SUPERSEDED: "close : mission attribuée à une autre instrumentiste",
  MISSION_CLOSED: "close : mission annulée",
};

const SKIP_REASONS: Record<string, string> = {
  NO_PUSH_SUBSCRIPTION: "aucun appareil",
  DISABLED_BY_PREFERENCE: "désactivé par préférence",
  NOT_APPLICABLE: "non applicable",
  COVERED_BY_PUSH: "couvert par la notification push",
};

/** Libellé + précision d'un événement d'historique (le backend fournit le fait, ceci le met en mots). */
export function describeHistoryEvent(e: RoomHistoryEvent): { title: string; detail: string | null } {
  const d = e.details ?? {};
  switch (e.type) {
    case "ABSENCE_DECLARED":
      return { title: `Absence de ${d.surgeonName ?? "chirurgien"}`, detail: `${d.dateStart} → ${d.dateEnd}${d.reason ? ` · ${d.reason}` : ""}` };
    case "ROOM_SLOT_MADE_AVAILABLE":
      return { title: "Salle rendue disponible", detail: null };
    case "SURGEON_MISSION_REQUEST_CREATED":
      return { title: `Demande de mission de ${d.surgeonName ?? "chirurgien"}`, detail: `Demande #${d.surgeonMissionRequestId}` };
    case "SURGEON_MISSION_REQUEST_ACCEPTED":
      return {
        title: d.acceptedAsTakeover ? "Demande acceptée comme reprise de la salle" : "Demande acceptée",
        detail: d.roomSlotDecision?.justification ? `Aucune salle libérée retenue : ${d.roomSlotDecision.justification}` : (d.reviewComment ?? null),
      };
    case "ROOM_SLOT_TAKEN_OVER":
      return { title: `Salle reprise par ${d.takenByName ?? "—"}`, detail: d.initialInstrumentistName ? `Instrumentiste habituelle : ${d.initialInstrumentistName}` : "Aucune instrumentiste habituelle identifiée" };
    case "ROOM_SLOT_TAKEOVER_REGULARIZED":
      return { title: "Reprise régularisée administrativement", detail: d.surgeonMissionRequestId ? `Demande #${d.surgeonMissionRequestId}` : null };
    case "ROOM_SLOT_TAKEOVER_REGULARIZATION_REVERTED":
      return { title: "Régularisation annulée", detail: null };
    case "MISSION_ADDED_POST_DEPLOY":
      return { title: `Mission #${e.missionId} créée`, detail: null };
    case "ROOM_TAKEOVER_PRIORITY_OFFERED":
      return {
        title: `Proposition prioritaire à ${d.instrumentistName ?? "—"}`,
        detail: `Échéance ${formatDeadline(d.expiresAt)}${d.missionStartsBeforeExpiry ? " · la mission commence avant l'échéance" : ""}`,
      };
    case "ROOM_TAKEOVER_PRIORITY_BYPASSED":
      return { title: "Pas de priorité", detail: POOL_REASONS[d.reason] ?? d.reason ?? null };
    case "ROOM_TAKEOVER_PRIORITY_ENDED":
      return {
        title: `Priorité de ${d.instrumentistName ?? "—"} ${END_REASONS[d.endReason] ?? d.endReason}`,
        detail: [d.justification ? `Justification : ${d.justification}` : null, d.reason ? `Motif : ${d.reason}` : null,
          d.offeredAt ? `envoyée ${formatDeadline(d.offeredAt)}, échéance ${formatDeadline(d.expiresAt)}` : null].filter(Boolean).join(" · ") || null,
      };
    case "MISSION_PUBLISHED_TO_POOL":
      return { title: "Mission ouverte au pool", detail: d.poolOpenedReason ? (POOL_REASONS[d.poolOpenedReason] ?? d.poolOpenedReason) : null };
    case "MISSION_OFFERED_TO_INSTRUMENTIST":
      return { title: `Demande envoyée à ${d.instrumentistName ?? "—"}`, detail: null };
    case "MISSION_OFFER_DECLINED":
      return { title: `Demande refusée par ${d.instrumentistName ?? "—"}`, detail: d.reason ?? null };
    case "MISSION_CLAIMED_FROM_POOL":
      return { title: `Mission acceptée par ${d.instrumentistName ?? "—"}`, detail: null };
    case "MISSION_ASSIGNED_DIRECTLY":
    case "MISSION_REASSIGNED_POST_DEPLOY":
      return { title: `Instrumentiste affectée : ${d.instrumentistName ?? d.toInstrumentistName ?? "—"}`, detail: null };
    case "MISSION_RELEASED_TO_POOL":
      return { title: `Instrumentiste désengagée : ${d.fromInstrumentistName ?? "—"}`, detail: d.reason ?? null };
    case "MISSION_CANCELLED_POST_DEPLOY":
      return { title: "Mission annulée", detail: d.reason ?? null };
    case "ROOM_SLOT_REOPENED":
      return { title: "Salle de nouveau disponible", detail: d.reason === "TAKER_ABSENT" ? `${d.previousTakenByName ?? "Le repreneur"} absent` : `${d.previousTakenByName ?? "Le repreneur"} s'est désisté` };
    case "PRIORITY_OFFER_DELIVERY":
      return {
        title: `Notification de la proposition à ${d.recipientName ?? "—"}`,
        detail: (d.channels ?? []).map((c: any) => `${c.channel} ${c.status === "SENT" ? "envoyé" : c.status === "FAILED" ? "en échec" : c.skipReason ? `ignoré (${SKIP_REASONS[c.skipReason] ?? c.skipReason})` : c.status}`).join(" · ") || null,
      };
    case "POOL_SOLICITED":
      return { title: `Pool sollicité (${d.recipientCount ?? 0} instrumentiste${(d.recipientCount ?? 0) > 1 ? "s" : ""})`, detail: (d.recipients ?? []).join(", ") || null };
    default:
      return { title: e.type, detail: null };
  }
}
