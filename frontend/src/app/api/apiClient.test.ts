import { describe, it, expect, vi, beforeEach } from "vitest";
import type { AxiosError } from "axios";

vi.mock("../auth/authApi", () => ({
  refreshTokens: vi.fn(),
}));

import { apiClient } from "./apiClient";
import { refreshTokens } from "../auth/authApi";
import { writeAuth, readAuth } from "../auth/authStorage";
import { SESSION_EXPIRED_EVENT } from "../auth/sessionExpiredEvent";

function axiosError(status: number, url: string, config: Record<string, unknown> = {}): AxiosError {
  const error = new Error(`Request failed with status ${status}`) as AxiosError;
  error.config = { url, headers: {}, ...config } as any;
  error.response = { status, data: {}, headers: {}, config: error.config, statusText: "" } as any;
  error.isAxiosError = true;
  return error;
}

describe("apiClient — 401 refresh flow", () => {
  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
    vi.clearAllMocks();
    writeAuth({ accessToken: "old-access", refreshToken: "old-refresh" });
  });

  it("rafraîchit le token et rejoue la requête initiale après un 401", async () => {
    (refreshTokens as ReturnType<typeof vi.fn>).mockResolvedValue({
      accessToken: "new-access",
      refreshToken: "new-refresh",
    });

    let callCount = 0;
    apiClient.defaults.adapter = vi.fn(async (config: any) => {
      callCount += 1;
      if (callCount === 1) {
        throw axiosError(401, "/api/me", config);
      }
      return { data: { id: 1 }, status: 200, statusText: "OK", headers: {}, config };
    });

    const res = await apiClient.get("/api/me");

    expect(res.data).toEqual({ id: 1 });
    expect(refreshTokens).toHaveBeenCalledWith("old-refresh");
    expect(readAuth()?.accessToken).toBe("new-access");
  });

  // Task 11, Cas D — deux requêtes protégées reçoivent un 401 en parallèle : un seul
  // refresh réel doit être exécuté (mutex), les deux requêtes doivent repartir avec le
  // nouveau token une fois ce refresh unique résolu.
  it("un seul refresh réel est exécuté quand deux requêtes reçoivent un 401 en parallèle", async () => {
    let resolveRefresh: (tokens: { accessToken: string; refreshToken: string }) => void = () => {};
    (refreshTokens as ReturnType<typeof vi.fn>).mockReturnValue(
      new Promise((resolve) => { resolveRefresh = resolve; }),
    );

    let call = 0;
    apiClient.defaults.adapter = vi.fn(async (config: any) => {
      call += 1;
      // Les deux requêtes initiales (call 1 et 2) échouent en 401 ; les deux retries
      // (après refresh) réussissent.
      if (call === 1 || call === 2) {
        throw axiosError(401, config.url, config);
      }
      return { data: { ok: true, url: config.url }, status: 200, statusText: "OK", headers: {}, config };
    });

    const p1 = apiClient.get("/api/me");
    const p2 = apiClient.get("/api/notifications");

    // Laisse les deux 401 initiaux être traités et le refresh démarrer avant de le résoudre.
    await new Promise((r) => setTimeout(r, 0));
    resolveRefresh({ accessToken: "new-access", refreshToken: "new-refresh" });

    const [r1, r2] = await Promise.all([p1, p2]);

    expect(r1.data).toEqual({ ok: true, url: "/api/me" });
    expect(r2.data).toEqual({ ok: true, url: "/api/notifications" });
    expect(refreshTokens).toHaveBeenCalledTimes(1);
    expect(readAuth()?.accessToken).toBe("new-access");
  });

  it("ne boucle pas indéfiniment si le refresh échoue : retour 401 propre, tokens nettoyés", async () => {
    (refreshTokens as ReturnType<typeof vi.fn>).mockRejectedValue(
      axiosError(401, "/api/auth/refresh")
    );

    const adapter = vi.fn(async (config: any) => {
      throw axiosError(401, "/api/me", config);
    });
    apiClient.defaults.adapter = adapter;

    const onSessionExpired = vi.fn();
    window.addEventListener(SESSION_EXPIRED_EVENT, onSessionExpired);

    await expect(apiClient.get("/api/me")).rejects.toBeTruthy();

    // Un seul appel réseau pour la requête initiale : pas de retry storm.
    expect(adapter).toHaveBeenCalledTimes(1);
    expect(readAuth()).toBeNull();
    expect(sessionStorage.getItem("surgicalhub.auth.sessionExpired")).toBe("1");
    // AuthContext listens for this to flip to "anonymous" immediately — without it, a
    // background mutation's definitive 401 clears tokens but nothing redirects to /login.
    expect(onSessionExpired).toHaveBeenCalledTimes(1);

    window.removeEventListener(SESSION_EXPIRED_EVENT, onSessionExpired);
  });

  it("ne tente pas de refresh si le 401 provient de l'endpoint de refresh lui-même", async () => {
    apiClient.defaults.adapter = vi.fn(async (config: any) => {
      throw axiosError(401, "/api/auth/refresh", config);
    });

    const onSessionExpired = vi.fn();
    window.addEventListener(SESSION_EXPIRED_EVENT, onSessionExpired);

    await expect(apiClient.post("/api/auth/refresh", {})).rejects.toBeTruthy();

    expect(refreshTokens).not.toHaveBeenCalled();
    expect(readAuth()).toBeNull();
    expect(onSessionExpired).toHaveBeenCalledTimes(1);

    window.removeEventListener(SESSION_EXPIRED_EVENT, onSessionExpired);
  });

  // Task 11 — re-audit Remember Me : cause racine réelle du bug "déconnexions fréquentes"
  // (reproductible sur Chrome, pas seulement Safari/localStorage). Avant ce correctif,
  // toute erreur pendant l'appel réseau de refresh (pas seulement un vrai 401) effaçait
  // les tokens et déconnectait l'utilisateur — y compris une simple coupure Wi-Fi
  // transitoire pendant que le refresh token restait parfaitement valide.
  it("ne déconnecte PAS l'utilisateur si l'appel de refresh échoue pour une raison réseau/timeout transitoire", async () => {
    const networkError = new Error("Network Error") as AxiosError;
    networkError.isAxiosError = true;
    networkError.response = undefined; // pas de réponse HTTP du tout — coupure réseau/timeout
    (refreshTokens as ReturnType<typeof vi.fn>).mockRejectedValue(networkError);

    const adapter = vi.fn(async (config: any) => {
      throw axiosError(401, "/api/me", config);
    });
    apiClient.defaults.adapter = adapter;

    const onSessionExpired = vi.fn();
    window.addEventListener(SESSION_EXPIRED_EVENT, onSessionExpired);

    await expect(apiClient.get("/api/me")).rejects.toBeTruthy();

    // Le refresh token stocké doit rester intact — une prochaine requête pourra retenter
    // un refresh normalement, l'utilisateur n'a jamais été réellement déconnecté.
    expect(readAuth()?.refreshToken).toBe("old-refresh");
    expect(sessionStorage.getItem("surgicalhub.auth.sessionExpired")).toBeNull();
    expect(onSessionExpired).not.toHaveBeenCalled();

    window.removeEventListener(SESSION_EXPIRED_EVENT, onSessionExpired);
  });

  it("émet l'événement de session expirée quand aucun refresh token n'est disponible", async () => {
    localStorage.clear(); // no stored refresh token at all
    apiClient.defaults.adapter = vi.fn(async (config: any) => {
      throw axiosError(401, "/api/me", config);
    });

    const onSessionExpired = vi.fn();
    window.addEventListener(SESSION_EXPIRED_EVENT, onSessionExpired);

    await expect(apiClient.get("/api/me")).rejects.toBeTruthy();

    expect(refreshTokens).not.toHaveBeenCalled();
    expect(onSessionExpired).toHaveBeenCalledTimes(1);

    window.removeEventListener(SESSION_EXPIRED_EVENT, onSessionExpired);
  });
});
