import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor, act } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { AuthProvider, useAuth } from "./AuthContext";
import { AUTH_STORAGE_KEY, readAuth, writeAuth } from "./authStorage";
import { RequireAuth } from "../router/RequireAuth";

/**
 * D-157 — session persistence on the client: a stored session is never dropped because the server
 * was unreachable, the storage follows "Rester connecté sur cet appareil", other tabs follow a
 * logout, and no transient /login redirect happens while the session is being restored.
 */
vi.mock("../api/apiClient", () => ({
  apiClient: { get: vi.fn(), post: vi.fn() },
}));
vi.mock("./authApi", () => ({ loginRequest: vi.fn(), logoutRequest: vi.fn() }));
vi.mock("../features/push/pushSubscriptionClient", () => ({
  detachCurrentPushSubscription: vi.fn().mockResolvedValue(undefined),
}));

import { apiClient } from "../api/apiClient";
import { loginRequest } from "./authApi";

const me = { data: { id: 7, role: "MANAGER", sites: [] } };
const get = apiClient.get as ReturnType<typeof vi.fn>;

function networkError() {
  const e = new Error("Network Error") as Error & { response?: unknown };
  e.response = undefined;
  return e;
}

function Probe({ remember = true }: { remember?: boolean }) {
  const { state, login } = useAuth();
  return (
    <div>
      <span data-testid="status">{state.status}</span>
      <button onClick={() => login("user@example.com", "secret", remember)}>login</button>
    </div>
  );
}

const status = () => screen.getByTestId("status").textContent;

