import { apiClient } from "../../../api/apiClient";
import type {
  SurgeonCreateDTO,
  SurgeonListItemDTO,
  SurgeonProfileDTO,
  SurgeonPlanningEvent,
  SurgeonSiteMembershipDTO,
} from "./surgeons.types";

export async function getSurgeons(params?: {
  q?: string;
  active?: boolean;
}): Promise<{ items: SurgeonListItemDTO[]; total: number }> {
  const { data } = await apiClient.get("/api/surgeons", { params });
  return data;
}

export async function getSurgeon(id: number): Promise<SurgeonProfileDTO> {
  const { data } = await apiClient.get(`/api/surgeons/${id}`);
  return data;
}

export async function createSurgeon(
  body: SurgeonCreateDTO,
): Promise<{ surgeon: SurgeonProfileDTO; warnings: any[] }> {
  const { data } = await apiClient.post("/api/surgeons", body);
  return data;
}

export async function getSurgeonPlanning(
  id: number,
  from: string,
  to: string,
): Promise<SurgeonPlanningEvent[]> {
  const { data } = await apiClient.get(`/api/surgeons/${id}/planning`, {
    params: { from, to },
  });
  return data;
}

export async function addSurgeonSiteMembership(
  id: number,
  siteId: number,
): Promise<SurgeonSiteMembershipDTO> {
  const { data } = await apiClient.post(
    `/api/surgeons/${id}/site-memberships`,
    { siteId },
  );
  return data;
}

export async function deleteSurgeonSiteMembership(
  id: number,
  membershipId: number,
): Promise<void> {
  await apiClient.delete(
    `/api/surgeons/${id}/site-memberships/${membershipId}`,
  );
}
