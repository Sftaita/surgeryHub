import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import NotificationsPage from "./NotificationsPage";

const fetchNotificationsMock = vi.fn();

vi.mock("../../features/notifications/api/notifications.api", () => ({
  fetchNotifications: (...args: unknown[]) => fetchNotificationsMock(...args),
  markNotificationSeen: vi.fn(),
  markAllNotificationsSeen: vi.fn(),
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
});

/**
 * Backé par GET /api/notifications — le backend est l'unique source de vérité (texte, lu/non lu,
 * état métier). Le comportement de la liste est couvert par NotificationCenter.test.tsx.
 */
describe("NotificationsPage (instrumentiste / chirurgien)", () => {
  it("affiche le centre de notifications avec ses filtres", async () => {
    renderPage();
    expect(await screen.findByText("Aucune notification")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Toutes" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Non lues" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "À traiter" })).toBeInTheDocument();
  });

  it("fonctionne même lorsque les notifications Push sont refusées (aucune dépendance à la permission)", async () => {
    Object.defineProperty(window, "Notification", { configurable: true, value: { permission: "denied" } });
    renderPage();
    expect(await screen.findByText("Aucune notification")).toBeInTheDocument();
  });
});
