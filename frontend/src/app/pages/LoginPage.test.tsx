import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import LoginPage from "./LoginPage";
import { homePathForRole } from "../auth/roles";

const mockNavigate = vi.fn();
vi.mock("react-router-dom", async () => {
  const actual = await vi.importActual<typeof import("react-router-dom")>("react-router-dom");
  return { ...actual, useNavigate: () => mockNavigate };
});

type MockAuthState =
  | { status: "initializing" }
  | { status: "anonymous" }
  | { status: "loading" }
  | { status: "authenticated"; user: { id: number; role: string } };

let mockState: MockAuthState = { status: "authenticated", user: { id: 1, role: "MANAGER" } };
vi.mock("../auth/AuthContext", () => ({
  useAuth: () => ({ state: mockState, login: vi.fn() }),
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
 *
 * Correctif PWA / Se souvenir de moi (2026-09-18) — quand aucun deep-link explicite n'a
 * amené l'utilisateur sur /login (cas typique : lancement PWA à froid, start_url=/login),
 * le repli n'est plus "/" (la page publique) mais homePathForRole(role) — la même source
 * de vérité que PostLoginRedirect/les guards — pour éviter un rebond visible par la home
 * publique avant le dashboard réel. Voir aussi AuthContext.test.tsx ("initializing").
 */
describe("LoginPage — restauration de la destination post-login", () => {
  beforeEach(() => {
    mockNavigate.mockReset();
    mockState = { status: "authenticated", user: { id: 1, role: "MANAGER" } };
  });

  it("restaure exactement pathname + query string (deep-link Demandes Catalogue)", () => {
    renderLoginAt({ from: "/app/m/catalogue/requests?kind=MATERIAL_ITEM&requestId=42" });

    expect(mockNavigate).toHaveBeenCalledWith("/app/m/catalogue/requests?kind=MATERIAL_ITEM&requestId=42", { replace: true });
  });

  it("continue de fonctionner pour une route sans query string", () => {
    renderLoginAt({ from: "/app/m/dashboard" });

    expect(mockNavigate).toHaveBeenCalledWith("/app/m/dashboard", { replace: true });
  });

  it("retombe sur le dashboard du rôle (homePathForRole) quand from est absent", () => {
    renderLoginAt(null);

    expect(mockNavigate).toHaveBeenCalledWith(homePathForRole("MANAGER"), { replace: true });
  });

  it("rejette une URL externe comme destination de retour et retombe sur le dashboard du rôle", () => {
    renderLoginAt({ from: "https://evil.com/phishing" });

    expect(mockNavigate).toHaveBeenCalledWith(homePathForRole("MANAGER"), { replace: true });
  });

  it("rejette une URL protocole-relative (//evil.com) et retombe sur le dashboard du rôle", () => {
    renderLoginAt({ from: "//evil.com" });

    expect(mockNavigate).toHaveBeenCalledWith(homePathForRole("MANAGER"), { replace: true });
  });

  it("utilise le dashboard propre au rôle réel (instrumentiste) pour le repli", () => {
    mockState = { status: "authenticated", user: { id: 2, role: "INSTRUMENTIST" } };
    renderLoginAt(null);

    expect(mockNavigate).toHaveBeenCalledWith(homePathForRole("INSTRUMENTIST"), { replace: true });
  });

  it("ne navigue pas tant que l'utilisateur n'est pas authentifié", () => {
    mockState = { status: "anonymous" };
    renderLoginAt({ from: "/app/m/catalogue/requests?kind=MATERIAL_ITEM&requestId=42" });

    expect(mockNavigate).not.toHaveBeenCalled();
  });
});

/**
 * Correctif PWA / Se souvenir de moi (2026-09-18) — pendant que la session stockée est
 * en cours de vérification (bootstrap AuthContext, status "initializing"), le formulaire
 * email/mot de passe ne doit pas s'afficher : une reconnexion automatique en cours
 * afficherait sinon le formulaire l'espace d'une frame avant de rediriger vers le
 * dashboard ("aucun flash login → dashboard").
 */
describe("LoginPage — état d'initialisation (bootstrap de session)", () => {
  beforeEach(() => {
    mockNavigate.mockReset();
  });

  it("n'affiche pas le formulaire de connexion tant que le statut est \"initializing\"", () => {
    mockState = { status: "initializing" };
    renderLoginAt(null);

    expect(screen.queryByPlaceholderText("votre@email.com")).not.toBeInTheDocument();
    expect(mockNavigate).not.toHaveBeenCalled();
  });

  it("affiche le formulaire dès que le statut redevient \"anonymous\" (aucune session à restaurer)", () => {
    mockState = { status: "anonymous" };
    renderLoginAt(null);

    expect(screen.getByPlaceholderText("votre@email.com")).toBeInTheDocument();
  });
});
