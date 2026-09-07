import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
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
const getMyAvailableRoomsMock = vi.fn();
const getMyAvailableRoomsCountMock = vi.fn();

vi.mock("../../features/missions/api/missions.api", () => ({
  fetchMissions: (...args: unknown[]) => fetchMissionsMock(...args),
}));

vi.mock("../../features/planning-v2/api/planningV2.api", () => ({
  getMyAvailableRooms: (...args: unknown[]) => getMyAvailableRoomsMock(...args),
  getMyAvailableRoomsCount: (...args: unknown[]) => getMyAvailableRoomsCountMock(...args),
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

function makeRoomSlot(overrides: Partial<any> = {}) {
  return {
    id: 1,
    site: { id: 9, name: "Site Salles" },
    occurrenceDate: "2026-08-26",
    period: "MATIN",
    startTime: "08:00",
    endTime: "13:00",
    surgeon: { id: 3, name: "Dr Ftaita" },
    status: "AVAILABLE",
    createdAt: "2026-08-01T00:00:00+00:00",
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

function renderPageWithRoutesForRooms(initialEntry = "/app/s/planning?view=week&date=2026-08-27&filter=all") {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <QueryClientProvider client={client}>
        <Routes>
          <Route path="/app/s/planning" element={<SurgeonPlanningPage />} />
          <Route path="/app/s/planning/salles-disponibles" element={<div>page salles disponibles</div>} />
        </Routes>
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  fetchMissionsMock.mockReset();
  getMyAvailableRoomsMock.mockReset();
  getMyAvailableRoomsCountMock.mockReset();
  getMyAvailableRoomsMock.mockResolvedValue({ items: [], page: 1, limit: 100, total: 0 });
  getMyAvailableRoomsCountMock.mockResolvedValue({ count: 0 });
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

// ── Intégration ReleasedOperatingRoomSlot dans l'agenda (revue 2026-09-07) ──────────
describe("SurgeonPlanningPage — salles disponibles dans l'agenda", () => {
  it("jour sans salle disponible : aucun badge, aucune section Salles disponibles", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [] });
    getMyAvailableRoomsMock.mockResolvedValue({ items: [], page: 1, limit: 100, total: 0 });
    renderPage();

    await waitFor(() => expect(getMyAvailableRoomsMock).toHaveBeenCalled());
    expect(screen.queryByText("SALLES DISPONIBLES")).not.toBeInTheDocument();
    expect(screen.queryByTestId(/month-room-badge-/)).not.toBeInTheDocument();
  });

  it("dateFrom/dateTo envoyés au format Y-m-d strict — une réponse réelle sur la fenêtre visible produit bien le badge (jamais un helper testé isolément)", async () => {
    // Simule la contrainte réelle du backend (`DateTimeImmutable::createFromFormat('!Y-m-d', ...)`,
    // 400 sur tout ce qui porte une heure/un 'Z') : si la régression ISO/.toISOString() revient,
    // ce mock refuse la requête exactement comme le ferait le vrai serveur, et le badge
    // n'apparaît jamais — ce test échoue pour la même raison que le bug réel observé en live.
    const STRICT_YMD = /^\d{4}-\d{2}-\d{2}$/;
    fetchMissionsMock.mockResolvedValue({ items: [] });
    getMyAvailableRoomsMock.mockImplementation(({ dateFrom, dateTo }: { dateFrom?: string; dateTo?: string }) => {
      if (!dateFrom || !STRICT_YMD.test(dateFrom) || !dateTo || !STRICT_YMD.test(dateTo)) {
        return Promise.reject({ response: { status: 400, data: { error: { message: "dateFrom invalide, format attendu Y-m-d." } } } });
      }
      if (dateFrom <= "2026-09-07" && "2026-09-07" <= dateTo) {
        return Promise.resolve({ items: [makeRoomSlot({ id: 42, occurrenceDate: "2026-09-07" })], page: 1, limit: 100, total: 1 });
      }
      return Promise.resolve({ items: [], page: 1, limit: 100, total: 0 });
    });
    renderPage("/planning?view=month&date=2026-09-07");

    expect(await screen.findByTestId("month-room-badge-2026-09-07")).toHaveTextContent("1");
    const call = getMyAvailableRoomsMock.mock.calls[0][0];
    expect(call.dateFrom).toBe("2026-09-01");
    expect(call.dateTo).toBe("2026-09-30");
  });

  it("jour avec 1 salle disponible : badge '1' dans la grille mois, ligne dans la section dédiée", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [] });
    getMyAvailableRoomsMock.mockResolvedValue({
      items: [makeRoomSlot({ id: 1, occurrenceDate: "2026-08-26" })],
      page: 1, limit: 100, total: 1,
    });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage("/planning?view=month&date=2026-08-27&filter=all");

    await user.click(screen.getByRole("button", { name: "Mois" }));
    expect(await screen.findByTestId("month-room-badge-2026-08-26")).toHaveTextContent("1");
    expect(screen.getByText("SALLES DISPONIBLES")).toBeInTheDocument();
    expect(screen.getByText(/Site Salles/)).toBeInTheDocument();
    expect(screen.getByText("Libérée par Dr Ftaita")).toBeInTheDocument();
  });

  it("plusieurs salles le même jour, plusieurs sites/périodes : badge affiche le compte total, toutes listées dans l'ordre reçu (jamais seulement la première)", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [] });
    getMyAvailableRoomsMock.mockResolvedValue({
      items: [
        makeRoomSlot({ id: 3, occurrenceDate: "2026-08-26", period: "JOURNEE", site: { id: 9, name: "Site Salles" } }),
        makeRoomSlot({ id: 1, occurrenceDate: "2026-08-26", period: "MATIN", site: { id: 20, name: "Delta Test" } }),
        makeRoomSlot({ id: 2, occurrenceDate: "2026-08-26", period: "APRES_MIDI", site: { id: 20, name: "Delta Test" } }),
      ],
      page: 1, limit: 100, total: 3,
    });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage("/planning?view=month&date=2026-08-27&filter=all");

    await user.click(screen.getByRole("button", { name: "Mois" }));
    const badge = await screen.findByTestId("month-room-badge-2026-08-26");
    expect(badge).toHaveTextContent("3");

    await user.click(badge);
    const dialog = await screen.findByRole("dialog");
    const rows = within(dialog).getAllByTestId(/available-room-row-/);
    // Pas seulement la première salle affichée, et jamais retriées côté client : le tri est
    // une responsabilité backend (déjà testé dans AvailableRoomsControllerTest) — le panneau
    // doit refléter tel quel l'ordre reçu de l'API.
    expect(rows.map((r) => r.getAttribute("data-testid"))).toEqual([
      "available-room-row-3",
      "available-room-row-1",
      "available-room-row-2",
    ]);
    expect(within(dialog).getAllByText(/Delta Test/)).toHaveLength(2);
    expect(within(dialog).getAllByText(/Site Salles/)).toHaveLength(1);
  });

  it("une salle disponible n'est jamais présentée avec un statut de mission — toujours 'Disponible'", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [] });
    getMyAvailableRoomsMock.mockResolvedValue({
      items: [makeRoomSlot({ id: 1, occurrenceDate: "2026-08-26" })],
      page: 1, limit: 100, total: 1,
    });
    renderPage();

    await screen.findByText(/Site Salles/);
    const row = screen.getByTestId("available-room-row-1");
    // Jamais un StatusPillVariant mission (Couverte/À couvrir/Annulée/En cours) sur une
    // salle — toujours la même pastille "Disponible", scopée à la ligne elle-même (le
    // contrôle de filtre "À couvrir" existe par ailleurs sur la page, hors de propos ici).
    expect(within(row).getByText("Disponible")).toBeInTheDocument();
    expect(within(row).queryByText("Couverte")).not.toBeInTheDocument();
    expect(within(row).queryByText("À couvrir")).not.toBeInTheDocument();
    expect(within(row).queryByText("Annulée")).not.toBeInTheDocument();
  });

  it("clic sur un jour avec une seule mission et aucune salle : comportement inchangé (ouverture directe du détail mission)", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [makeMission({ id: 42 })] });
    getMyAvailableRoomsMock.mockResolvedValue({ items: [], page: 1, limit: 100, total: 0 });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage("/planning?view=month&date=2026-08-27&filter=all");

    await user.click(screen.getByRole("button", { name: "Mois" }));
    const dayCell = await screen.findByTestId("month-day-2026-08-26");
    await user.click(dayCell);
    expect(await screen.findByText("détail mission #42")).toBeInTheDocument();
  });

  it("clic sur un jour avec mission ET salle disponible : panneau détail du jour, jamais directement le dialogue mission", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [makeMission({ id: 42, startAt: "2026-08-26T10:00:00+02:00", endAt: "2026-08-26T12:00:00+02:00" })] });
    getMyAvailableRoomsMock.mockResolvedValue({
      items: [makeRoomSlot({ id: 1, occurrenceDate: "2026-08-26" })],
      page: 1, limit: 100, total: 1,
    });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage("/planning?view=month&date=2026-08-27&filter=all");

    await user.click(screen.getByRole("button", { name: "Mois" }));
    const badge = await screen.findByTestId("month-room-badge-2026-08-26");
    await user.click(badge);

    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("MISSIONS")).toBeInTheDocument();
    expect(within(dialog).getByText("SALLES DISPONIBLES")).toBeInTheDocument();
    expect(screen.queryByText("détail mission #42")).not.toBeInTheDocument();

    await user.click(within(dialog).getByText(/CHIREC - Hôpital Delta/));
    expect(await screen.findByText("détail mission #42")).toBeInTheDocument();
  });

  it("vue semaine : point bleu distinct sur un jour avec salle disponible", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [] });
    getMyAvailableRoomsMock.mockResolvedValue({
      items: [makeRoomSlot({ id: 1, occurrenceDate: "2026-08-26" })],
      page: 1, limit: 100, total: 1,
    });
    renderPage("/planning?view=week&date=2026-08-27&filter=all");

    expect(await screen.findByTestId("week-room-dot-2026-08-26")).toBeInTheDocument();
  });

  it("le CTA 'Salles disponibles' affiche un badge avec le compteur total (endpoint /count)", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [] });
    getMyAvailableRoomsCountMock.mockResolvedValue({ count: 4 });
    renderPage();

    await waitFor(() => expect(getMyAvailableRoomsCountMock).toHaveBeenCalled());
    expect(await screen.findByText("4")).toBeInTheDocument();
  });

  it("aucun compteur affiché quand il n'y a aucune salle future", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [] });
    getMyAvailableRoomsCountMock.mockResolvedValue({ count: 0 });
    renderPage();

    await waitFor(() => expect(getMyAvailableRoomsCountMock).toHaveBeenCalled());
    expect(screen.queryByText("0")).not.toBeInTheDocument();
  });

  it("le CTA 'Salles disponibles' navigue vers /app/s/planning/salles-disponibles", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [] });
    const user = userEvent.setup();
    renderPageWithRoutesForRooms();

    await user.click(await screen.findByText("Salles disponibles"));
    expect(await screen.findByText("page salles disponibles")).toBeInTheDocument();
  });
});
