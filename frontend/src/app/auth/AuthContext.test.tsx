import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, act } from "@testing-library/react";
import { AuthProvider, useAuth } from "./AuthContext";
import { writeAuth, readAuth } from "./authStorage";
import { dispatchSessionExpired } from "./sessionExpiredEvent";

vi.mock("../api/apiClient", () => ({
  apiClient: {
    get: vi.fn(),
    post: vi.fn(),
  },
}));

vi.mock("./authApi", () => ({
  loginRequest: vi.fn(),
  logoutRequest: vi.fn(),
}));

vi.mock("../features/push/pushSubscriptionClient", () => ({
  detachCurrentPushSubscription: vi.fn().mockResolvedValue(undefined),
}));

import { apiClient } from "../api/apiClient";
import { loginRequest, logoutRequest } from "./authApi";
import { detachCurrentPushSubscription } from "../features/push/pushSubscriptionClient";

function Probe() {
  const { state, login, logout } = useAuth();
  return (
    <div>
      <span data-testid="status">{state.status}</span>
      <button onClick={() => login("user@example.com", "secret", true)}>login</button>
      <button onClick={logout}>logout</button>
    </div>
  );
}

function renderProbe() {
  return render(
    <AuthProvider>
      <Probe />
    </AuthProvider>
  );
}

describe("AuthContext", () => {
  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
    vi.clearAllMocks();
  });

  it("restaure la session au reload si le refresh token stocké est valide (/api/me OK)", async () => {
    writeAuth({ accessToken: "a", refreshToken: "r" });
    (apiClient.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { id: 1, role: "ADMIN", sites: [] } });

    renderProbe();

    await waitFor(() => expect(screen.getByTestId("status").textContent).toBe("authenticated"));
  });

  it("retourne anonyme si /api/me échoue (refresh invalide)", async () => {
    writeAuth({ accessToken: "a", refreshToken: "r" });
    (apiClient.get as ReturnType<typeof vi.fn>).mockRejectedValue(new Error("401"));

    renderProbe();

    await waitFor(() => expect(screen.getByTestId("status").textContent).toBe("anonymous"));
    expect(readAuth()).toBeNull();
  });

  it("login() transmet rememberMe au backend", async () => {
    (loginRequest as ReturnType<typeof vi.fn>).mockResolvedValue({
      accessToken: "a",
      refreshToken: "r",
    });
    (apiClient.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { id: 1, role: "ADMIN", sites: [] } });

    renderProbe();
    await act(async () => {
      screen.getByText("login").click();
    });

    await waitFor(() => expect(screen.getByTestId("status").textContent).toBe("authenticated"));
    expect(loginRequest).toHaveBeenCalledWith("user@example.com", "secret", true);
  });

  it("logout() invalide le refresh token côté serveur puis nettoie le stockage local", async () => {
    writeAuth({ accessToken: "a", refreshToken: "r" });
    (apiClient.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { id: 1, role: "ADMIN", sites: [] } });

    renderProbe();
    await waitFor(() => expect(screen.getByTestId("status").textContent).toBe("authenticated"));

    await act(async () => {
      screen.getByText("logout").click();
    });

    await waitFor(() => expect(screen.getByTestId("status").textContent).toBe("anonymous"));
    expect(logoutRequest).toHaveBeenCalledWith("r");
    expect(readAuth()).toBeNull();
  });

  it("logout() détache l'abonnement push côté serveur avec le token capturé avant le nettoyage local", async () => {
    // The token must be the one that was valid at logout time — captured synchronously
    // before clearAuth() — not read lazily from storage after it's already gone.
    writeAuth({ accessToken: "push-token-abc", refreshToken: "r" });
    (apiClient.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { id: 1, role: "ADMIN", sites: [] } });

    renderProbe();
    await waitFor(() => expect(screen.getByTestId("status").textContent).toBe("authenticated"));

    await act(async () => {
      screen.getByText("logout").click();
    });

    expect(detachCurrentPushSubscription).toHaveBeenCalledWith("push-token-abc");
  });

  it("logout() ne bloque jamais sur le détachement push (même si le nettoyage local doit se produire avant qu'il ne se résolve)", async () => {
    // Simulates the User A → User B same-device scenario: A's server-side detach may still
    // be in flight, but A's local session must clear immediately regardless.
    let resolveDetach: () => void = () => {};
    (detachCurrentPushSubscription as ReturnType<typeof vi.fn>).mockReturnValue(
      new Promise<void>((resolve) => {
        resolveDetach = resolve;
      }),
    );
    writeAuth({ accessToken: "a", refreshToken: "r" });
    (apiClient.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { id: 1, role: "ADMIN", sites: [] } });

    renderProbe();
    await waitFor(() => expect(screen.getByTestId("status").textContent).toBe("authenticated"));

    await act(async () => {
      screen.getByText("logout").click();
    });

    // Local session is already gone even though the detach promise never resolved.
    expect(screen.getByTestId("status").textContent).toBe("anonymous");
    expect(readAuth()).toBeNull();

    resolveDetach();
  });

  it("passe à anonyme dès que le SESSION_EXPIRED_EVENT est émis en arrière-plan (401 définitif sur une mutation)", async () => {
    // apiClient's interceptor clears storage and dispatches this event on its own, from
    // wherever in the app a background mutation hit a definitive 401 — AuthContext must react
    // immediately (not on next reload) so RequireAuth redirects to /login right away.
    writeAuth({ accessToken: "a", refreshToken: "r" });
    (apiClient.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { id: 1, role: "ADMIN", sites: [] } });

    renderProbe();
    await waitFor(() => expect(screen.getByTestId("status").textContent).toBe("authenticated"));

    act(() => {
      dispatchSessionExpired();
    });

    await waitFor(() => expect(screen.getByTestId("status").textContent).toBe("anonymous"));
  });
});
