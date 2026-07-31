import { apiClient } from "../../../api/apiClient";
import type {
  Mission,
  PaginatedResponse,
  CreateMissionBody,
  CreateMissionResult,
  SiteListItem,
  UserListItem,
  InstrumentistsResponse,
  InstrumentistService,
  MissionSyncResponse,
} from "./missions.types";
import type {
  MissionPatchBody,
  PublishMissionBody,
  DeclareMissionBody,
} from "./missions.requests";

/**
 * Filtres génériques backend (GET /api/missions)
 * NB: le backend est source de vérité (status, allowedActions, etc.)
 */
export type MissionsFilters = {
  status?: string; // ex: "OPEN" ou "ASSIGNED,IN_PROGRESS"
  type?: string;
  siteId?: number;

  // Lot P1A.2 — planning from/to
  from?: string;
  to?: string;

  // Lot 3
  eligibleToMe?: boolean; // OPEN offers (si supporté backend)
  assignedToMe?: boolean; // my missions

  // Planning V2 Modification mode — all missions of an already-deployed PlanningVersion
  planningVersionId?: number;
};

export async function fetchMissions(
  page = 1,
  limit = 100,
  filters: MissionsFilters = {},
) {
  const { data } = await apiClient.get<PaginatedResponse<Mission>>(
    "/api/missions",
    {
      params: {
        page,
        limit,
        ...filters,
      },
    },
  );

  return data;
}

function isEligibleToMeUnsupported(err: any): boolean {
  const status = err?.response?.status;
  const message =
    err?.response?.data?.message ??
    err?.response?.data?.detail ??
    err?.message ??
    "";

  // Heuristique "best-effort": backend qui rejette un param non reconnu
  // (selon implémentations: 400/422, message "unknown"/"unexpected"/"not allowed"...)
  if (status !== 400 && status !== 422) return false;
  const m = String(message).toLowerCase();
  return (
    m.includes("eligible") ||
    m.includes("unknown") ||
    m.includes("unexpected") ||
    m.includes("not allowed") ||
    m.includes("unrecognized") ||
    m.includes("invalid") ||
    m.includes("query")
  );
}

/**
 * Lot 3 — Offres instrumentiste (OPEN éligibles)
 * Mode préféré: GET /api/missions?status=OPEN&eligibleToMe=true
 */
export async function fetchInstrumentistOffers(page = 1, limit = 100) {
  return fetchMissions(page, limit, {
    status: "OPEN",
    eligibleToMe: true,
  });
}

/**
 * Lot 3 — Offres instrumentiste (fallback si eligibleToMe indisponible)
 * - tente eligibleToMe=true
 * - si rejet (400/422 avec message type "unknown param"), fallback: status=OPEN seul
 *
 * IMPORTANT:
 * - le frontend n’infère aucune éligibilité
 * - l’action CLAIM reste strictement conditionnée à allowedActions.includes("claim")
 */
export async function fetchInstrumentistOffersWithFallback(
  page = 1,
  limit = 100,
) {
  try {
    return await fetchInstrumentistOffers(page, limit);
  } catch (err: any) {
    if (isEligibleToMeUnsupported(err)) {
      return fetchMissions(page, limit, { status: "OPEN" });
    }
    throw err;
  }
}

/**
 * Lot 6 (audit PWA/mobile/admin 2026-07-29) — badge "offres non lues". Remplace un
 * badge cumulatif (comptait toutes les offres OPEN disponibles, ne redescendait jamais
 * en visitant l'écran Offres) par un compteur serveur filtrant sur
 * `User.offersLastSeenAt`. Le frontend n'infère aucune éligibilité ni aucune date de
 * dernière visite localement — la seule source de vérité est ce endpoint.
 */
export async function fetchOffersUnreadCount(): Promise<number> {
  const { data } = await apiClient.get<{ unreadCount: number }>("/api/missions/offers/unread-count");
  return data.unreadCount;
}

/** Checkpoint appelé quand l'écran Offres termine un chargement réussi (remet le badge à zéro côté serveur). */
export async function markOffersSeen(): Promise<void> {
  await apiClient.post("/api/me/offers-seen");
}

