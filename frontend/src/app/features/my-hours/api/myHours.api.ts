import { apiClient } from "../../../api/apiClient";
import type { MyHoursStatement } from "./myHours.types";

/** D-179 — relevé du mois (YYYY-MM), éventuellement restreint à un site. */
export async function fetchMyHours(month: string, siteId: number | null): Promise<MyHoursStatement> {
  const { data } = await apiClient.get<MyHoursStatement>("/api/me/hours", {
    params: siteId === null ? { month } : { month, siteId },
  });
  return data;
}
