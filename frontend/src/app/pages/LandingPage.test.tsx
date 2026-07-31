import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import LandingPage from "./LandingPage";

vi.mock("../auth/AuthContext", () => ({
  useAuth: () => ({ state: { status: "anonymous" } }),
}));

function renderLandingPage() {
  return render(
    <MemoryRouter>
      <LandingPage />
    </MemoryRouter>
  );
}

describe("LandingPage — absence de contenu fictif", () => {
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
});
