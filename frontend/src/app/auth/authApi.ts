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

export async function loginRequest(
  email: string,
  password: string,
  rememberMe: boolean
) {
  const res = await apiClient.post("/api/auth/login", {
    email,
    password,
    rememberMe,
  });
  return normalizeTokenPayload(res.data);
}

export async function logoutRequest(refreshToken: string) {
  // Best-effort : on invalide le refresh token côté serveur, mais on ne bloque
  // jamais la déconnexion locale si l'appel échoue (réseau coupé, etc.).
  try {
    await apiClient.post("/api/auth/logout", { refresh_token: refreshToken });
  } catch {
    // ignore
  }
}
