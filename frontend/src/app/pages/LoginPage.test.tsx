import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import LoginPage from "./LoginPage";

// ── Mocks ─────────────────────────────────────────────────────────────────────

const loginMock = vi.fn().mockResolvedValue(undefined);

vi.mock("../auth/AuthContext", () => ({
  useAuth: () => ({
    state: { status: "anonymous" },
    login: loginMock,
    logout: vi.fn(),
  }),
}));

beforeEach(() => {
  loginMock.mockClear();
  sessionStorage.clear();
});

function renderLoginPage() {
  return render(
    <MemoryRouter>
      <LoginPage />
    </MemoryRouter>
  );
}

describe("LoginPage — Se souvenir de moi", () => {
  it("affiche la checkbox 'Se souvenir de moi'", () => {
    renderLoginPage();
    expect(screen.getByLabelText("Se souvenir de moi")).toBeInTheDocument();
  });

  it("envoie rememberMe=false par défaut", async () => {
    const user = userEvent.setup();
    renderLoginPage();

    await user.type(screen.getByPlaceholderText("vous@surgeryhub.be"), "user@example.com");
    await user.type(screen.getByPlaceholderText("••••••••"), "secret123");
    await user.click(screen.getByRole("button", { name: /Se connecter/i }));

    expect(loginMock).toHaveBeenCalledWith("user@example.com", "secret123", false);
  });

  it("envoie rememberMe=true si la checkbox est cochée", async () => {
    const user = userEvent.setup();
    renderLoginPage();

    await user.type(screen.getByPlaceholderText("vous@surgeryhub.be"), "user@example.com");
    await user.type(screen.getByPlaceholderText("••••••••"), "secret123");
    await user.click(screen.getByLabelText("Se souvenir de moi"));
    await user.click(screen.getByRole("button", { name: /Se connecter/i }));

    expect(loginMock).toHaveBeenCalledWith("user@example.com", "secret123", true);
  });

  it("affiche un message discret si la session a expiré", () => {
    sessionStorage.setItem("surgicalhub.auth.sessionExpired", "1");
    renderLoginPage();

    expect(screen.getByText(/session a expiré/i)).toBeInTheDocument();
    expect(sessionStorage.getItem("surgicalhub.auth.sessionExpired")).toBeNull();
  });
});
