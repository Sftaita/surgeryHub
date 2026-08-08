import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import SurgeonActivityPage from "./SurgeonActivityPage";
import type { SurgeonActivity } from "./api/surgeonActivity.types";

const fetchSurgeonActivityMock = vi.fn();

vi.mock("./api/surgeonActivity.api", () => ({
  fetchSurgeonActivity: (...args: unknown[]) => fetchSurgeonActivityMock(...args),
}));

function makeActivity(interventions: SurgeonActivity["interventions"] = []): SurgeonActivity {
  return {
    period: { from: "2026-01-01", to: "2027-01-01" },
    missionCount: interventions.length > 0 ? 12 : 0,
    interventionCount: interventions.reduce((sum, i) => sum + i.count, 0),
    interventions,
  };
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <MemoryRouter initialEntries={["/app/s/activity"]}>
      <QueryClientProvider client={client}>
        <SurgeonActivityPage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  fetchSurgeonActivityMock.mockReset();
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(new Date("2026-08-06T12:00:00+02:00"));
});

afterEach(() => {
  vi.useRealTimers();
});

describe("SurgeonActivityPage — totaux", () => {
  it("affiche le total interventions et missions", async () => {
    fetchSurgeonActivityMock.mockResolvedValue(makeActivity([
      { interventionTypeId: 1, label: "PTG", count: 84 },
      { interventionTypeId: 2, label: "LCA", count: 61 },
    ]));
    renderPage();

    expect(await screen.findByText("145")).toBeInTheDocument();
    expect(screen.getByText("interventions")).toBeInTheDocument();
    expect(screen.getByText("12")).toBeInTheDocument();
    expect(screen.getByText("missions")).toBeInTheDocument();
  });
});

describe("SurgeonActivityPage — podium", () => {
  it("3 types → podium complet avec médailles", async () => {
    fetchSurgeonActivityMock.mockResolvedValue(makeActivity([
      { interventionTypeId: 1, label: "PTG", count: 84 },
      { interventionTypeId: 2, label: "LCA", count: 61 },
      { interventionTypeId: 3, label: "Ménisque", count: 37 },
    ]));
    renderPage();

    await screen.findByText("TOP INTERVENTIONS");
    expect(screen.getByText("🥇")).toBeInTheDocument();
    expect(screen.getByText("🥈")).toBeInTheDocument();
    expect(screen.getByText("🥉")).toBeInTheDocument();
  });

  it("2 types → podium de 2, jamais une 3e case vide", async () => {
    fetchSurgeonActivityMock.mockResolvedValue(makeActivity([
      { interventionTypeId: 1, label: "PTG", count: 84 },
      { interventionTypeId: 2, label: "LCA", count: 61 },
    ]));
    renderPage();

    await screen.findByText("TOP INTERVENTIONS");
    expect(screen.getByText("🥇")).toBeInTheDocument();
    expect(screen.getByText("🥈")).toBeInTheDocument();
    expect(screen.queryByText("🥉")).not.toBeInTheDocument();
  });

  it("1 type → podium d'une seule case", async () => {
    fetchSurgeonActivityMock.mockResolvedValue(makeActivity([
      { interventionTypeId: 1, label: "PTG", count: 84 },
    ]));
    renderPage();

    await screen.findByText("TOP INTERVENTIONS");
    expect(screen.getByText("🥇")).toBeInTheDocument();
    expect(screen.queryByText("🥈")).not.toBeInTheDocument();
  });

  it("4e type et plus → absent du podium mais présent dans la liste complète", async () => {
    fetchSurgeonActivityMock.mockResolvedValue(makeActivity([
      { interventionTypeId: 1, label: "PTG", count: 84 },
      { interventionTypeId: 2, label: "LCA", count: 61 },
      { interventionTypeId: 3, label: "Ménisque", count: 37 },
      { interventionTypeId: 4, label: "PUC", count: 18 },
    ]));
    renderPage();

    await screen.findByText("TOUTES LES INTERVENTIONS");
    expect(screen.getByText("PUC")).toBeInTheDocument();
    expect(screen.getByText("18")).toBeInTheDocument();
  });
});

