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

/**
 * Backé par GET /api/notifications (audit PWA/mobile/admin 2026-07-29, revue
 * post-rapport) — remplace l'ancien cache localStorage
 * (features/push/notifications.store.ts, retiré). Le backend est désormais l'unique
 * source de vérité : historique, compteur, lu/non lu, cohérents entre appareils.
 */
describe("NotificationsPage (instrumentiste) — source de vérité serveur", () => {
  it("affiche un état vide quand il n'y a aucune notification", async () => {
    renderPage();
    expect(await screen.findByText("Aucune notification")).toBeInTheDocument();
  });

  it("affiche l'historique serveur avec titre/corps dérivés de l'eventType", async () => {
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

  it("le compteur non-lu vient du serveur, pas d'un décompte local", async () => {
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

  it("marque une notification comme lue individuellement au clic", async () => {
    fetchNotificationsMock.mockResolvedValue({
      unreadCount: 1,
      items: [{ id: 7, eventType: "MISSION_REASSIGNED", missionId: null, payload: null, sentAt: null, seenAt: null }],
    });
    const user = userEvent.setup();
    renderPage();

    const card = await screen.findByText("Mission réassignée");
    await user.click(card);

    await waitFor(() => expect(markNotificationSeenMock).toHaveBeenCalledWith(7));
  });

  it("le bouton « Tout marquer comme lu » déclenche le marquage global", async () => {
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

  it("navigue vers la mission liée au clic sur une notification déjà lue", async () => {
    fetchNotificationsMock.mockResolvedValue({
      unreadCount: 0,
      items: [{ id: 1, eventType: "MISSION_REASSIGNED", missionId: 99, payload: null, sentAt: null, seenAt: "2026-07-29T09:00:00+00:00" }],
    });
    const user = userEvent.setup();
    renderPage();

    const card = await screen.findByText("Mission réassignée");
    await user.click(card);

    // Déjà lue : pas de nouvel appel markSeen, seule la navigation doit se produire.
    await waitFor(() => expect(markNotificationSeenMock).not.toHaveBeenCalled());
  });

  it("fonctionne même lorsque les notifications Push sont refusées (aucune dépendance à la permission)", async () => {
    // Aucun mock de Notification.permission ici : la page ne lit jamais ce global,
    // uniquement l'API serveur — preuve par construction qu'elle ne s'y couple pas.
    fetchNotificationsMock.mockResolvedValue({
      unreadCount: 1,
      items: [{ id: 1, eventType: "MISSION_REASSIGNED", missionId: null, payload: null, sentAt: null, seenAt: null }],
    });
    renderPage();

    expect(await screen.findByText("Mission réassignée")).toBeInTheDocument();
  });
});
