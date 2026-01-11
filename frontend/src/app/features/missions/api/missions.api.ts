import { apiClient } from "../../../api/apiClient";
import type { Mission, PaginatedResponse } from "./missions.types";

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
