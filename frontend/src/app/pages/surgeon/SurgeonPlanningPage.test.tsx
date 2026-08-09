import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import SurgeonPlanningPage from "./SurgeonPlanningPage";

/**
 * Planning chirurgien (Lot 2, D-095, §2-7/§15) — réutilise les primitives extraites de
 * l'instrumentiste (planningPrimitives.tsx), jamais une deuxième implémentation de
 * calendrier. Couverture jamais recalculée ici : `mission.covered` vient du backend
 * (PlanningCoverageService::isCovered()). CANCELLED n'est jamais "à couvrir", rendu
 * discret (pastille "Annulée"), visible seulement sous "Toutes".
 */

const fetchMissionsMock = vi.fn();

vi.mock("../../features/missions/api/missions.api", () => ({
  fetchMissions: (...args: unknown[]) => fetchMissionsMock(...args),
}));

vi.mock("../instrumentist/MissionDetailPage", () => ({
  MissionDetailContent: ({ missionId }: { missionId: number }) => <div>détail mission #{missionId}</div>,
}));

function makeMission(overrides: Partial<any> = {}) {
  return {
    id: 1,
    type: "BLOCK",
    status: "ASSIGNED",
    startAt: "2026-08-26T10:00:00+02:00",
    endAt: "2026-08-26T20:00:00+02:00",
    site: { id: 1, name: "CHIREC - Hôpital Delta" },
    instrumentist: { id: 2, firstname: "Salve", lastname: "Decorte", email: "sd@test.be" },
    covered: true,
    allowedActions: [],
    ...overrides,
  };
}

function renderPage(initialEntry = "/planning?view=week&date=2026-08-27&filter=all") {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <QueryClientProvider client={client}>
        <SurgeonPlanningPage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

function renderPageWithRoutes(initialEntry = "/app/s/planning?view=week&date=2026-08-27&filter=all") {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <QueryClientProvider client={client}>
        <Routes>
          <Route path="/app/s/planning" element={<SurgeonPlanningPage />} />
          <Route path="/app/s/mission-requests/new" element={<div>formulaire demande</div>} />
        </Routes>
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  fetchMissionsMock.mockReset();
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(new Date("2026-08-27T12:00:00+02:00"));
});

afterEach(() => {
  vi.useRealTimers();
});

describe("SurgeonPlanningPage — requête self-scoped", () => {
  it("n'envoie jamais assignedToMe (hardcodé sur m.instrumentist côté backend, viderait le résultat pour un chirurgien)", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [] });
    renderPage();

    await waitFor(() => expect(fetchMissionsMock).toHaveBeenCalled());
    const [, , filters] = fetchMissionsMock.mock.calls[0];
    expect(filters).not.toHaveProperty("assignedToMe");
  });
});

describe("SurgeonPlanningPage — vues mois/semaine", () => {
  it("bascule entre Semaine et Mois", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [] });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    await waitFor(() => expect(fetchMissionsMock).toHaveBeenCalled());
    expect(screen.getByRole("button", { name: "Semaine" })).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Mois" }));
    await waitFor(() => expect(screen.getAllByText("L").length).toBeGreaterThan(0));
  });

  it("plusieurs missions le même jour apparaissent comme des lignes distinctes", async () => {
    fetchMissionsMock.mockResolvedValue({
      items: [
        makeMission({ id: 1, startAt: "2026-08-26T10:00:00+02:00", endAt: "2026-08-26T20:00:00+02:00" }),
        makeMission({ id: 2, startAt: "2026-08-26T10:00:00+02:00", endAt: "2026-08-26T15:00:00+02:00" }),
      ],
    });
    renderPage();

    const rows = await screen.findAllByText(/CHIREC - Hôpital Delta/);
    expect(rows).toHaveLength(2);
  });
});

