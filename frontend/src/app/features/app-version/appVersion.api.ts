import { apiClient } from "../../api/apiClient";

/** D-162 — `GET /api/version` : version réellement exécutée, jamais mise en cache. */
export type AppVersion = {
  application: string;
  status: "OFFICIAL" | "PENDING_VALIDATION" | "DEVELOPMENT";
  version: string | null;
  commit: string | null;
};

export async function fetchAppVersion(): Promise<AppVersion> {
  const { data } = await apiClient.get<AppVersion>("/api/version");
  return data;
}
