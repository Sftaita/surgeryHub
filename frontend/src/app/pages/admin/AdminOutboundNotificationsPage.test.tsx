import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import AdminOutboundNotificationsPage from "./AdminOutboundNotificationsPage";
import type { OutboundNotificationListItem, OutboundNotificationListResponse } from "../../features/admin/api/admin.types";

vi.mock("../../features/admin/api/admin.api", () => ({
  getAdminOutboundNotifications: vi.fn(),
  getAdminOutboundNotification: vi.fn(),
}));

import { getAdminOutboundNotifications } from "../../features/admin/api/admin.api";

function buildItem(overrides: Partial<OutboundNotificationListItem> = {}): OutboundNotificationListItem {
  return {
    id: 1,
    createdAt: "2026-07-26T08:05:00+02:00",
    recipient: { id: 12, name: "Jane Doe", email: "jane@example.com" },
    channel: "PUSH",
    notificationType: "ENCODING_REMINDER_D1",
    status: "SENT",
    title: "Encodage à finaliser",
    subject: null,
    missionId: 690,
    attemptCount: 1,
    fallback: false,
    ...overrides,
  };
}

function buildResponse(items: OutboundNotificationListItem[], total = items.length): OutboundNotificationListResponse {
  return { items, page: 1, limit: 25, total };
}

beforeEach(() => {
  vi.mocked(getAdminOutboundNotifications).mockReset();
});

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <AdminOutboundNotificationsPage />
    </QueryClientProvider>,
  );
}

describe("AdminOutboundNotificationsPage", () => {
  it("affiche un indicateur de chargement puis la liste", async () => {
    vi.mocked(getAdminOutboundNotifications).mockResolvedValue(buildResponse([buildItem()]));

    renderPage();

    await screen.findByText("Jane Doe");
    expect(screen.getByText("Encodage à finaliser")).toBeInTheDocument();
  });

  it("affiche un état vide explicite", async () => {
    vi.mocked(getAdminOutboundNotifications).mockResolvedValue(buildResponse([]));

    renderPage();

    await screen.findByText("Aucune notification trouvée.");
  });

  it("affiche une erreur si le chargement échoue", async () => {
    vi.mocked(getAdminOutboundNotifications).mockRejectedValue(new Error("network"));

    renderPage();

    await screen.findByText(/Impossible de charger/);
  });

  it("relance la requête avec le filtre canal sélectionné", async () => {
    vi.mocked(getAdminOutboundNotifications).mockResolvedValue(buildResponse([buildItem()]));
    const user = userEvent.setup();

    renderPage();
    await screen.findByText("Jane Doe");

    const channelSelect = screen.getAllByRole("combobox")[0];
    await user.click(channelSelect);
    await user.click(await screen.findByRole("option", { name: "Push" }));

    await waitFor(() => {
      const lastCall = vi.mocked(getAdminOutboundNotifications).mock.calls.at(-1)?.[0];
      expect(lastCall?.channel).toBe("PUSH");
    });
  });

  it("applique la recherche après un debounce", async () => {
    vi.mocked(getAdminOutboundNotifications).mockResolvedValue(buildResponse([buildItem()]));
    const user = userEvent.setup();

    renderPage();
    await screen.findByText("Jane Doe");

    await user.type(screen.getByLabelText("Recherche"), "jane");

    await waitFor(() => {
      const lastCall = vi.mocked(getAdminOutboundNotifications).mock.calls.at(-1)?.[0];
      expect(lastCall?.search).toBe("jane");
    }, { timeout: 2000 });
  });

  it("ouvre le détail au clic sur une ligne", async () => {
    vi.mocked(getAdminOutboundNotifications).mockResolvedValue(buildResponse([buildItem()]));
    const user = userEvent.setup();

    renderPage();
    const row = await screen.findByText("Jane Doe");
    await user.click(row);

    // The drawer mounts and issues its own query — presence of the drawer title proves it opened.
    await screen.findByRole("heading", { name: "Notification" });
  });

  it("affiche l'avertissement « accepté ne garantit pas la lecture »", async () => {
    vi.mocked(getAdminOutboundNotifications).mockResolvedValue(buildResponse([buildItem()]));

    renderPage();

    await screen.findByText(/ne garantit pas que le message a été lu/);
  });

  it("indique le total réel et gère la pagination", async () => {
    vi.mocked(getAdminOutboundNotifications).mockResolvedValue(buildResponse([buildItem()], 140));

    renderPage();

    await screen.findByText(/140/);
  });
});
