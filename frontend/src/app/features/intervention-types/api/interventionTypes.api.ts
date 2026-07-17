import { apiClient } from "../../../api/apiClient";

export interface InterventionType {
  id: number;
  code: string;
  label: string;
  specialty: string | null;
  active: boolean;
}

export async function getInterventionTypes(activeOnly = false): Promise<InterventionType[]> {
  const res = await apiClient.get("/api/intervention-types", {
    params: activeOnly ? { active: true } : undefined,
  });
  return res.data;
}

export async function createInterventionType(body: {
  code: string;
  label: string;
  specialty?: string;
}): Promise<InterventionType> {
  const res = await apiClient.post("/api/intervention-types", body);
  return res.data;
}

export async function updateInterventionType(
  id: number,
  body: { label?: string; specialty?: string | null; active?: boolean },
): Promise<InterventionType> {
  const res = await apiClient.patch(`/api/intervention-types/${id}`, body);
  return res.data;
}

export async function deleteInterventionType(id: number): Promise<void> {
  await apiClient.delete(`/api/intervention-types/${id}`);
}
