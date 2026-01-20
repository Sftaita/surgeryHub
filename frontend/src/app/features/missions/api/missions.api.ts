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

export type MissionsFilters = {
  status?: string;
  type?: string;
  siteId?: number;
};

export async function fetchMissions(
  page = 1,
  limit = 100,
  filters: MissionsFilters = {}
) {
  const { data } = await apiClient.get<PaginatedResponse<Mission>>(
    "/api/missions",
    {
      params: {
        page,
        limit,
        ...filters,
      },
    }
  );

  return data;
}

export async function fetchMissionById(id: number) {
  const { data } = await apiClient.get<Mission>(`/api/missions/${id}`);
  return data;
}

export async function fetchSites() {
  const { data } = await apiClient.get<SiteListItem[]>("/api/sites");
  return data;
}

export async function fetchSurgeons(page = 1, limit = 100) {
  const { data } = await apiClient.get<PaginatedResponse<UserListItem>>(
    "/api/surgeons",
    { params: { page, limit } }
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
 * Le backend tranche l’éligibilité TARGETED (403/422).
 */
export async function fetchInstrumentists(
  params: FetchInstrumentistsParams = {}
) {
  const page = params.page ?? 1;
  const limit = params.limit ?? 200;

  const { data } = await apiClient.get<InstrumentistsResponse>(
    "/api/instrumentists",
    { params: { page, limit } }
  );
  return data;
}

export async function createMission(body: CreateMissionBody) {
  const { data } = await apiClient.post<CreateMissionResult>(
    "/api/missions",
    body
  );
  return data;
}

export async function createMissionAndPublish(
  body: CreateMissionBody,
  publishBody: PublishMissionBody
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
  body: PublishMissionBody
): Promise<void> {
  await apiClient.post(`/api/missions/${id}/publish`, body);
}
