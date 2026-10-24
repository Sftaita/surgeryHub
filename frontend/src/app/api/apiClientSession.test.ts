import { describe, it, expect, vi, beforeEach } from "vitest";
import type { AxiosError } from "axios";

vi.mock("../auth/authApi", () => ({
  refreshTokens: vi.fn(),
}));

import { apiClient } from "./apiClient";
import { refreshTokens } from "../auth/authApi";
import { AUTH_STORAGE_KEY, writeAuth, readAuth, isPersistentAuth } from "../auth/authStorage";
import { SESSION_EXPIRED_EVENT } from "../auth/sessionExpiredEvent";

/** D-157 — interceptor rules that keep an active session alive (see also apiClient.test.ts). */
function httpError(status: number, config: any): AxiosError {
  const error = new Error(`Request failed with status ${status}`) as AxiosError;
  error.config = { headers: {}, ...config };
  error.response = { status, data: {}, headers: {}, config: error.config, statusText: "" } as any;
  error.isAxiosError = true;
  return error;
}

const refresh = refreshTokens as ReturnType<typeof vi.fn>;

describe("apiClient — session persistante (D-157)", () => {
  const expired = vi.fn();
  const onExpired = () => expired();

  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
    vi.clearAllMocks();
    writeAuth({ accessToken: "old-access", refreshToken: "old-refresh" }, true);
    window.addEventListener(SESSION_EXPIRED_EVENT, onExpired);
    return () => window.removeEventListener(SESSION_EXPIRED_EVENT, onExpired);
  });

  it("n'envoie jamais le jeton d'accès (souvent expiré) aux endpoints /api/auth/*", async () => {
    const seen: Record<string, unknown> = {};
    apiClient.defaults.adapter = vi.fn(async (config: any) => {
      seen[config.url] = config.headers?.Authorization;
      return { data: {}, status: 200, statusText: "OK", headers: {}, config };
    });

    await apiClient.post("/api/auth/refresh", { refresh_token: "old-refresh" });
    await apiClient.post("/api/auth/logout", { refresh_token: "old-refresh" });
    await apiClient.post("/api/auth/login", {});
    await apiClient.get("/api/me");

    expect(seen["/api/auth/refresh"]).toBeUndefined();
    expect(seen["/api/auth/logout"]).toBeUndefined();
    expect(seen["/api/auth/login"]).toBeUndefined();
    expect(seen["/api/me"]).toBe("Bearer old-access");
  });

  it("un 401 sur /api/auth/login (identifiants invalides) ne tente aucun refresh et ne touche pas la session", async () => {
    apiClient.defaults.adapter = vi.fn(async (config: any) => {
      throw httpError(401, config);
    });

    await expect(apiClient.post("/api/auth/login", {})).rejects.toBeTruthy();

    expect(refresh).not.toHaveBeenCalled();
    expect(readAuth()?.refreshToken).toBe("old-refresh");
    expect(expired).not.toHaveBeenCalled();
  });

  it.each([403, 500, 503])("une erreur %i ne déconnecte jamais", async (code) => {
    apiClient.defaults.adapter = vi.fn(async (config: any) => {
      throw httpError(code, config);
    });

    await expect(apiClient.get("/api/missions")).rejects.toBeTruthy();

    expect(refresh).not.toHaveBeenCalled();
    expect(readAuth()).not.toBeNull();
    expect(expired).not.toHaveBeenCalled();
  });

  it("timeout / réseau coupé sur une requête métier : session conservée", async () => {
    apiClient.defaults.adapter = vi.fn(async () => {
      const e = new Error("timeout of 10000ms exceeded") as AxiosError;
      e.isAxiosError = true;
      e.code = "ECONNABORTED";
      throw e;
    });

    await expect(apiClient.get("/api/missions")).rejects.toBeTruthy();
    expect(readAuth()).not.toBeNull();
    expect(expired).not.toHaveBeenCalled();
  });

  it("JWT déjà renouvelé par un autre onglet pendant la requête : rejouée avec le jeton courant, sans refresh", async () => {
    let call = 0;
    apiClient.defaults.adapter = vi.fn(async (config: any) => {
      call += 1;
      if (call === 1) {
        // Another tab rotated the session while this request was in flight.
        writeAuth({ accessToken: "tab2-access", refreshToken: "tab2-refresh" });
        throw httpError(401, config);
      }
      return { data: { auth: config.headers.Authorization }, status: 200, statusText: "OK", headers: {}, config };
    });

    const res = await apiClient.get("/api/me");

    expect(res.data).toEqual({ auth: "Bearer tab2-access" });
    expect(refresh).not.toHaveBeenCalled();
  });

  it("trois requêtes simultanées avec JWT expiré : un seul refresh, toutes rejouées", async () => {
    let release: (t: { accessToken: string; refreshToken: string }) => void = () => {};
    refresh.mockReturnValue(new Promise((r) => { release = r; }));
    apiClient.defaults.adapter = vi.fn(async (config: any) => {
      if (config.headers.Authorization === "Bearer old-access") throw httpError(401, config);
      return { data: config.url, status: 200, statusText: "OK", headers: {}, config };
    });

    const all = Promise.all(["/api/me", "/api/missions", "/api/notifications/unread-count"].map((u) => apiClient.get(u)));
    await new Promise((r) => setTimeout(r, 0));
    release({ accessToken: "new-access", refreshToken: "new-refresh" });

    expect((await all).map((r) => r.data)).toEqual(["/api/me", "/api/missions", "/api/notifications/unread-count"]);
    expect(refresh).toHaveBeenCalledTimes(1);
    expect(readAuth()).toEqual({ accessToken: "new-access", refreshToken: "new-refresh" });
  });

  it("la rotation du refresh token garde le type de session (appareil partagé → liée au navigateur)", async () => {
    localStorage.clear();
    writeAuth({ accessToken: "old-access", refreshToken: "old-refresh" }, false);
    refresh.mockResolvedValue({ accessToken: "new-access", refreshToken: "new-refresh" });
    apiClient.defaults.adapter = vi.fn(async (config: any) => {
      if (config.headers.Authorization === "Bearer old-access") throw httpError(401, config);
      return { data: {}, status: 200, statusText: "OK", headers: {}, config };
    });

    await apiClient.get("/api/me");

    const stored = JSON.parse(localStorage.getItem(AUTH_STORAGE_KEY) ?? "{}");
    expect(stored.refreshToken).toBe("new-refresh");
    expect(stored.browserSession).toBeTruthy();
    expect(isPersistentAuth()).toBe(false);
  });

  it("refresh refusé (401) pour un ancien jeton alors qu'un autre onglet a déjà une session plus récente : rien n'est effacé", async () => {
    apiClient.defaults.adapter = vi.fn(async (config: any) => {
      writeAuth({ accessToken: "tab2-access", refreshToken: "tab2-refresh" });
      throw httpError(401, config);
    });

    await expect(apiClient.post("/api/auth/refresh", { refresh_token: "old-refresh" })).rejects.toBeTruthy();

    expect(readAuth()?.refreshToken).toBe("tab2-refresh");
    expect(expired).not.toHaveBeenCalled();
  });

  it("refresh refusé (401) pour le jeton courant : session réellement expirée, message affiché sur /login", async () => {
    apiClient.defaults.adapter = vi.fn(async (config: any) => {
      throw httpError(401, config);
    });

    await expect(apiClient.post("/api/auth/refresh", { refresh_token: "old-refresh" })).rejects.toBeTruthy();

    expect(readAuth()).toBeNull();
    expect(sessionStorage.getItem("surgicalhub.auth.sessionExpired")).toBe("1");
    expect(expired).toHaveBeenCalledTimes(1);
  });
});