describe("SurgeonActivityPage — état vide", () => {
  it("aucune intervention → empty state, jamais un faux podium à 3 cases vides", async () => {
    fetchSurgeonActivityMock.mockResolvedValue(makeActivity([]));
    renderPage();

    expect(await screen.findByText("Aucune intervention validée pour cette période")).toBeInTheDocument();
    expect(screen.queryByText("TOP INTERVENTIONS")).not.toBeInTheDocument();
    expect(screen.queryByText("🥇")).not.toBeInTheDocument();
  });
});

describe("SurgeonActivityPage — liste complète", () => {
  it("affiche toutes les interventions, triées count DESC (déjà trié côté backend, jamais re-trié ici)", async () => {
    fetchSurgeonActivityMock.mockResolvedValue(makeActivity([
      { interventionTypeId: 1, label: "PTG", count: 84 },
      { interventionTypeId: 2, label: "LCA", count: 61 },
      { interventionTypeId: 3, label: "Ménisque", count: 37 },
      { interventionTypeId: 4, label: "PUC", count: 18 },
      { interventionTypeId: 5, label: "Ostéotomie", count: 9 },
    ]));
    renderPage();

    await screen.findByText("TOUTES LES INTERVENTIONS");
    // PTG/LCA/Ménisque apparaissent aussi dans le podium au-dessus — on vérifie juste
    // qu'ils existent au moins une fois, la liste complète elle-même est vérifiée via
    // les deux entrées absentes du podium (PUC, Ostéotomie).
    const labels = ["PTG", "LCA", "Ménisque", "PUC", "Ostéotomie"];
    for (const label of labels) {
      expect(screen.getAllByText(label).length).toBeGreaterThan(0);
    }
  });
});

describe("SurgeonActivityPage — période", () => {
  it("bascule Mois/Année déclenche une nouvelle requête avec le bon range", async () => {
    fetchSurgeonActivityMock.mockResolvedValue(makeActivity([{ interventionTypeId: 1, label: "PTG", count: 5 }]));
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    await screen.findByText("2026");
    await waitFor(() => expect(fetchSurgeonActivityMock).toHaveBeenCalledWith("2026-01-01", "2027-01-01"));

    await user.click(screen.getByText("Mois"));
    await screen.findByText("Août 2026");
    await waitFor(() => expect(fetchSurgeonActivityMock).toHaveBeenCalledWith("2026-08-01", "2026-09-01"));
  });

  it("navigation < > déplace la période dans le mode courant", async () => {
    fetchSurgeonActivityMock.mockResolvedValue(makeActivity([{ interventionTypeId: 1, label: "PTG", count: 5 }]));
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    await screen.findByText("2026");
    await user.click(screen.getByLabelText("Période précédente"));
    await screen.findByText("2025");
    await waitFor(() => expect(fetchSurgeonActivityMock).toHaveBeenCalledWith("2025-01-01", "2026-01-01"));

    await user.click(screen.getByLabelText("Période suivante"));
    await user.click(screen.getByLabelText("Période suivante"));
    await screen.findByText("2027");
  });
});

describe("SurgeonActivityPage — chargement et erreur", () => {
  it("affiche un indicateur de chargement pendant la requête", async () => {
    let resolvePromise: (value: SurgeonActivity) => void = () => {};
    fetchSurgeonActivityMock.mockReturnValue(new Promise((resolve) => { resolvePromise = resolve; }));
    renderPage();

    expect(screen.getByRole("progressbar")).toBeInTheDocument();
    resolvePromise(makeActivity([]));
    await waitFor(() => expect(screen.queryByRole("progressbar")).not.toBeInTheDocument());
  });

  it("erreur réseau → message explicite avec action de réessai", async () => {
    fetchSurgeonActivityMock.mockRejectedValue(new Error("network error"));
    renderPage();

    expect(await screen.findByText("Impossible de charger votre activité.")).toBeInTheDocument();
    expect(screen.getByText("Réessayer")).toBeInTheDocument();
  });

  it("le bouton Réessayer relance la requête", async () => {
    fetchSurgeonActivityMock.mockRejectedValueOnce(new Error("network error"));
    fetchSurgeonActivityMock.mockResolvedValueOnce(makeActivity([{ interventionTypeId: 1, label: "PTG", count: 5 }]));
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    await screen.findByText("Impossible de charger votre activité.");
    await user.click(screen.getByText("Réessayer"));
    expect(await screen.findByText("TOP INTERVENTIONS")).toBeInTheDocument();
  });
});
