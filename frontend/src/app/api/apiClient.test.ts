import { describe, it, expect, vi, beforeEach } from "vitest";
import type { AxiosError } from "axios";

vi.mock("../auth/authApi", () => ({
  refreshTokens: vi.fn(),
}));

import { apiClient } from "./apiClient";
import { refreshTokens } from "../auth/authApi";
import { writeAuth, readAuth } from "../auth/authStorage";

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

  it("ne boucle pas indéfiniment si le refresh échoue : retour 401 propre, tokens nettoyés", async () => {
    (refreshTokens as ReturnType<typeof vi.fn>).mockRejectedValue(
      axiosError(401, "/api/auth/refresh")
    );

    const adapter = vi.fn(async (config: any) => {
      throw axiosError(401, "/api/me", config);
    });
    apiClient.defaults.adapter = adapter;

    await expect(apiClient.get("/api/me")).rejects.toBeTruthy();

    // Un seul appel réseau pour la requête initiale : pas de retry storm.
    expect(adapter).toHaveBeenCalledTimes(1);
    expect(readAuth()).toBeNull();
    expect(sessionStorage.getItem("surgicalhub.auth.sessionExpired")).toBe("1");
  });

  it("ne tente pas de refresh si le 401 provient de l'endpoint de refresh lui-même", async () => {
    apiClient.defaults.adapter = vi.fn(async (config: any) => {
      throw axiosError(401, "/api/auth/refresh", config);
    });

    await expect(apiClient.post("/api/auth/refresh", {})).rejects.toBeTruthy();

    expect(refreshTokens).not.toHaveBeenCalled();
    expect(readAuth()).toBeNull();
  });
});
