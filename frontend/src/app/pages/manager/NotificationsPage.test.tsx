import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import NotificationsPage from "./NotificationsPage";

const fetchNotificationsMock = vi.fn();
const markAllNotificationsSeenMock = vi.fn();

vi.mock("../../features/notifications/api/notifications.api", () => ({
  fetchNotifications: (...args: unknown[]) => fetchNotificationsMock(...args),
  markNotificationSeen: vi.fn(),
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
  fetchNotificationsMock.mockReset().mockResolvedValue({ items: [], unreadCount: 0, actionRequiredCount: 0 });
  markAllNotificationsSeenMock.mockReset();
});

/** Le comportement de la liste est couvert par NotificationCenter.test.tsx. */
describe("NotificationsPage (manager/admin)", () => {
  it("affiche l'en-tête et le centre de notifications", async () => {
    renderPage();
    expect(screen.getByRole("heading", { name: "Notifications" })).toBeInTheDocument();
    expect(await screen.findByText("Aucune notification")).toBeInTheDocument();
  });

  it("ne marque rien comme lu au chargement, même avec des non-lues", async () => {
    fetchNotificationsMock.mockResolvedValue({
      unreadCount: 2,
      actionRequiredCount: 0,
      items: [
        { id: 1, eventType: "PLANNING_MISSION_CANCELLED", missionId: null, title: "Mission annulée", body: "", category: "mission", actionLabel: null, businessState: "INFO", stateLabel: null, legacy: false, missions: null, targetUrl: null, payload: null, sentAt: null, seenAt: null },
      ],
    });
    renderPage();
    expect(await screen.findByText("Mission annulée")).toBeInTheDocument();
    expect(markAllNotificationsSeenMock).not.toHaveBeenCalled();
  });
});
