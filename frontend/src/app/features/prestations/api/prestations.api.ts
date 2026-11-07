import { apiClient } from "../../../api/apiClient";
import type { MissionEncodingResponse } from "../../encoding/api/encoding.types";

/**
 * D-156 — prestation regroupée multi-salles. Toujours calculée par le serveur à partir des
 * missions réelles (jamais recalculée ici) : le frontend affiche, saisit une fois les
 * heures communes et soumet la prestation, sans aucune règle métier locale.
 */
export type PrestationMission = {
  id: number;
  ordinal: number;
  /** Identification non ambiguë : « Salle 3 · 07h45–13h00 · n° 674 » ou « Mission 1/2 · … ». */
  label: string;
  roomLabel: string | null;
  startAt: string;
  endAt: string;
  plannedMinutes: number;
  status: string;
  /** Part imputée de la présence commune (null tant qu'elle n'est pas déclarée). */
  imputedMinutes: number | null;
  effectiveMinutes: number;
  effectiveSource: string;
  linkedToPresence: boolean;
  hasOwnHours: boolean;
  interventionCount: number;
  activeMaterialLineCount: number;
  noMaterialComment: string | null;
  /** Complément D-156 — mission qui reçoit tout nouvel encodage de la prestation (décidée par le serveur). */
  isReference: boolean;
  /** Porte des interventions, du matériel ou des demandes au catalogue. */
  carriesContent: boolean;
  allowedActions: string[];
};

export type PrestationPresence = {
  id: number;
  revision: number;
  actualStartAt: string | null;
  actualEndAt: string | null;
  totalMinutes: number | null;
  missionIds: number[];
  allocationRule: string;
};

export type PrestationView = {
  key: string;
  size: number;
  missionIds: number[];
  missions: PrestationMission[];
  date: string;
  instrumentist: { id: number; name: string } | null;
  surgeon: { id: number; name: string } | null;
  site: { id: number; name: string };
  planned: { startAt: string; endAt: string; unionMinutes: number };
  presence: PrestationPresence | null;
  unlinkedMissionIds: number[];
  ownHoursMissionIds: number[];
  statuses: string[];
  globalStatus: string;
  /** Empreinte de l'état soumis : renvoyée telle quelle à la soumission (contrôle de concurrence). */
  fingerprint: string;
  allowedActions: string[];
  /** Missions qui empêchent le lecteur de modifier les heures communes (soumise, validée…). */
  hoursLockedByMissionIds?: number[];
  /**
   * Complément D-156 — un seul encodage par prestation : la mission qui reçoit tout nouveau
   * contenu (jamais choisie ici), les autres missions qui portent du contenu historique
   * (affiché regroupé, jamais déplacé), les compteurs de toute la prestation et la
   * justification « sans matériel » unique, appréciée sur toute la prestation.
   */
  referenceMissionId: number;
  otherEncodedMissionIds: number[];
  encoding: { interventionCount: number; encodedInterventionCount: number; materialLineCount: number };
  noMaterialJustificationRequired: boolean;
};

/**
 * Complément D-156 — l'encodage de toute la prestation en une liste (même contrat que
 * GET /api/missions/{id}/encoding, chaque entrée portant son `missionId`).
 */
export async function fetchPrestationEncoding(missionId: number): Promise<MissionEncodingResponse> {
  const { data } = await apiClient.get<MissionEncodingResponse>(`/api/missions/${missionId}/prestation/encoding`);
  return data;
}

export async function fetchPrestation(missionId: number): Promise<PrestationView> {
  const { data } = await apiClient.get<PrestationView>(`/api/missions/${missionId}/prestation`);
  return data;
}

export async function savePrestationPresence(
  missionId: number,
  body: {
    actualStartAt: string;
    actualEndAt?: string;
    expectedMissionIds: number[];
    expectedRevision?: number;
    convertOwnHours?: boolean;
    /** Obligatoire pour un manager : correction motivée des heures communes (historique). */
    reason?: string;
  },
): Promise<PrestationView> {
  const { data } = await apiClient.put<PrestationView>(`/api/missions/${missionId}/prestation/presence`, body);
  return data;
}

export async function submitPrestation(
  missionId: number,
  body: { fingerprint: string; noMaterialComment?: string },
): Promise<PrestationView> {
  const { data } = await apiClient.post<PrestationView>(`/api/missions/${missionId}/prestation/submit`, body);
  return data;
}

export async function detachPresence(missionId: number, reason: string): Promise<PrestationView> {
  const { data } = await apiClient.post<PrestationView>(`/api/missions/${missionId}/encoding/detach-presence`, { reason });
  return data;
}

export async function updateRoomLabel(missionId: number, roomLabel: string | null): Promise<{ id: number; roomLabel: string | null }> {
  const { data } = await apiClient.patch(`/api/missions/${missionId}/room-label`, { roomLabel });
  return data;
}

/** Erreur API normalisée ({error: {code, message, …}}), sans interprétation métier. */
export function prestationError(err: unknown): { status?: number; code?: string; message: string; extra: Record<string, unknown> } {
  const e = err as { response?: { status?: number; data?: { error?: Record<string, unknown> } }; message?: string };
  const error = e?.response?.data?.error ?? {};
  return {
    status: e?.response?.status,
    code: typeof error.code === "string" ? error.code : undefined,
    message: typeof error.message === "string" && error.message !== "" ? error.message : e?.message ?? "Erreur",
    extra: error,
  };
}

export function formatMinutes(minutes: number | null | undefined): string {
  if (minutes == null) return "—";
  return `${Math.floor(minutes / 60)}h${String(minutes % 60).padStart(2, "0")}`;
}

/**
 * D-156 — the imputed share is a financial allocation of the one shared presence, never a
 * statement of work: 0 min means the hours are paid on another mission of the prestation,
 * not that this room was not worked (its interventions and material stay billable).
 */
export function formatImputation(minutes: number | null | undefined): string {
  if (minutes == null) return "imputation financière non calculée";
  if (minutes === 0) return "imputation financière 0h00 (heures comptées sur une autre mission de la prestation)";
  return `imputation financière ${formatMinutes(minutes)}`;
}
