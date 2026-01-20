import { apiClient } from "../../../api/apiClient";

export type Site = {
  id: number;
  name: string;
  address?: string;
  timezone?: string;
};

export async function fetchSites(): Promise<Site[]> {
  const { data } = await apiClient.get<Site[]>("/api/sites");
  return data;
}
