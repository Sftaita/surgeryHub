import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { NotificationCenter } from "./NotificationCenter";
import type { NotificationItem } from "./api/notifications.api";

const fetchNotificationsMock = vi.fn();
const markNotificationSeenMock = vi.fn();
const markAllNotificationsSeenMock = vi.fn();

const { mockNavigate } = vi.hoisted(() => ({ mockNavigate: vi.fn() }));
vi.mock("react-router-dom", async (importOriginal) => {
  const actual = await importOriginal<typeof import("react-router-dom")>();
  return { ...actual, useNavigate: () => mockNavigate };
});

vi.mock("./api/notifications.api", () => ({
  fetchNotifications: (...args: unknown[]) => fetchNotificationsMock(...args),
  markNotificationSeen: (...args: unknown[]) => markNotificationSeenMock(...args),
  markAllNotificationsSeen: (...args: unknown[]) => markAllNotificationsSeenMock(...args),
}));

/** Données fictives, telles que le serveur (NotificationPresenter) les rédige. */
function item(overrides: Partial<NotificationItem> = {}): NotificationItem {
  return {
    id: 1,
    eventType: "MISSION_DECLARED",
    missionId: 42,
    title: "Mission déclarée à valider",
    body: "Une mission a été déclarée par Sophie Colette pour le Dr Jean Deltour le 13 novembre 2026 de 08h00 à 13h00 à Delta.",
    category: "mission",
    actionLabel: "Consulter la mission",
    businessState: "ACTION_REQUIRED",
    stateLabel: "À valider",
    legacy: false,
    missions: null,
    targetUrl: "/app/m/missions/42",
    payload: null,
    sentAt: "2026-11-07T09:00:00+00:00",
    seenAt: null,
    ...overrides,
  };
}

function renderCenter() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <NotificationCenter />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  fetchNotificationsMock.mockReset().mockResolvedValue({ items: [], unreadCount: 0, actionRequiredCount: 0 });
  markNotificationSeenMock.mockReset().mockResolvedValue({});
  markAllNotificationsSeenMock.mockReset().mockResolvedValue({ updated: 0 });
  mockNavigate.mockReset();
});

