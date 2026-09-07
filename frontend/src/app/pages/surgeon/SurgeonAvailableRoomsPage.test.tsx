import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import SurgeonAvailableRoomsPage from "./SurgeonAvailableRoomsPage";

/**
 * « Salles disponibles » — vue chirurgien, filtres (revue intégration agenda, 2026-09-07,
 * §5/§6). Le scoping réel (affiliations, jamais le passé) est appliqué côté backend
 * uniquement — ce test couvre le comportement des filtres, jamais une seconde source de
 * vérité côté frontend (§8/§11 de la demande).
 */

const getMyAvailableRoomsMock = vi.fn();

vi.mock("../../features/planning-v2/api/planningV2.api", () => ({
  getMyAvailableRooms: (...args: unknown[]) => getMyAvailableRoomsMock(...args),
}));

function makeSlot(overrides: Partial<any> = {}) {
  return {
    id: 1,
    site: { id: 9, name: "CHIREC - Hôpital Delta" },
    occurrenceDate: "2026-08-30",
    period: "MATIN",
    startTime: "08:00",
    endTime: "13:00",
    surgeon: { id: 3, name: "Dr Ftaita" },
    status: "AVAILABLE",
    createdAt: "2026-08-01T00:00:00+00:00",
    ...overrides,
  };
}

function renderPage(initialEntry = "/app/s/planning/salles-disponibles") {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <QueryClientProvider client={client}>
        <SurgeonAvailableRoomsPage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  getMyAvailableRoomsMock.mockReset();
  getMyAvailableRoomsMock.mockResolvedValue({ items: [], page: 1, limit: 100, total: 0 });
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(new Date("2026-08-27T12:00:00+02:00"));
});

afterEach(() => {
  vi.useRealTimers();
});

describe("SurgeonAvailableRoomsPage — état par défaut", () => {
  it("défaut 'À venir' : aucune borne dateTo envoyée, uniquement dateFrom = aujourd'hui", async () => {
    renderPage();

    await waitFor(() => {
      expect(getMyAvailableRoomsMock.mock.calls.some((c) => c[0]?.dateFrom === "2026-08-27")).toBe(true);
    });
    const params = getMyAvailableRoomsMock.mock.calls.find((c) => c[0]?.dateFrom === "2026-08-27")![0];
    expect(params.dateTo).toBeUndefined();
    expect(screen.getByText("À venir")).toBeInTheDocument();
  });

  it("tri chronologique croissant préservé (ordre renvoyé par l'API, jamais retrié à l'envers côté client)", async () => {
    getMyAvailableRoomsMock.mockResolvedValue({
      items: [
        makeSlot({ id: 1, occurrenceDate: "2026-08-28", site: { id: 1, name: "Site A" } }),
        makeSlot({ id: 2, occurrenceDate: "2026-09-05", site: { id: 1, name: "Site A" } }),
      ],
      page: 1, limit: 100, total: 2,
    });
    renderPage();

    const rows = await screen.findAllByTestId(/available-room-row-/);
    expect(rows.map((r) => r.getAttribute("data-testid"))).toEqual([
      "available-room-row-1",
      "available-room-row-2",
    ]);
  });

  it("état vide explicite quand aucune salle ne correspond", async () => {
    renderPage();
    expect(await screen.findByText("Aucune salle disponible pour cette sélection.")).toBeInTheDocument();
  });

  it("erreur réseau → message explicite", async () => {
    getMyAvailableRoomsMock.mockRejectedValue(new Error("network"));
    renderPage();
    expect(await screen.findByText("Impossible de charger les salles disponibles.")).toBeInTheDocument();
  });
});

describe("SurgeonAvailableRoomsPage — chips de plage rapide", () => {
  it("'Aujourd'hui' borne dateFrom = dateTo = aujourd'hui", async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();
    await waitFor(() => expect(getMyAvailableRoomsMock).toHaveBeenCalled());

    await user.click(screen.getByText("Aujourd'hui"));
    await waitFor(() => {
      const last = getMyAvailableRoomsMock.mock.calls.at(-1)![0];
      expect(last.dateFrom).toBe("2026-08-27");
      expect(last.dateTo).toBe("2026-08-27");
    });
  });

  it("'30 prochains jours' borne dateTo = aujourd'hui + 30", async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();
    await waitFor(() => expect(getMyAvailableRoomsMock).toHaveBeenCalled());

    await user.click(screen.getByText("30 prochains jours"));
    await waitFor(() => {
      const last = getMyAvailableRoomsMock.mock.calls.at(-1)![0];
      expect(last.dateFrom).toBe("2026-08-27");
      expect(last.dateTo).toBe("2026-09-26");
    });
  });
});

describe("SurgeonAvailableRoomsPage — filtre période", () => {
  it("sélectionner une période l'inclut dans les paramètres de la requête", async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();
    await waitFor(() => expect(getMyAvailableRoomsMock).toHaveBeenCalled());

    await user.click(screen.getByText("Toutes les périodes"));
    await user.click(await screen.findByRole("option", { name: "Après-midi" }));

    await waitFor(() => {
      const last = getMyAvailableRoomsMock.mock.calls.at(-1)![0];
      expect(last.period).toBe("APRES_MIDI");
    });
  });
});

describe("SurgeonAvailableRoomsPage — filtre établissement", () => {
  it("le sélecteur établissement n'apparaît pas avec un seul site affilié", async () => {
    getMyAvailableRoomsMock.mockResolvedValue({
      items: [makeSlot({ site: { id: 1, name: "Site Unique" } })],
      page: 1, limit: 100, total: 1,
    });
    renderPage();

    await screen.findAllByTestId(/available-room-row-/);
    expect(screen.queryByLabelText("Établissement")).not.toBeInTheDocument();
  });

  it("avec plusieurs sites, sélectionner un établissement filtre par siteId", async () => {
    getMyAvailableRoomsMock.mockResolvedValue({
      items: [
        makeSlot({ id: 1, site: { id: 1, name: "Site Alpha" } }),
        makeSlot({ id: 2, site: { id: 2, name: "Site Beta" } }),
      ],
      page: 1, limit: 100, total: 2,
    });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    const field = await screen.findByLabelText("Établissement");
    await user.click(field);
    await user.type(field, "Beta");
    await user.click(await screen.findByText("Site Beta"));

    await waitFor(() => {
      const last = getMyAvailableRoomsMock.mock.calls.at(-1)![0];
      expect(last.siteId).toBe(2);
    });
  });
});

describe("SurgeonAvailableRoomsPage — deep link", () => {
  it("les paramètres d'URL (range/siteId/period) sont appliqués dès le premier rendu", async () => {
    renderPage("/app/s/planning/salles-disponibles?range=today&siteId=7&period=JOURNEE");

    // Deux requêtes partent au montage (liste filtrée + options établissement, non bornée) —
    // on cible celle qui porte réellement les filtres, jamais une hypothèse d'ordre.
    await waitFor(() => {
      expect(getMyAvailableRoomsMock.mock.calls.some((c) => c[0]?.siteId === 7)).toBe(true);
    });
    const params = getMyAvailableRoomsMock.mock.calls.find((c) => c[0]?.siteId === 7)![0];
    expect(params.dateFrom).toBe("2026-08-27");
    expect(params.dateTo).toBe("2026-08-27");
    expect(params.siteId).toBe(7);
    expect(params.period).toBe("JOURNEE");
    expect(screen.getByText("Aujourd'hui").closest("div")).toHaveClass("MuiChip-filled");
  });
});