/**
 * Lot 3 — Mes missions instrumentiste (missions déjà “à moi”)
 *
 * IMPORTANT (backend):
 * - eligibleToMe=true = OFFRES (OPEN + publiées + claimables) -> exclut DECLARED
 * - assignedToMe=true = MES MISSIONS (incluant DECLARED si on le demande)
 *
 * GET /api/missions?assignedToMe=true&status=DECLARED,ASSIGNED,IN_PROGRESS&page=1&limit=100
 */
export async function fetchInstrumentistMyMissions(page = 1, limit = 100) {
  return fetchMissions(page, limit, {
    assignedToMe: true,
    status: "DECLARED,ASSIGNED,IN_PROGRESS",
  });
}

/**
 * V1 "polling intelligent" — GET /api/instrumentist/missions/sync?since=ISO_DATE
 *
 * - `since` absent => premier sync (le backend renvoie l'état pertinent complet)
 * - 422 si `since` est fourni mais n'est pas un ISO 8601 valide
 */
export async function fetchInstrumentistMissionSync(
  since?: string | null,
): Promise<MissionSyncResponse> {
  const { data } = await apiClient.get<MissionSyncResponse>(
    "/api/instrumentist/missions/sync",
    {
      params: since ? { since } : {},
    },
  );
  return data;
}

export async function fetchMissionById(id: number) {
  const { data } = await apiClient.get<Mission>(`/api/missions/${id}`);
  return data;
}

/**
 * Lot F5 — UI Heures prestées : patch service (edit_hours)
 * PATCH /api/missions/{id}/service
 *
 * IMPORTANT:
 * - Body = ServiceUpdateRequest (champs optionnels)
 * - UI instrumentiste n'envoie que hours (+ hoursSource optionnel)
 */
export type ServiceUpdateBody = {
  hours?: number | null;
  consultationFeeApplied?: number | null; // ⚠️ présent côté manager, NON utilisé en UI F5
  hoursSource?: string | null; // ex: "INSTRUMENTIST"
  status?: string | null; // ex: "CALCULATED" (optionnel)
};

export async function patchMissionService(
  id: number,
  body: ServiceUpdateBody,
): Promise<InstrumentistService> {
  const { data } = await apiClient.patch<InstrumentistService>(
    `/api/missions/${id}/service`,
    body,
  );
  return data;
}

/**
 * Lot F4 — Manager/Admin — Approve mission declared
 * POST /api/missions/{id}/approve-declared
 *
 * NOTE: backend peut répondre 200 (MissionDetailDto) ou 204.
 */
export async function approveDeclaredMission(
  id: number,
): Promise<Mission | null> {
  const res = await apiClient.post(`/api/missions/${id}/approve-declared`);
  if (res.status === 204) return null;
  return (res.data as Mission) ?? null;
}

/**
 * Lot F4 — Manager/Admin — Reject mission declared
 * POST /api/missions/{id}/reject-declared
 *
 * NOTE: REJECT purge l’encodage côté backend (Lot B5).
 */
export async function rejectDeclaredMission(
  id: number,
): Promise<Mission | null> {
  const res = await apiClient.post(`/api/missions/${id}/reject-declared`);
  if (res.status === 204) return null;
  return (res.data as Mission) ?? null;
}

/**
 * Lot F2 — Declare mission (instrumentist)
 * POST /api/missions/declare
 */
export async function declareMission(
  body: DeclareMissionBody,
): Promise<Mission> {
  const { data } = await apiClient.post<Mission>("/api/missions/declare", body);
  return data;
}

/**
 * Lot 3 — Claim une mission
 * POST /api/missions/{id}/claim
 *
 * NOTE: backend peut répondre 200 (MissionDetailDto) ou 204 selon implémentation.
 */
export async function claimMission(id: number): Promise<Mission | null> {
  const res = await apiClient.post(`/api/missions/${id}/claim`);
  if (res.status === 204) return null;
  return (res.data as Mission) ?? null;
}

export type SubmitMissionBody = {
  noMaterial: boolean;
  comment?: string;
};

/**
 * Lot 3 — Submit une mission
 * POST /api/missions/{id}/submit
 */
