import { apiClient } from "../../../api/apiClient";
import type {
  Mission,
  PaginatedResponse,
  CreateMissionBody,
  CreateMissionResult,
  SiteListItem,
  UserListItem,
  InstrumentistsResponse,
} from "./missions.types";
import type { MissionPatchBody, PublishMissionBody } from "./missions.requests";

/**
 * Filtres génériques backend (GET /api/missions)
 * NB: le backend est source de vérité (status, allowedActions, etc.)
 */
export type MissionsFilters = {
  status?: string; // ex: "OPEN" ou "ASSIGNED,IN_PROGRESS"
  type?: string;
  siteId?: number;

  // Lot 3
  eligibleToMe?: boolean; // OPEN offers (si supporté backend)
  assignedToMe?: boolean; // my missions
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
 * Lot 3 — Mes missions instrumentiste (ASSIGNED / IN_PROGRESS)
 * GET /api/missions?assignedToMe=true&status=ASSIGNED,IN_PROGRESS&page=1&limit=100
 */
export async function fetchInstrumentistMyMissions(page = 1, limit = 100) {
  return fetchMissions(page, limit, {
    assignedToMe: true,
    status: "ASSIGNED,IN_PROGRESS",
  });
}

export async function fetchMissionById(id: number) {
  const { data } = await apiClient.get<Mission>(`/api/missions/${id}`);
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