describe("NotificationCenter — texte, état et cible venant du serveur", () => {
  it("affiche le titre, la phrase contextualisée, l'état métier et l'action fournis par le serveur", async () => {
    fetchNotificationsMock.mockResolvedValue({ items: [item()], unreadCount: 1, actionRequiredCount: 1 });

    renderCenter();

    const row = await screen.findByRole("button", { name: "Mission déclarée à valider (non lue)" });
    expect(within(row).getByText(/déclarée par Sophie Colette pour le Dr Jean Deltour le 13 novembre 2026/)).toBeInTheDocument();
    expect(within(row).getByText("À valider")).toBeInTheDocument();
    expect(within(row).getByText(/Consulter la mission/)).toBeInTheDocument();
    expect(within(row).getByLabelText("Non lue")).toBeInTheDocument();
    expect(screen.queryByText("Notification")).not.toBeInTheDocument();
  });

  it("n'affiche plus jamais tout comme lu à l'ouverture de l'écran", async () => {
    fetchNotificationsMock.mockResolvedValue({ items: [item()], unreadCount: 1, actionRequiredCount: 1 });

    renderCenter();

    await screen.findByText("Mission déclarée à valider");
    expect(markAllNotificationsSeenMock).not.toHaveBeenCalled();
    expect(markNotificationSeenMock).not.toHaveBeenCalled();
  });

  it("non lue → lue : le clic marque la notification et ouvre la ressource", async () => {
    fetchNotificationsMock.mockResolvedValue({ items: [item({ id: 7 })], unreadCount: 1, actionRequiredCount: 1 });
    renderCenter();

    await userEvent.click(await screen.findByRole("button", { name: /Mission déclarée à valider/ }));

    expect(markNotificationSeenMock).toHaveBeenCalledWith(7);
    expect(mockNavigate).toHaveBeenCalledWith("/app/m/missions/42");
    await waitFor(() => expect(fetchNotificationsMock.mock.calls.length).toBeGreaterThan(1)); // refetch après marquage
  });

  it("une notification déjà lue reste « À traiter » et ouvre toujours la bonne ressource", async () => {
    fetchNotificationsMock.mockResolvedValue({
      items: [item({ seenAt: "2026-11-07T09:30:00+00:00", targetUrl: "/app/m/planning/absences?absenceId=58&notification=1", title: "Absence de Sophie Colette — planning impacté", category: "absence", stateLabel: "2 à couvrir" })],
      unreadCount: 0,
      actionRequiredCount: 1,
    });
    renderCenter();

    const row = await screen.findByRole("button", { name: "Absence de Sophie Colette — planning impacté" });
    expect(within(row).getByText("2 à couvrir")).toBeInTheDocument();
    expect(within(row).queryByLabelText("Non lue")).not.toBeInTheDocument();
    await userEvent.click(row);

    expect(markNotificationSeenMock).not.toHaveBeenCalled();
    expect(mockNavigate).toHaveBeenCalledWith("/app/m/planning/absences?absenceId=58&notification=1");
  });

  it("le bouton « Tout marquer comme lu » marque tout côté serveur", async () => {
    fetchNotificationsMock.mockResolvedValue({ items: [item({ id: 1 }), item({ id: 2 })], unreadCount: 2, actionRequiredCount: 2 });
    renderCenter();

    await userEvent.click(await screen.findByRole("button", { name: "Tout marquer comme lu" }));

    expect(markAllNotificationsSeenMock).toHaveBeenCalledTimes(1);
  });

  it("les filtres Non lues et À traiter sont appliqués par le serveur", async () => {
    fetchNotificationsMock.mockResolvedValue({ items: [item()], unreadCount: 3, actionRequiredCount: 2 });
    renderCenter();

    expect(await screen.findByRole("button", { name: "À traiter (2)" })).toBeInTheDocument();
    expect(fetchNotificationsMock).toHaveBeenLastCalledWith(50, "all", 0);

    await userEvent.click(screen.getByRole("button", { name: "À traiter (2)" }));
    await waitFor(() => expect(fetchNotificationsMock).toHaveBeenLastCalledWith(50, "action", 0));

    await userEvent.click(screen.getByRole("button", { name: "Non lues (3)" }));
    await waitFor(() => expect(fetchNotificationsMock).toHaveBeenLastCalledWith(50, "unread", 0));
  });

  it("filtre À traiter vide : message explicite", async () => {
    fetchNotificationsMock.mockImplementation((_limit: number, filter: string) =>
      Promise.resolve(filter === "action" ? { items: [], unreadCount: 0, actionRequiredCount: 0 } : { items: [item({ businessState: "DONE", stateLabel: "Validée" })], unreadCount: 0, actionRequiredCount: 0 }),
    );
    renderCenter();

    expect(await screen.findByText("Validée")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "À traiter" }));

    expect(await screen.findByText("Rien à traiter")).toBeInTheDocument();
  });

  it("une notification informative sans cible est marquée lue mais ne navigue nulle part", async () => {
    fetchNotificationsMock.mockResolvedValue({
      items: [item({ id: 9, title: "Mission annulée", body: "La mission du 16 novembre 2026 à Delta est annulée.", businessState: "INFO", stateLabel: null, targetUrl: null, actionLabel: null })],
      unreadCount: 1,
      actionRequiredCount: 0,
    });
    renderCenter();

    const row = await screen.findByRole("button", { name: "Mission annulée (non lue)" });
    expect(within(row).queryByText("À traiter")).not.toBeInTheDocument();
    await userEvent.click(row);

    expect(markNotificationSeenMock).toHaveBeenCalledWith(9);
    expect(mockNavigate).not.toHaveBeenCalled();
  });

  it("une ancienne notification incomplète est affichée telle que le serveur la décrit, sans contexte inventé", async () => {
    fetchNotificationsMock.mockResolvedValue({
      items: [item({ id: 3, eventType: "MISSION_ASSIGNED", title: "Ancienne notification", body: "Le détail de cette ancienne notification n'est pas disponible.", category: "info", businessState: "INFO", stateLabel: null, legacy: true, targetUrl: null, actionLabel: null, missionId: null })],
      unreadCount: 1,
      actionRequiredCount: 0,
    });
    renderCenter();

    expect(await screen.findByText("Ancienne notification")).toBeInTheDocument();
    expect(screen.getByText("Le détail de cette ancienne notification n'est pas disponible.")).toBeInTheDocument();
  });

  it("état vide", async () => {
    renderCenter();
    expect(await screen.findByText("Aucune notification")).toBeInTheDocument();
  });

  it("pagination serveur : « Afficher plus » charge la page suivante (offset)", async () => {
    fetchNotificationsMock.mockImplementation((_limit: number, _filter: string, offset: number) =>
      Promise.resolve(offset === 0
        ? { items: [item({ id: 1, title: "Première page" })], hasMore: true, unreadCount: 1, actionRequiredCount: 1 }
        : { items: [item({ id: 2, title: "Deuxième page" })], hasMore: false, unreadCount: 1, actionRequiredCount: 1 }),
    );
    renderCenter();

    await userEvent.click(await screen.findByRole("button", { name: "Afficher plus" }));

    expect(await screen.findByText("Deuxième page")).toBeInTheDocument();
    expect(screen.getByText("Première page")).toBeInTheDocument();
    expect(fetchNotificationsMock).toHaveBeenLastCalledWith(50, "all", 50);
    expect(screen.queryByRole("button", { name: "Afficher plus" })).not.toBeInTheDocument();
  });
});