export async function submitMission(
  id: number,
  body: SubmitMissionBody,
): Promise<Mission> {
  const { data } = await apiClient.post<Mission>(`/api/missions/${id}/submit`, {
    noMaterial: body.noMaterial,
    comment: body.comment ?? "",
  });
  return data;
}

export async function fetchSites() {
  const { data } = await apiClient.get<SiteListItem[]>("/api/sites");
  return data;
}

export async function fetchSurgeons(page = 1, limit = 100) {
  const { data } = await apiClient.get<PaginatedResponse<UserListItem>>(
    "/api/surgeons",
    { params: { page, limit } },
  );
  return data;
}

export type FetchInstrumentistsParams = {
  page?: number;
  limit?: number;
};

/**
 * Lot 2b (correction finale) — instrumentistes multi-sites
 * GET /api/instrumentists
 */
export async function fetchInstrumentists(
  params: FetchInstrumentistsParams = {},
) {
  const page = params.page ?? 1;
  const limit = params.limit ?? 200;

  const { data } = await apiClient.get<InstrumentistsResponse>(
    "/api/instrumentists",
    { params: { page, limit } },
  );
  return data;
}

export async function createMission(body: CreateMissionBody) {
  const { data } = await apiClient.post<CreateMissionResult>(
    "/api/missions",
    body,
  );
  return data;
}

export async function createMissionAndPublish(
  body: CreateMissionBody,
  publishBody: PublishMissionBody,
) {
  const created = await createMission(body);
  await publishMission(created.id, publishBody);
  const refreshed = await fetchMissionById(created.id);
  return refreshed;
}

export async function patchMission(id: number, body: MissionPatchBody) {
  const { data } = await apiClient.patch<Mission>(`/api/missions/${id}`, body);
  return data;
}

export async function publishMission(
  id: number,
  body: PublishMissionBody,
): Promise<void> {
  await apiClient.post(`/api/missions/${id}/publish`, body);
}

/** EPIC Exécution & Valorisation, Lot 1 — le RÉALISÉ, distinct du planifié (Mission.startAt/endAt) et du chemin legacy (ServiceController/patchMissionService). */
export interface MissionExecutionDispute {
  id: number;
  reasonCode: string;
  comment: string | null;
  status: string;
  resolutionComment: string | null;
  raisedByDisplayName: string;
  createdAt: string;
}

export interface MissionExecutionInfo {
  missionId: number;
  hasExecutionRecord: boolean;
  actualStartAt: string | null;
  actualEndAt: string | null;
  actualDurationMinutes: number | null;
  hoursSource: string | null;
  effectiveDurationMinutes: number;
  effectiveDurationSource: string;
  disputes: MissionExecutionDispute[];
}

export async function getMissionExecution(id: number): Promise<MissionExecutionInfo> {
  const { data } = await apiClient.get<MissionExecutionInfo>(`/api/missions/${id}/execution`);
  return data;
}

/**
 * Anomalie écran d'encodage (commit dédié) — EditServiceHoursDialog appelait jusqu'ici
 * l'endpoint legacy `patchMissionService` (PATCH /api/missions/{id}/service), dont la
 * réponse est désormais l'entité MissionExecution brute (groupes execution:read),
 * PAS le DTO MissionExecutionInfo lu par GET .../execution. La carte "Heures prestées"
 * lit `mission.service?.hours`, un champ que MissionDetailDto n'expose plus du tout
 * depuis le renommage InstrumentistService -> MissionExecution (D-071) : `mission.service`
 * est donc systématiquement `undefined`, quel que soit l'état réel. PATCH .../execution
 * est la "forme cible" déjà documentée côté backend (MissionExecutionController) et
 * renvoie exactement la même forme que le GET (MissionExecutionDto) — la seule à pouvoir
 * resynchroniser le cache ["mission-execution", missionId] sans deviner de champs.
 */
export type MissionExecutionUpdateBody = {
  actualDurationMinutes?: number | null;
  hoursSource?: string | null;
};

export async function updateMissionExecution(
  id: number,
  body: MissionExecutionUpdateBody,
): Promise<MissionExecutionInfo> {
  const { data } = await apiClient.patch<MissionExecutionInfo>(
    `/api/missions/${id}/execution`,
    body,
  );
  return data;
}
