import { apiClient } from "../../../api/apiClient";

/**
 * D-181 — changement d'instrumentiste de missions publiées (une ou plusieurs) :
 * POST /api/missions/reassignments. Chaque mission est contrôlée et enregistrée seule côté
 * serveur ; le résultat dit, mission par mission, ce qui a été fait ou refusé.
 */
export type ReassignmentMode = "DIRECT" | "RELAY";

export interface ReassignmentItem {
  missionId: number;
  instrumentistId: number;
  mode: ReassignmentMode;
  /** ISO 8601 — RELAY seulement : l'heure à laquelle l'instrumentiste de remplacement prend le relais. */
  relayAt?: string;
  /** L'instrumentiste vue à l'écran : le serveur refuse si la mission a changé entre-temps. */
  expectedInstrumentistId: number | null;
}

export interface ReassignmentError {
  status: number;
  code: string;
  message: string;
  reasons?: string[];
}

export interface ReassignmentResultItem {
  missionId: number | null;
  status: "DONE" | "FAILED";
  mode?: ReassignmentMode;
  relayMissionId?: number | null;
  error?: ReassignmentError;
}

/** CONFIRMED_INSTRUMENTIST — D-182 : mission de remplacement conservée et validée. */
export type ReassignmentRecipientRole = "PREVIOUS_INSTRUMENTIST" | "NEW_INSTRUMENTIST" | "CONFIRMED_INSTRUMENTIST" | "SURGEON";

export interface ReassignmentResult {
  operationId: string;
  results: ReassignmentResultItem[];
  recipients: Array<{ userId: number; name: string; roles: ReassignmentRecipientRole[] }>;
}

export interface DeliveryStatus {
  status: "QUEUED" | "SENT" | "FAILED" | "SKIPPED";
  skipReason: string | null;
  at: string | null;
}

export interface OperationRecipientStatus {
  userId: number;
  name: string | null;
  missionIds: number[];
  email: DeliveryStatus | null;
  inApp: DeliveryStatus | null;
}

export async function reassignMissions(items: ReassignmentItem[], comment?: string): Promise<ReassignmentResult> {
  const { data } = await apiClient.post<ReassignmentResult>("/api/missions/reassignments", {
    items,
    ...(comment && comment.trim() !== "" ? { comment: comment.trim() } : {}),
  });
  return data;
}

/** Statut réel des notifications d'une opération (une ligne par destinataire déjà traité). */
export async function fetchReassignmentOperation(operationId: string): Promise<OperationRecipientStatus[]> {
  const { data } = await apiClient.get<{ recipients: OperationRecipientStatus[] }>(
    `/api/missions/reassignment-operations/${operationId}`,
  );
  return data.recipients;
}

/** "2026-11-03T08:00:00+01:00" + "10:30" → "2026-11-03T10:30:00+01:00" (même jour, même décalage). */
export function relayInstant(missionStartAt: string, hhmm: string): string {
  return `${missionStartAt.slice(0, 10)}T${hhmm}:00${missionStartAt.slice(19)}`;
}

export const ROLE_LABEL: Record<ReassignmentRecipientRole, string> = {
  PREVIOUS_INSTRUMENTIST: "mission retirée",
  NEW_INSTRUMENTIST: "nouvelle mission attribuée",
  CONFIRMED_INSTRUMENTIST: "mission confirmée",
  SURGEON: "chirurgien",
};

/** Libellé honnête d'un statut de livraison (« envoyé » = accepté par le transport, jamais « lu »). */
export function deliveryLabel(d: DeliveryStatus | null): string {
  if (d === null) return "en attente";
  switch (d.status) {
    case "SENT":
      return "envoyé";
    case "QUEUED":
      return "en cours d'envoi";
    case "FAILED":
      return "échec de l'envoi";
    case "SKIPPED":
      return d.skipReason === "DISABLED_BY_PREFERENCE" ? "non envoyé (désactivé dans ses préférences)" : "non envoyé";
  }
}
