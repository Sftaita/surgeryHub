import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import MissionsListPage from "./MissionsListPage";
import type { Mission } from "../../features/missions/api/missions.types";

vi.mock("../../features/missions/api/missions.api", () => ({
  fetchMissions: vi.fn(),
  fetchSites: vi.fn().mockResolvedValue([{ id: 1, name: "CHIREC Delta" }, { id: 2, name: "Clinique Nord" }]),
}));

vi.mock("../../features/manager-mission-requests/api/managerSurgeonMissionRequests.api", () => ({
  getSurgeonMissionRequestCounts: vi.fn().mockResolvedValue({ PENDING: 3, ACCEPTED: 0, REJECTED: 0 }),
  getSurgeonMissionRequests: vi.fn().mockResolvedValue({ items: [], total: 0 }),
  acceptSurgeonMissionRequest: vi.fn(),
  rejectSurgeonMissionRequest: vi.fn(),
}));

import { fetchMissions } from "../../features/missions/api/missions.api";

function mission(id: number, startAt: string, overrides: Partial<Mission> = {}): Mission {
  return {
    id,
    type: "BLOCK",
    schedulePrecision: "EXACT",
    startAt,
    endAt: startAt.replace(/T(\d\d)/, (_, h) => `T${String(Number(h) + 4).padStart(2, "0")}`),
    site: { id: 1, name: "CHIREC Delta" },
    status: "ASSIGNED",
    surgeon: { id: 5, firstname: "Arnaud", lastname: "Deltour", email: "a@x.be" },
    instrumentist: { id: 9, firstname: "Salve", lastname: "Decorte", email: "s@x.be" },
    ...overrides,
  } as Mission;
}

let lastSearch = "";
function LocationProbe() {
  lastSearch = useLocation().search;
  return null;
}

function renderPage(initialEntry = "/app/m/missions") {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[initialEntry]}>
        <Routes>
          {["/app/m/missions", "/app/m/missions/to-validate", "/app/m/missions/requests"].map((path) => (
            <Route key={path} path={path} element={<><MissionsListPage /><LocationProbe /></>} />
          ))}
          <Route path="/app/m/missions/:id" element={<div>detail</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

function lastFilters() {
  const calls = vi.mocked(fetchMissions).mock.calls;
  return calls[calls.length - 1][2]!;
}

beforeEach(() => {
  vi.mocked(fetchMissions).mockReset();
  vi.mocked(fetchMissions).mockResolvedValue({ items: [], total: 0 } as never);
});

describe("MissionsListPage — vue manager (D-147)", () => {
  it("par défaut : missions à venir (from = aujourd'hui), les plus proches d'abord", async () => {
    renderPage();

    await waitFor(() => expect(fetchMissions).toHaveBeenCalled());
    const filters = lastFilters();
    expect(filters.order).toBe("asc");
    const today = new Date();
    expect(new Date(filters.from!).getTime()).toBe(new Date(today.getFullYear(), today.getMonth(), today.getDate()).getTime());
    expect(filters).not.toHaveProperty("to");
  });

  it("regroupe les missions par jour avec un en-tête et un compteur", async () => {
    vi.mocked(fetchMissions).mockResolvedValue({
      items: [
        mission(1, "2026-10-14T08:00:00+02:00"),
        mission(2, "2026-10-14T13:00:00+02:00", { instrumentist: null, status: "OPEN" }),
        mission(3, "2026-10-15T08:00:00+02:00"),
      ],
      total: 3,
    } as never);
    renderPage();

    const day1 = await screen.findByRole("region", { name: "Mercredi 14 octobre 2026" });
    expect(within(day1).getByText("2 missions")).toBeInTheDocument();
    expect(within(day1).getAllByTestId("mission-row")).toHaveLength(2);
    expect(within(day1).getByText("Aucune instrumentiste")).toBeInTheDocument();
    expect(within(day1).getByText("08:00 – 12:00")).toBeInTheDocument();

    const day2 = screen.getByRole("region", { name: "Jeudi 15 octobre 2026" });
    expect(within(day2).getByText("1 mission")).toBeInTheDocument();
    expect(screen.getByTestId("missions-total")).toHaveTextContent("3 missions");
  });

  it("recherche dynamique : la saisie part au serveur (q) après une courte pause et va dans l'URL", async () => {
    const user = userEvent.setup();
    renderPage();
    await waitFor(() => expect(fetchMissions).toHaveBeenCalled());

    await user.type(screen.getByPlaceholderText(/Chirurgien, instrumentiste/), "deltour");

    await waitFor(() => expect(lastFilters().q).toBe("deltour"));
    // Debounced: no request per keystroke.
    expect(vi.mocked(fetchMissions).mock.calls.filter(([, , f]) => f?.q && f.q !== "deltour")).toHaveLength(0);
    await waitFor(() => expect(lastSearch).toContain("q=deltour"));
  });

  it("filtres période / statut / site transmis au serveur", async () => {
    const user = userEvent.setup();
    renderPage();
    await waitFor(() => expect(fetchMissions).toHaveBeenCalled());

    await user.click(screen.getByRole("button", { name: "Passées" }));
    await waitFor(() => expect(lastFilters().order).toBe("desc"));
    expect(lastFilters()).not.toHaveProperty("from");

    await user.click(screen.getByLabelText("Statut"));
    await user.click(await screen.findByRole("option", { name: "Publiée" }));
    await waitFor(() => expect(lastFilters().status).toBe("OPEN"));

    await user.click(screen.getByLabelText("Site"));
    await user.click(await screen.findByRole("option", { name: "Clinique Nord" }));
    await waitFor(() => expect(lastFilters().siteId).toBe(2));
    expect(lastSearch).toContain("period=past");
  });

  it("les filtres de l'URL sont repris au chargement", async () => {
    renderPage("/app/m/missions?q=nord&period=all&status=OPEN&siteId=2");

    await waitFor(() => expect(fetchMissions).toHaveBeenCalled());
    expect(lastFilters()).toMatchObject({ q: "nord", status: "OPEN", siteId: 2, order: "desc" });
    expect(screen.getByPlaceholderText(/Chirurgien, instrumentiste/)).toHaveValue("nord");
  });

  it("« À valider » : statut DECLARED imposé, toutes dates", async () => {
    renderPage("/app/m/missions/to-validate");

    await waitFor(() => expect(fetchMissions).toHaveBeenCalled());
    expect(lastFilters()).toMatchObject({ status: "DECLARED", order: "desc" });
    expect(lastFilters()).not.toHaveProperty("from");
    expect(screen.queryByLabelText("Statut")).not.toBeInTheDocument();
  });

  it("cliquer une mission ouvre son détail", async () => {
    vi.mocked(fetchMissions).mockResolvedValue({ items: [mission(42, "2026-10-14T08:00:00+02:00")], total: 1 } as never);
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByTestId("mission-row"));
    expect(await screen.findByText("detail")).toBeInTheDocument();
  });

  it("le badge « Demandes chirurgien » lit les compteurs partagés", async () => {
    renderPage();
    expect(await screen.findByText("3")).toBeInTheDocument();
  });
});
