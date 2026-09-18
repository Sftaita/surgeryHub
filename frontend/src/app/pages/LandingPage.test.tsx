import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import LandingPage from "./LandingPage";

let mockAuthStatus: "initializing" | "anonymous" | "loading" | "authenticated" = "anonymous";
vi.mock("../auth/AuthContext", () => ({
  useAuth: () => ({ state: { status: mockAuthStatus } }),
}));

function renderLandingPage() {
  return render(
    <MemoryRouter>
      <LandingPage />
    </MemoryRouter>
  );
}

describe("LandingPage — absence de contenu fictif", () => {
  beforeEach(() => {
    mockAuthStatus = "anonymous";
  });

  it("n'affiche aucune des statistiques inventées précédemment retirées", () => {
    renderLandingPage();
    const text = document.body.textContent ?? "";
    expect(text).not.toMatch(/180\+/);
    expect(text).not.toMatch(/45\+/);
    expect(text).not.toMatch(/2\s?400\+/);
    expect(text).not.toMatch(/98\s?%/);
  });

  it("n'affiche aucun des témoignages fictifs précédemment retirés", () => {
    renderLandingPage();
    const text = document.body.textContent ?? "";
    expect(text).not.toMatch(/Sophie M\./);
    expect(text).not.toMatch(/Thomas L\./);
    expect(text).not.toMatch(/Alexia P\./);
    expect(text).not.toMatch(/Clinique Saint-Jean/);
  });

  it("n'affiche pas de numéro de téléphone ou de TVA placeholder", () => {
    renderLandingPage();
    const text = document.body.textContent ?? "";
    expect(text).not.toMatch(/\+32 2 000 00 00/);
    expect(text).not.toMatch(/BE0XXX/);
    expect(text).not.toMatch(/Agréé INAMI/);
  });

  it("affiche une présentation factuelle des fonctionnalités réelles", () => {
    renderLandingPage();
    expect(screen.getByText(/Mise en relation ciblée/i)).toBeInTheDocument();
    expect(screen.getByText(/Publication de besoins de couverture/i)).toBeInTheDocument();
    expect(screen.getByText(/Consultation des offres de mission/i)).toBeInTheDocument();
  });

  it("expose un menu mobile (burger) pour la navigation responsive", () => {
    renderLandingPage();
    expect(screen.getByRole("button", { name: /Ouvrir le menu/i })).toBeInTheDocument();
  });

  it("affiche le logo officiel partagé (même asset que LoginPage/MobileLayout), jamais un SVG réinventé localement", () => {
    renderLandingPage();
    const logos = screen.getAllByAltText("SurgeryHub");
    expect(logos.length).toBeGreaterThanOrEqual(2); // navbar + footer
    logos.forEach((img) => expect(img).toHaveAttribute("src", "/logo-mark-transparent.png"));
  });
});

/**
 * Correctif PWA / Se souvenir de moi (2026-09-18) — pendant le bootstrap d'une session
 * stockée ("initializing", même traitement que "loading"), la home publique ne doit rien
 * afficher : sinon un utilisateur avec une session valide qui atterrit un instant sur "/"
 * (avant que LoginPage ne redirige directement vers son dashboard) verrait la page
 * marketing flasher.
 */
describe("LandingPage — aucun flash pendant le bootstrap de session", () => {
  it("n'affiche rien tant que le statut est \"initializing\"", () => {
    mockAuthStatus = "initializing";
    renderLandingPage();

    expect(document.body.textContent).toBe("");
  });

  it("n'affiche rien tant que le statut est \"loading\"", () => {
    mockAuthStatus = "loading";
    renderLandingPage();

    expect(document.body.textContent).toBe("");
  });
});
