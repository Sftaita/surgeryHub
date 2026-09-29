import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import { ToastProvider } from "../../ui/toast/ToastProvider";
import SurgeonAvailableRoomsPage from "./SurgeonAvailableRoomsPage";

/**
 * D-124 — « Reprendre une salle libérée », côté chirurgien. Le frontend n'invente rien : CTA
 * pilotés par `allowedActions`, une seule action serveur par confirmation, puis rechargement
 * de l'état serveur (jamais un masquage local). Le mock de liste renvoie des réponses
 * successives pour simuler l'état serveur avant/après chaque action.
 */

const getMyAvailableRoomsMock = vi.fn();
const takeOverMock = vi.fn();
const releaseMock = vi.fn();

vi.mock("../../features/planning-v2/api/planningV2.api", () => ({
  getMyAvailableRooms: (...args: unknown[]) => getMyAvailableRoomsMock(...args),
  takeOverReleasedRoom: (...args: unknown[]) => takeOverMock(...args),
  releaseReleasedRoom: (...args: unknown[]) => releaseMock(...args),
}));

function slot(overrides: Partial<any> = {}) {
  return {
    id: 7,
    site: { id: 9, name: "Delta" },
    occurrenceDate: "2026-10-15",
    period: "MATIN",
    startTime: "08:00",
    endTime: "13:00",
    surgeon: { id: 3, name: "Dr Alain" },
    status: "AVAILABLE",
    createdAt: "2026-09-01T00:00:00+00:00",
    claimedBy: null,
    claimedAt: null,
    claimedByMe: false,
    takeoverMission: null,
    allowedActions: { takeOver: true, release: false },
    ...overrides,
  };
}

const claimedByMe = (mission: any = { id: 55, status: "OPEN", instrumentist: null }) =>
  slot({
    status: "CLAIMED",
    claimedBy: { id: 4, name: "Dr Bruno" },
    claimedAt: "2026-09-25T10:00:00+02:00",
    claimedByMe: true,
    takeoverMission: mission,
    allowedActions: { takeOver: false, release: true },
  });

/** Every list query (page list + site options) returns the current "server state". */
let serverItems: any[] = [];

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <MemoryRouter initialEntries={["/app/s/planning/salles-disponibles"]}>
      <QueryClientProvider client={client}><ToastProvider>
        <SurgeonAvailableRoomsPage />
      </ToastProvider></QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  serverItems = [slot()];
  getMyAvailableRoomsMock.mockReset();
  getMyAvailableRoomsMock.mockImplementation(() => Promise.resolve({ items: serverItems, page: 1, limit: 100, total: serverItems.length }));
  takeOverMock.mockReset();
  releaseMock.mockReset();
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(new Date("2026-09-25T12:00:00+02:00"));
});

afterEach(() => {
  vi.useRealTimers();
});

describe("Reprendre une salle — CTA et confirmation", () => {
  it("affiche date, site, horaires, chirurgien absent et le CTA quand le backend l'autorise", async () => {
    renderPage();
    const card = await screen.findByTestId("available-room-row-7");
    expect(within(card).getByText("Delta — 08:00–13:00")).toBeInTheDocument();
    expect(within(card).getByText("Dr Alain absent")).toBeInTheDocument();
    expect(within(card).getByText("Disponible")).toBeInTheDocument();
    expect(within(card).getByRole("button", { name: "Reprendre cette salle" })).toBeInTheDocument();
    expect(within(card).queryByRole("button", { name: "Libérer la salle" })).not.toBeInTheDocument();
  });

  it("aucun CTA quand allowedActions.takeOver est false — jamais déduit côté client", async () => {
    serverItems = [slot({ allowedActions: { takeOver: false, release: false } })];
    renderPage();
    const card = await screen.findByTestId("available-room-row-7");
    expect(within(card).queryByRole("button")).not.toBeInTheDocument();
  });

  it("le clic ouvre une confirmation explicite ; Annuler n'appelle jamais le serveur", async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();
    await user.click(await screen.findByRole("button", { name: "Reprendre cette salle" }));

    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("Reprendre cette salle ?")).toBeInTheDocument();
    expect(within(dialog).getByText("Delta")).toBeInTheDocument();
    expect(within(dialog).getByText("Jeudi 15 octobre")).toBeInTheDocument();
    expect(within(dialog).getByText("08:00–13:00")).toBeInTheDocument();
    expect(within(dialog).getByText("Salle libérée suite à l'absence du Dr Alain")).toBeInTheDocument();
    expect(within(dialog).getByText(/une mission sera ouverte aux instrumentistes du site/)).toBeInTheDocument();

    await user.click(within(dialog).getByRole("button", { name: "Annuler" }));
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    expect(takeOverMock).not.toHaveBeenCalled();
  });
});