describe("SurgeonPlanningPage — filtre couverture (Toutes/Couvertes/À couvrir)", () => {
  function twoMissions() {
    return [
      makeMission({ id: 1, status: "ASSIGNED", covered: true, site: { id: 1, name: "Site Couvert" } }),
      makeMission({ id: 2, status: "OPEN", instrumentist: null, covered: false, site: { id: 2, name: "Site À Couvrir" } }),
    ];
  }

  it("'Toutes' affiche toutes les missions", async () => {
    fetchMissionsMock.mockResolvedValue({ items: twoMissions() });
    renderPage("/planning?view=week&date=2026-08-27&filter=all");

    expect(await screen.findByText(/Site Couvert/)).toBeInTheDocument();
    expect(screen.getByText(/Site À Couvrir/)).toBeInTheDocument();
  });

  it("'Couvertes' masque les missions non couvertes", async () => {
    fetchMissionsMock.mockResolvedValue({ items: twoMissions() });
    renderPage("/planning?view=week&date=2026-08-27&filter=covered");

    expect(await screen.findByText(/Site Couvert/)).toBeInTheDocument();
    expect(screen.queryByText(/Site À Couvrir/)).not.toBeInTheDocument();
  });

  it("'À couvrir' masque les missions couvertes", async () => {
    fetchMissionsMock.mockResolvedValue({ items: twoMissions() });
    renderPage("/planning?view=week&date=2026-08-27&filter=uncovered");

    expect(await screen.findByText(/Site À Couvrir/)).toBeInTheDocument();
    expect(screen.queryByText(/Site Couvert/)).not.toBeInTheDocument();
  });

  it("clic sur un filtre agit sur le jeu de données déjà chargé, sans nouvelle requête réseau", async () => {
    fetchMissionsMock.mockResolvedValue({ items: twoMissions() });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage("/planning?view=week&date=2026-08-27&filter=all");

    await screen.findByText(/Site Couvert/);
    const callsBefore = fetchMissionsMock.mock.calls.length;

    await user.click(screen.getByRole("button", { name: "À couvrir" }));
    await waitFor(() => expect(screen.queryByText(/Site Couvert/)).not.toBeInTheDocument());

    expect(fetchMissionsMock.mock.calls.length).toBe(callsBefore);
  });
});

describe("SurgeonPlanningPage — missions annulées", () => {
  it("une mission CANCELLED est visible sous 'Toutes' avec un rendu discret (pastille Annulée)", async () => {
    fetchMissionsMock.mockResolvedValue({
      items: [makeMission({ id: 5, status: "CANCELLED", covered: false, site: { id: 5, name: "Site Annulé" } })],
    });
    renderPage("/planning?view=week&date=2026-08-27&filter=all");

    expect(await screen.findByText(/Site Annulé/)).toBeInTheDocument();
    expect(screen.getByText("Annulée")).toBeInTheDocument();
  });

  it("une mission CANCELLED n'est jamais comptée comme 'à couvrir'", async () => {
    fetchMissionsMock.mockResolvedValue({
      items: [makeMission({ id: 5, status: "CANCELLED", covered: false, site: { id: 5, name: "Site Annulé" } })],
    });
    renderPage("/planning?view=week&date=2026-08-27&filter=uncovered");

    expect(await screen.findByText("Aucune mission à couvrir sur cette période")).toBeInTheDocument();
    expect(screen.queryByText(/Site Annulé/)).not.toBeInTheDocument();
  });

  it("une mission CANCELLED n'est jamais comptée comme 'couverte'", async () => {
    fetchMissionsMock.mockResolvedValue({
      items: [makeMission({ id: 5, status: "CANCELLED", covered: false, site: { id: 5, name: "Site Annulé" } })],
    });
    renderPage("/planning?view=week&date=2026-08-27&filter=covered");

    expect(await screen.findByText("Aucune mission couverte sur cette période")).toBeInTheDocument();
    expect(screen.queryByText(/Site Annulé/)).not.toBeInTheDocument();
  });
});

describe("SurgeonPlanningPage — clic mission", () => {
  it("clic sur une ligne mission ouvre le dialogue de détail partagé", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [makeMission({ id: 77 })] });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    await user.click(await screen.findByText(/CHIREC - Hôpital Delta/));
    expect(await screen.findByText("détail mission #77")).toBeInTheDocument();
  });
});

describe("SurgeonPlanningPage — états UX", () => {
  it("aucune mission sur la période → état vide simple", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [] });
    renderPage();

    expect(await screen.findByText("Aucune mission sur cette période")).toBeInTheDocument();
  });

  it("erreur réseau → message d'erreur explicite", async () => {
    fetchMissionsMock.mockRejectedValue(new Error("network error"));
    renderPage();

    expect(await screen.findByText("Impossible de charger le planning.")).toBeInTheDocument();
  });
});

describe("SurgeonPlanningPage — CTA demande de mission (Lot 5, D-099)", () => {
  it("le CTA '+ Demander une mission' est accessible depuis le planning et ouvre le formulaire", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [] });
    const user = userEvent.setup();
    renderPageWithRoutes();

    await user.click(await screen.findByText("+ Demander une mission"));
    expect(await screen.findByText("formulaire demande")).toBeInTheDocument();
  });
});
