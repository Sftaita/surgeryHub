import { apiClient } from "../../api/apiClient";
import type { RoomHistory } from "./roomTakeover.types";

/** D-177 — historique complet et permanent d'une salle libérée (managers). */
export async function getReleasedRoomHistory(slotId: number): Promise<RoomHistory> {
  const res = await apiClient.get(`/api/planning/available-rooms/${slotId}/history`);
  return res.data as RoomHistory;
}

/**
 * D-177 (Cas D) — lever la priorité de l'instrumentiste habituelle et ouvrir le pool tout de
 * suite. Justification obligatoire (422 sinon), 409 si la priorité vient de prendre fin.
 */
export async function liftMissionPriority(missionId: number, justification: string): Promise<void> {
  await apiClient.post(`/api/missions/${missionId}/lift-priority`, { justification });
}
