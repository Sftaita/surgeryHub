import { apiClient } from "../api/apiClient";

function normalizeTokenPayload(data: any) {
  const accessToken =
    data.accessToken ?? data.access_token ?? data.token ?? null;
  const refreshToken = data.refreshToken ?? data.refresh_token ?? null;

  if (!accessToken) throw new Error("Refresh payload: token manquant");
  if (!refreshToken) throw new Error("Refresh payload: refresh_token manquant");

  return { accessToken, refreshToken };
}

export async function refreshTokens(refreshToken: string) {
  const res = await apiClient.post("/api/auth/refresh", {
    refresh_token: refreshToken,
  });
  return normalizeTokenPayload(res.data);
}