describe("Reprendre une salle — résultat serveur", () => {
  it("succès : UN seul appel métier, puis l'état serveur rechargé montre « Salle reprise » + mission à pourvoir", async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    takeOverMock.mockImplementation(async () => {
      serverItems = [claimedByMe()];
      return claimedByMe();
    });
    renderPage();
    await user.click(await screen.findByRole("button", { name: "Reprendre cette salle" }));
    const callsBefore = getMyAvailableRoomsMock.mock.calls.length;
    await user.click(within(await screen.findByRole("dialog")).getByRole("button", { name: "Reprendre la salle" }));

    expect(await screen.findByText("Vous avez repris cette salle")).toBeInTheDocument();
    expect(takeOverMock).toHaveBeenCalledTimes(1);
    expect(takeOverMock).toHaveBeenCalledWith(7);
    expect(getMyAvailableRoomsMock.mock.calls.length).toBeGreaterThan(callsBefore); // server state refreshed
    const card = screen.getByTestId("available-room-row-7");
    expect(within(card).getByText("Salle reprise")).toBeInTheDocument();
    expect(within(card).getByText("À pourvoir")).toBeInTheDocument();
    expect(within(card).queryByRole("button", { name: "Reprendre cette salle" })).not.toBeInTheDocument();
    expect(screen.getByText("Salle reprise — une mission est ouverte aux instrumentistes du site.")).toBeInTheDocument();
  });

  it("409 ROOM_SLOT_ALREADY_TAKEN : message « reprise par Dr X » et le créneau disparaît après rechargement serveur", async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    takeOverMock.mockImplementation(async () => {
      serverItems = []; // taken by someone else — the server no longer lists it for me
      throw {
        response: {
          status: 409,
          data: { error: { code: "ROOM_SLOT_ALREADY_TAKEN", message: "Cette salle vient d'être reprise par Dr Claire.", takenBy: { id: 5, name: "Dr Claire" } } },
        },
      };
    });
    renderPage();
    await user.click(await screen.findByRole("button", { name: "Reprendre cette salle" }));
    await user.click(within(await screen.findByRole("dialog")).getByRole("button", { name: "Reprendre la salle" }));

    expect(await screen.findByText("Cette salle vient d'être reprise par Dr Claire.")).toBeInTheDocument();
    await waitFor(() => expect(screen.queryByTestId("available-room-row-7")).not.toBeInTheDocument());
    expect(screen.getByText("Aucune salle disponible pour cette sélection.")).toBeInTheDocument();
  });
});

describe("Salle reprise par moi — état de la mission et libération", () => {
  it("affiche l'instrumentiste attribuée quand la mission est ASSIGNED", async () => {
    serverItems = [claimedByMe({ id: 55, status: "ASSIGNED", instrumentist: { id: 8, name: "Marie Dupont" } })];
    renderPage();
    const card = await screen.findByTestId("available-room-row-7");
    expect(within(card).getByText("Marie Dupont")).toBeInTheDocument();
    expect(within(card).queryByText("À pourvoir")).not.toBeInTheDocument();
  });

  it("« Libérer la salle » uniquement si le backend l'autorise, confirmation puis un seul appel", async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    serverItems = [claimedByMe({ id: 55, status: "ASSIGNED", instrumentist: { id: 8, name: "Marie Dupont" } })];
    releaseMock.mockImplementation(async () => {
      serverItems = [slot()];
      return slot();
    });
    renderPage();

    await user.click(await screen.findByRole("button", { name: "Libérer la salle" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText(/Marie Dupont en sera informée/)).toBeInTheDocument();
    await user.click(within(dialog).getByRole("button", { name: "Libérer la salle" }));

    expect(await screen.findByRole("button", { name: "Reprendre cette salle" })).toBeInTheDocument();
    expect(releaseMock).toHaveBeenCalledTimes(1);
    expect(releaseMock).toHaveBeenCalledWith(7);
  });

  it("pas de bouton « Libérer la salle » quand allowedActions.release est false", async () => {
    serverItems = [{ ...claimedByMe(), allowedActions: { takeOver: false, release: false } }];
    renderPage();
    const card = await screen.findByTestId("available-room-row-7");
    expect(within(card).getByText("Vous avez repris cette salle")).toBeInTheDocument();
    expect(within(card).queryByRole("button")).not.toBeInTheDocument();
  });
});
