import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { RoomAvailabilityNotificationsCard } from "./RoomAvailabilityNotificationsCard";
import { NotificationPreferencesSection } from "./NotificationPreferencesSection";

const fetchNotificationPreferencesMock = vi.fn();
const updateNotificationPreferenceMock = vi.fn();

vi.mock("./api/notifications.api", () => ({
  fetchNotificationPreferences: (...args: unknown[]) => fetchNotificationPreferencesMock(...args),
  updateNotificationPreference: (...args: unknown[]) => updateNotificationPreferenceMock(...args),
}));

let status: "unsupported" | "permission-default" | "permission-denied" | "subscribing" | "subscribed" | "error" = "subscribed";
const subscribeMock = vi.fn();
vi.mock("../push/usePushNotifications", () => ({
  usePushNotifications: () => ({ status, subscribe: subscribeMock, unsubscribe: vi.fn() }),
}));
vi.mock("../pwa-install/pwaInstallDetection", () => ({ detectPlatform: () => "other" }));

const DEFAULTS = { type: "ROOM_SLOT_AVAILABLE", inAppEnabled: true, emailEnabled: false, pushEnabled: true };

function renderWithClient(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

beforeEach(() => {
  status = "subscribed";
  subscribeMock.mockReset();
  fetchNotificationPreferencesMock.mockReset().mockResolvedValue([DEFAULTS]);
  updateNotificationPreferenceMock.mockReset().mockResolvedValue({});
});

describe("RoomAvailabilityNotificationsCard — salles opératoires disponibles (D-161)", () => {
  it("affiche les défauts serveur : push activé, e-mail désactivé", async () => {
    renderWithClient(<RoomAvailabilityNotificationsCard />);

    expect(await screen.findByText("Notifications — Salles opératoires disponibles")).toBeInTheDocument();
    expect(screen.getByRole("switch", { name: "Notifications push — Salles opératoires disponibles" })).toBeChecked();
    expect(screen.getByRole("switch", { name: "Notifications par e-mail — Salles opératoires disponibles" })).not.toBeChecked();
    expect(screen.getByText("Autorisées sur cet appareil.")).toBeInTheDocument();
  });

  it("active puis désactive l'e-mail : seule la préférence e-mail est envoyée au serveur", async () => {
    const user = userEvent.setup();
    renderWithClient(<RoomAvailabilityNotificationsCard />);

    await user.click(await screen.findByRole("switch", { name: "Notifications par e-mail — Salles opératoires disponibles" }));
    await waitFor(() => expect(updateNotificationPreferenceMock).toHaveBeenCalledWith("ROOM_SLOT_AVAILABLE", { emailEnabled: true }));
    expect(updateNotificationPreferenceMock).toHaveBeenCalledTimes(1);
  });

  it("distingue la préférence push de l'autorisation du navigateur et propose de l'accorder", async () => {
    status = "permission-default";
    const user = userEvent.setup();
    renderWithClient(<RoomAvailabilityNotificationsCard />);

    expect(await screen.findByText(/Pas encore autorisées sur cet appareil/)).toBeInTheDocument();
    expect(screen.getByRole("switch", { name: "Notifications push — Salles opératoires disponibles" })).toBeChecked();
    await user.click(screen.getByRole("button", { name: "Autoriser les notifications sur cet appareil" }));
    expect(subscribeMock).toHaveBeenCalledTimes(1);
  });

  it("push refusé par le navigateur : l'état est expliqué et l'e-mail n'est jamais réactivé automatiquement", async () => {
    status = "permission-denied";
    renderWithClient(<RoomAvailabilityNotificationsCard />);

    expect(await screen.findByText(/Bloquées par le navigateur/)).toBeInTheDocument();
    expect(screen.getByText(/n'apparaissent que dans le centre de notifications/)).toBeInTheDocument();
    expect(screen.getByRole("switch", { name: "Notifications par e-mail — Salles opératoires disponibles" })).not.toBeChecked();
    expect(updateNotificationPreferenceMock).not.toHaveBeenCalled();
  });

  it("n'affiche rien pour un compte qui ne reçoit pas cette catégorie", async () => {
    fetchNotificationPreferencesMock.mockResolvedValue([{ type: "PLANNING_ALERT", inAppEnabled: true, emailEnabled: true, pushEnabled: false }]);
    const { container } = renderWithClient(<RoomAvailabilityNotificationsCard />);

    await waitFor(() => expect(fetchNotificationPreferencesMock).toHaveBeenCalled());
    expect(container).toBeEmptyDOMElement();
  });

  it("la liste générique ne propose pas une seconde fois cette catégorie", async () => {
    fetchNotificationPreferencesMock.mockResolvedValue([
      DEFAULTS,
      { type: "PLANNING_ALERT", inAppEnabled: true, emailEnabled: true, pushEnabled: false },
    ]);
    renderWithClient(<NotificationPreferencesSection />);

    expect(await screen.findByText("Alertes planning")).toBeInTheDocument();
    expect(screen.queryByText("Salles opératoires disponibles")).not.toBeInTheDocument();
  });
});
