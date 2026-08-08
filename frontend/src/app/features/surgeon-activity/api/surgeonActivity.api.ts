import { apiClient } from "../../../api/apiClient";
import type { SurgeonActivity } from "./surgeonActivity.types";

/**
 * Self-service surgeon activity API (Lot 4, D-098) — GET /api/surgeon/activity, always
 * self-scoped server-side (no surgeonId ever sent from here).
 */
export async function fetchSurgeonActivity(from: string, to: string): Promise<SurgeonActivity> {
  const { data } = await apiClient.get("/api/surgeon/activity", { params: { from, to } });
  return data;
}
