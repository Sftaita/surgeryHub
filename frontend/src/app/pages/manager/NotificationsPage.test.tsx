import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import NotificationsPage from "./NotificationsPage";

const fetchNotificationsMock = vi.fn();
const markNotificationSeenMock = vi.fn();
const markAllNotificationsSeenMock = vi.fn();

vi.mock("../../features/notifications/api/notifications.api", () => ({
  fetchNotifications: (...args: unknown[]) => fetchNotificationsMock(...args),
  markNotificationSeen: (...args: unknown[]) => markNotificationSeenMock(...args),
  markAllNotificationsSeen: (...args: unknown[]) => markAllNotificationsSeenMock(...args),
}));

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <NotificationsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  fetchNotificationsMock.mockReset().mockResolvedValue({ items: [], unreadCount: 0 });
  markNotificationSeenMock.mockReset().mockResolvedValue({});
  markAllNotificationsSeenMock.mockReset().mockResolvedValue({ updated: 0 });
});

describe("NotificationsPage (manager/admin) — absent auparavant (Lot 3)", () => {
  it("affiche un état vide quand il n'y a aucune notification", async () => {
    renderPage();
    expect(await screen.findByText("Aucune notification")).toBeInTheDocument();
  });

  it("affiche la liste des notifications avec titre/corps dérivés de l'eventType", async () => {
    fetchNotificationsMock.mockResolvedValue({
      unreadCount: 1,
      items: [
        {
          id: 1,
          eventType: "PLANNING_ALERT_REASSIGNED_TO",
          missionId: 42,
          payload: { siteName: "Clinique Test", missionDate: "2026-08-01" },
          sentAt: "2026-07-29T10:00:00+00:00",
          seenAt: null,
        },
      ],
    });

    renderPage();

    expect(await screen.findByText("Nouvelle mission assignée")).toBeInTheDocument();
    expect(screen.getByText("2026-08-01 — Clinique Test")).toBeInTheDocument();
  });

  it("marque automatiquement tout comme lu au chargement réussi de l'écran", async () => {
    fetchNotificationsMock.mockResolvedValue({
      unreadCount: 2,
      items: [
        { id: 1, eventType: "MISSION_REASSIGNED", missionId: null, payload: null, sentAt: null, seenAt: null },
        { id: 2, eventType: "MISSION_CANCELLED", missionId: null, payload: null, sentAt: null, seenAt: null },
      ],
    });

    renderPage();

    await waitFor(() => expect(markAllNotificationsSeenMock).toHaveBeenCalledTimes(1));
  });

  it("ne tente aucun marquage automatique si tout est déjà lu", async () => {
    fetchNotificationsMock.mockResolvedValue({
      unreadCount: 0,
      items: [{ id: 1, eventType: "MISSION_REASSIGNED", missionId: null, payload: null, sentAt: null, seenAt: "2026-07-29T10:00:00+00:00" }],
    });

    renderPage();
    await screen.findByText("Mission réassignée");

    expect(markAllNotificationsSeenMock).not.toHaveBeenCalled();
  });

  it("un type d'événement inconnu retombe sur un texte générique, sans écran cassé", async () => {
    fetchNotificationsMock.mockResolvedValue({
      unreadCount: 1,
      items: [{ id: 1, eventType: "SOME_FUTURE_EVENT_TYPE", missionId: null, payload: null, sentAt: null, seenAt: null }],
    });

    renderPage();

    expect(await screen.findByText("Notification")).toBeInTheDocument();
  });

  it("le bouton « Tout marquer comme lu » déclenche le marquage manuel", async () => {
    fetchNotificationsMock.mockResolvedValue({
      unreadCount: 1,
      items: [{ id: 1, eventType: "MISSION_REASSIGNED", missionId: null, payload: null, sentAt: null, seenAt: null }],
    });
    const user = userEvent.setup();
    renderPage();

    await waitFor(() => expect(markAllNotificationsSeenMock).toHaveBeenCalledTimes(1));
    const button = await screen.findByRole("button", { name: "Tout marquer comme lu" });
    await user.click(button);

    await waitFor(() => expect(markAllNotificationsSeenMock).toHaveBeenCalledTimes(2));
  });
});
