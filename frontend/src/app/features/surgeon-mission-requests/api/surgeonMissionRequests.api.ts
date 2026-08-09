import { apiClient } from "../../../api/apiClient";
import type { CreateSurgeonMissionRequestBody, SurgeonMissionRequest } from "./surgeonMissionRequests.types";

/**
 * Self-service surgeon mission request API (Lot 5, D-099) — GET/POST
 * /api/surgeon/mission-requests. Ownership is always server-enforced
 * (request.surgeon = authenticated user) — no surgeonId is ever sent from here.
 */
export async function fetchMyMissionRequests(): Promise<SurgeonMissionRequest[]> {
  const { data } = await apiClient.get("/api/surgeon/mission-requests");
  return data;
}

export async function createMyMissionRequest(body: CreateSurgeonMissionRequestBody): Promise<SurgeonMissionRequest> {
  const { data } = await apiClient.post("/api/surgeon/mission-requests", body);
  return data;
}
