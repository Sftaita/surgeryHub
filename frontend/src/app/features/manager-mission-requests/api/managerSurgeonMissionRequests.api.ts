import type { RoomSlotDecision } from "../../room-takeover/roomTakeover.types";
import { apiClient } from "../../../api/apiClient";
import type { ManagerSurgeonMissionRequest, SurgeonMissionRequestCounts } from "./managerSurgeonMissionRequests.types";
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
  roomSlot?: RoomSlotDecision,
): Promise<ManagerSurgeonMissionRequest> {
  const { data } = await apiClient.post(`/api/manager/surgeon-mission-requests/${id}/accept`, {
    reviewComment,
    ...(dispatch ? { dispatch: toDispatchPayload(dispatch) } : {}),
    // D-177 — explicit choice when the request matches released room(s) ambiguously.
    ...(roomSlot ? { roomSlot } : {}),
  });
  return data;
}

export async function rejectSurgeonMissionRequest(id: number, reviewComment: string): Promise<ManagerSurgeonMissionRequest> {
  const { data } = await apiClient.post(`/api/manager/surgeon-mission-requests/${id}/reject`, { reviewComment });
  return data;
}

/**
 * D-147 — compteurs par statut, en une requête. Source unique du badge d'onglet et des
 * cartes de l'en-tête Demandes (même clé de cache : la mise à jour optimiste les déplace
 * ensemble).
 */
export async function getSurgeonMissionRequestCounts(): Promise<SurgeonMissionRequestCounts> {
  const { data } = await apiClient.get<SurgeonMissionRequestCounts>("/api/manager/surgeon-mission-requests/counts");
  return data;
}
