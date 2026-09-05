import { describe, it, expect, vi, beforeEach } from "vitest";
import { render } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import LoginPage from "./LoginPage";

const mockNavigate = vi.fn();
vi.mock("react-router-dom", async () => {
  const actual = await vi.importActual<typeof import("react-router-dom")>("react-router-dom");
  return { ...actual, useNavigate: () => mockNavigate };
});

let authStatus: "anonymous" | "loading" | "authenticated" = "authenticated";
vi.mock("../auth/AuthContext", () => ({
  useAuth: () => ({ state: { status: authStatus }, login: vi.fn() }),
}));

vi.mock("../auth/authStorage", () => ({
  consumeSessionExpired: () => false,
}));

vi.mock("../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}));

function renderLoginAt(locationState: unknown) {
  return render(
    <MemoryRouter initialEntries={[{ pathname: "/login", state: locationState }]}>
      <LoginPage />
    </MemoryRouter>,
  );
}

/**
 * Correctif auth/router (2026-09-04) — après authentification, LoginPage doit revenir
 * exactement vers `state.from` (pathname + query string), jamais un pathname tronqué de
 * sa query string, et jamais une valeur qui ne serait pas une route interne de
 * l'application (protection open-redirect). Voir aussi RequireAuth.test.tsx (capture) et
 * safeInternalPath.test.ts (validation).
 */
describe("LoginPage — restauration de la destination post-login", () => {
  beforeEach(() => {
    mockNavigate.mockReset();
    authStatus = "authenticated";
  });

  it("restaure exactement pathname + query string (deep-link Demandes Catalogue)", () => {
    renderLoginAt({ from: "/app/m/catalogue/requests?kind=MATERIAL_ITEM&requestId=42" });

    expect(mockNavigate).toHaveBeenCalledWith("/app/m/catalogue/requests?kind=MATERIAL_ITEM&requestId=42", { replace: true });
  });

  it("continue de fonctionner pour une route sans query string", () => {
    renderLoginAt({ from: "/app/m/dashboard" });

    expect(mockNavigate).toHaveBeenCalledWith("/app/m/dashboard", { replace: true });
  });

  it("retombe sur le repli habituel (\"/\") quand from est absent", () => {
    renderLoginAt(null);

    expect(mockNavigate).toHaveBeenCalledWith("/", { replace: true });
  });

  it("rejette une URL externe comme destination de retour et retombe sur le repli habituel", () => {
    renderLoginAt({ from: "https://evil.com/phishing" });

    expect(mockNavigate).toHaveBeenCalledWith("/", { replace: true });
  });

  it("rejette une URL protocole-relative (//evil.com) et retombe sur le repli habituel", () => {
    renderLoginAt({ from: "//evil.com" });

    expect(mockNavigate).toHaveBeenCalledWith("/", { replace: true });
  });

  it("ne navigue pas tant que l'utilisateur n'est pas authentifié", () => {
    authStatus = "anonymous";
    renderLoginAt({ from: "/app/m/catalogue/requests?kind=MATERIAL_ITEM&requestId=42" });

    expect(mockNavigate).not.toHaveBeenCalled();
  });
});
