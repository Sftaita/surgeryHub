import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import PlanningPage, { buildMonthGridCells, formatDateToYmd } from "./PlanningPage";

describe("buildMonthGridCells (risque identifié dans le plan — grille mensuelle calculée à la main)", () => {
  it("commence toujours un lundi", () => {
    const cells = buildMonthGridCells("2026-07-05");
    const firstDay = new Date(cells[0].dateYmd + "T00:00:00");
    expect(firstDay.getDay()).toBe(1); // 1 = lundi
  });

  it("couvre des semaines complètes (multiple de 7 cellules)", () => {
    const cells = buildMonthGridCells("2026-07-05");
    expect(cells.length % 7).toBe(0);
  });

  it("marque correctement les jours du mois courant vs hors-mois — juillet 2026 (1er = mercredi)", () => {
    const cells = buildMonthGridCells("2026-07-15");
    // Juillet 2026 : le 1er tombe un mercredi → lun 29 et mar 30 juin sont hors-mois en tête de grille.
    expect(cells[0]).toEqual({ dateYmd: "2026-06-29", dayNumber: 29, inCurrentMonth: false });
    expect(cells[1]).toEqual({ dateYmd: "2026-06-30", dayNumber: 30, inCurrentMonth: false });
    expect(cells[2]).toEqual({ dateYmd: "2026-07-01", dayNumber: 1, inCurrentMonth: true });
    const last = cells[cells.length - 1];
    expect(new Date(last.dateYmd + "T00:00:00").getDay()).toBe(0); // se termine un dimanche
  });

  it("couvre bien tous les jours du mois, y compris le dernier", () => {
    const cells = buildMonthGridCells("2026-02-10"); // février 2026 = 28 jours
    const inMonth = cells.filter((c) => c.inCurrentMonth);
    expect(inMonth).toHaveLength(28);
    expect(inMonth[0].dateYmd).toBe("2026-02-01");
    expect(inMonth[27].dateYmd).toBe("2026-02-28");
  });

  it("gère le changement d'année (décembre → janvier)", () => {
    const cells = buildMonthGridCells("2026-12-15");
    const inMonth = cells.filter((c) => c.inCurrentMonth);
    expect(inMonth).toHaveLength(31);
    expect(inMonth[0].dateYmd).toBe("2026-12-01");
    expect(inMonth[30].dateYmd).toBe("2026-12-31");
  });
});

describe("formatDateToYmd", () => {
  it("formate en YYYY-MM-DD avec zéros de tête", () => {
    expect(formatDateToYmd(new Date(2026, 0, 5))).toBe("2026-01-05");
  });
});

const fetchMissionsMock = vi.fn();

vi.mock("../../features/missions/api/missions.api", () => ({
  fetchMissions: (...args: unknown[]) => fetchMissionsMock(...args),
}));

vi.mock("./MissionDetailPage", () => ({
  MissionDetailContent: () => <div>détail mission</div>,
}));

function makeMission(overrides: Partial<any> = {}) {
  return {
    id: 1,
    type: "BLOCK",
    status: "ASSIGNED",
    startAt: "2026-07-10T07:30:00",
    endAt: "2026-07-10T15:30:00",
    site: { id: 1, name: "CHU Brugmann" },
    allowedActions: [],
    ...overrides,
  };
}

function renderPage(initialEntry = "/planning?view=week&date=2026-07-05") {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <QueryClientProvider client={client}>
        <PlanningPage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  fetchMissionsMock.mockReset();
});

describe("PlanningPage", () => {
  it("affiche le contrôle segmenté et bascule entre semaine et mois", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [] });
    const user = userEvent.setup();
    renderPage();

    await waitFor(() => expect(fetchMissionsMock).toHaveBeenCalled());
    expect(screen.getByRole("button", { name: "Semaine" })).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Mois" }));
    // Bascule vers la grille mensuelle : les en-têtes de colonnes L M M J V S D apparaissent.
    await waitFor(() => expect(screen.getAllByText("L").length).toBeGreaterThan(0));
  });

  it("affiche la section À VENIR avec les missions de la période", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [makeMission()] });
    renderPage();

    expect(await screen.findByText("À VENIR")).toBeInTheDocument();
    expect(await screen.findByText("CHU Brugmann")).toBeInTheDocument();
  });

  it("affiche le bandeau d'info quand aucune mission n'est prévue sur la période", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [] });
    renderPage();

    expect(await screen.findByText(/Acceptez des offres/)).toBeInTheDocument();
  });

  it("ouvre le détail mission au clic sur une ligne À VENIR", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [makeMission()] });
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByText("CHU Brugmann"));
    expect(await screen.findByText("détail mission")).toBeInTheDocument();
  });
});
