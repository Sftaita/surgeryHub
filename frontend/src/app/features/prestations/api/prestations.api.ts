import { apiClient } from "../../../api/apiClient";

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
};

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
