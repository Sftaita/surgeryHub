import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import LoginPage from "./LoginPage";

/** D-157 — « Rester connecté sur cet appareil », message d'expiration réelle, serveur injoignable. */
let mockState: { status: string } = { status: "anonymous" };
const login = vi.fn().mockResolvedValue(undefined);
let expired = false;

vi.mock("../auth/AuthContext", () => ({
  useAuth: () => ({ state: mockState, login, retryRestore: vi.fn() }),
}));
vi.mock("../auth/authStorage", () => ({
  consumeSessionExpired: () => expired,
}));
vi.mock("../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}));

function renderLogin() {
  return render(
    <MemoryRouter initialEntries={["/login"]}>
      <LoginPage />
    </MemoryRouter>,
  );
}

describe("LoginPage — session persistante (D-157)", () => {
  beforeEach(() => {
    mockState = { status: "anonymous" };
    expired = false;
    login.mockClear();
  });

  it("« Rester connecté sur cet appareil » est coché par défaut et transmis au login", async () => {
    renderLogin();
    const box = screen.getByRole("checkbox", { name: "Rester connecté sur cet appareil" });
    expect(box).toHaveAttribute("aria-checked", "true");
    expect(screen.getByText(/au moins une fois tous les 30 jours/)).toBeInTheDocument();

    fireEvent.change(screen.getByPlaceholderText("votre@email.com"), { target: { value: "a@b.c" } });
    fireEvent.change(screen.getByPlaceholderText("••••••••"), { target: { value: "pw" } });
    fireEvent.click(screen.getByRole("button", { name: /Se connecter/ }));

    expect(login).toHaveBeenCalledWith("a@b.c", "pw", true);
  });

  it("décoché (appareil partagé) : explication claire et login sans persistance", () => {
    renderLogin();
    fireEvent.click(screen.getByRole("checkbox", { name: "Rester connecté sur cet appareil" }));

    expect(screen.getByText(/la session se fermera à la fermeture du navigateur/)).toBeInTheDocument();
    fireEvent.change(screen.getByPlaceholderText("votre@email.com"), { target: { value: "a@b.c" } });
    fireEvent.change(screen.getByPlaceholderText("••••••••"), { target: { value: "pw" } });
    fireEvent.click(screen.getByRole("button", { name: /Se connecter/ }));

    expect(login).toHaveBeenCalledWith("a@b.c", "pw", false);
  });

  it("après une expiration réelle : « Votre session a expiré. Veuillez vous reconnecter. »", () => {
    expired = true;
    renderLogin();
    expect(screen.getByText("Votre session a expiré. Veuillez vous reconnecter.")).toBeInTheDocument();
  });

  it("serveur injoignable avec une session stockée : pas de formulaire, écran de reconnexion", () => {
    mockState = { status: "unavailable" };
    renderLogin();
    expect(screen.getByText("Connexion au serveur impossible")).toBeInTheDocument();
    expect(screen.queryByPlaceholderText("votre@email.com")).not.toBeInTheDocument();
  });
});
