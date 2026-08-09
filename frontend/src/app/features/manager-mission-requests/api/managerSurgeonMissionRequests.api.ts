import { apiClient } from "../../../api/apiClient";
import type { ManagerSurgeonMissionRequest } from "./managerSurgeonMissionRequests.types";

/**
 * Manager review API for surgeon mission requests (Lot 5, D-099).
 * GET/accept/reject on /api/manager/surgeon-mission-requests.
 */
export async function getSurgeonMissionRequests(status?: string): Promise<{ items: ManagerSurgeonMissionRequest[]; total: number }> {
  const { data } = await apiClient.get("/api/manager/surgeon-mission-requests", { params: status ? { status } : undefined });
  return data;
}

export async function acceptSurgeonMissionRequest(id: number, reviewComment?: string): Promise<ManagerSurgeonMissionRequest> {
  const { data } = await apiClient.post(`/api/manager/surgeon-mission-requests/${id}/accept`, { reviewComment });
  return data;
}

export async function rejectSurgeonMissionRequest(id: number, reviewComment: string): Promise<ManagerSurgeonMissionRequest> {
  const { data } = await apiClient.post(`/api/manager/surgeon-mission-requests/${id}/reject`, { reviewComment });
  return data;
}

/** Réutilisé par le badge nav (useNavBadgeCount) — même endpoint que la liste, filtré PENDING. */
export async function getPendingSurgeonMissionRequestsCount(): Promise<number> {
  const { total } = await getSurgeonMissionRequests("PENDING");
  return total;
}
