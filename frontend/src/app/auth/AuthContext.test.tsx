import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, act } from "@testing-library/react";
import { AuthProvider, useAuth } from "./AuthContext";
import { writeAuth, readAuth } from "./authStorage";

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

import { apiClient } from "../api/apiClient";
import { loginRequest, logoutRequest } from "./authApi";

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
});
