import { apiClient } from "../../../api/apiClient";
import type { ManagerSurgeonMissionRequest } from "./managerSurgeonMissionRequests.types";
import { toDispatchPayload, type MissionDispatchChoice } from "../../missions/dispatch/missionDispatch.api";

/**
 * Manager review API for surgeon mission requests (Lot 5, D-099).
 * GET/accept/reject on /api/manager/surgeon-mission-requests.
 */
export async function getSurgeonMissionRequests(status?: string): Promise<{ items: ManagerSurgeonMissionRequest[]; total: number }> {
  const { data } = await apiClient.get("/api/manager/surgeon-mission-requests", { params: status ? { status } : undefined });
  return data;
}

/**
 * D-125 — `dispatch` (optional): the manager decides in the same action how the created
 * Mission is put into play (pool / request to an instrumentist / direct assignment) —
 * validated server-side before the request is accepted.
 */
export async function acceptSurgeonMissionRequest(
  id: number,
  reviewComment?: string,
  dispatch?: MissionDispatchChoice,
): Promise<ManagerSurgeonMissionRequest> {
  const { data } = await apiClient.post(`/api/manager/surgeon-mission-requests/${id}/accept`, {
    reviewComment,
    ...(dispatch ? { dispatch: toDispatchPayload(dispatch) } : {}),
  });
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