describe("AuthContext — persistance de session (D-157)", () => {
  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
    vi.clearAllMocks();
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it("réseau coupé au lancement (PWA hors ligne) : la session est conservée, état « unavailable », puis restaurée au retour du réseau", async () => {
    writeAuth({ accessToken: "a", refreshToken: "r" }, true);
    get.mockRejectedValueOnce(networkError()).mockResolvedValue(me);

    render(<AuthProvider><Probe /></AuthProvider>);

    await waitFor(() => expect(status()).toBe("unavailable"));
    expect(readAuth()).toEqual({ accessToken: "a", refreshToken: "r" });

    await act(async () => {
      window.dispatchEvent(new Event("online"));
    });
    await waitFor(() => expect(status()).toBe("authenticated"));
  });

  it.each([500, 503])("erreur serveur %i au démarrage : aucune déconnexion, nouvelle tentative automatique", async (code) => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    writeAuth({ accessToken: "a", refreshToken: "r" }, true);
    get.mockRejectedValueOnce(Object.assign(new Error(String(code)), { response: { status: code } })).mockResolvedValue(me);

    render(<AuthProvider><Probe /></AuthProvider>);
    await waitFor(() => expect(status()).toBe("unavailable"));
    expect(readAuth()).not.toBeNull();

    await act(async () => {
      vi.advanceTimersByTime(3_100);
    });
    await waitFor(() => expect(status()).toBe("authenticated"));
  });

  it("serveur indisponible plusieurs minutes : les tentatives continuent (3 s, 10 s, 30 s…) jusqu'au retour du serveur", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    writeAuth({ accessToken: "a", refreshToken: "r" }, true);
    get
      .mockRejectedValueOnce(networkError()) // lancement
      .mockRejectedValueOnce(networkError()) // +3 s
      .mockRejectedValueOnce(networkError()) // +10 s
      .mockResolvedValue(me); // +30 s

    render(<AuthProvider><Probe /></AuthProvider>);
    await waitFor(() => expect(status()).toBe("unavailable"));

    for (const ms of [3_100, 10_100]) {
      await act(async () => {
        vi.advanceTimersByTime(ms);
      });
      expect(status()).toBe("unavailable");
    }
    await act(async () => {
      vi.advanceTimersByTime(30_100);
    });
    await waitFor(() => expect(status()).toBe("authenticated"));
    expect(get).toHaveBeenCalledTimes(4);
  });

  it("aucune redirection vers /login pendant la restauration ni quand le serveur est injoignable", async () => {
    writeAuth({ accessToken: "a", refreshToken: "r" }, true);
    get.mockRejectedValue(networkError());

    render(
      <MemoryRouter initialEntries={["/app/m/dashboard"]}>
        <AuthProvider>
          <Routes>
            <Route path="/login" element={<div>LOGIN PAGE</div>} />
            <Route element={<RequireAuth />}>
              <Route path="/app/m/dashboard" element={<div>DASHBOARD</div>} />
            </Route>
          </Routes>
        </AuthProvider>
      </MemoryRouter>,
    );

    expect(screen.getByText("Chargement…")).toBeInTheDocument();
    await waitFor(() => expect(screen.getByText("Connexion au serveur impossible")).toBeInTheDocument());
    expect(screen.queryByText("LOGIN PAGE")).not.toBeInTheDocument();
  });

  it("« Rester connecté » coché : session en localStorage (survit à la fermeture du navigateur)", async () => {
    (loginRequest as ReturnType<typeof vi.fn>).mockResolvedValue({ accessToken: "a", refreshToken: "r" });
    get.mockResolvedValue(me);

    render(<AuthProvider><Probe remember /></AuthProvider>);
    await act(async () => screen.getByText("login").click());

    await waitFor(() => expect(status()).toBe("authenticated"));
    expect(localStorage.getItem(AUTH_STORAGE_KEY)).not.toBeNull();
    expect(sessionStorage.getItem(AUTH_STORAGE_KEY)).toBeNull();
  });

  it("appareil partagé (non coché) : session en sessionStorage seulement — fermer le navigateur la termine", async () => {
    (loginRequest as ReturnType<typeof vi.fn>).mockResolvedValue({ accessToken: "a", refreshToken: "r" });
    get.mockResolvedValue(me);

    render(<AuthProvider><Probe remember={false} /></AuthProvider>);
    await act(async () => screen.getByText("login").click());

    await waitFor(() => expect(status()).toBe("authenticated"));
    expect(sessionStorage.getItem(AUTH_STORAGE_KEY)).not.toBeNull();
    expect(localStorage.getItem(AUTH_STORAGE_KEY)).toBeNull();
    expect(loginRequest).toHaveBeenCalledWith("user@example.com", "secret", false);

    // Simulated browser restart: sessionStorage is gone, nothing persistent remains.
    sessionStorage.clear();
    expect(readAuth()).toBeNull();
  });

  it("réouverture du navigateur / de la PWA : la session persistante est restaurée sans formulaire", async () => {
    writeAuth({ accessToken: "a", refreshToken: "r" }, true);
    get.mockResolvedValue(me);

    render(<AuthProvider><Probe /></AuthProvider>);

    expect(status()).toBe("initializing");
    await waitFor(() => expect(status()).toBe("authenticated"));
  });

  it("déconnexion dans un autre onglet : cet onglet repasse anonyme", async () => {
    writeAuth({ accessToken: "a", refreshToken: "r" }, true);
    get.mockResolvedValue(me);
    render(<AuthProvider><Probe /></AuthProvider>);
    await waitFor(() => expect(status()).toBe("authenticated"));

    await act(async () => {
      localStorage.removeItem(AUTH_STORAGE_KEY);
      window.dispatchEvent(new StorageEvent("storage", { key: AUTH_STORAGE_KEY, newValue: null }));
    });

    expect(status()).toBe("anonymous");
  });

  it("connexion dans un autre onglet : l'onglet resté sur /login restaure la session", async () => {
    get.mockResolvedValue(me);
    render(<AuthProvider><Probe /></AuthProvider>);
    expect(status()).toBe("anonymous");

    await act(async () => {
      writeAuth({ accessToken: "a2", refreshToken: "r2" }, true);
      window.dispatchEvent(new StorageEvent("storage", { key: AUTH_STORAGE_KEY, newValue: "x" }));
    });

    await waitFor(() => expect(status()).toBe("authenticated"));
  });
});
