import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { NotificationPreferencesSection } from "./NotificationPreferencesSection";

const fetchNotificationPreferencesMock = vi.fn();
const updateNotificationPreferenceMock = vi.fn();

vi.mock("./api/notifications.api", () => ({
  fetchNotificationPreferences: (...args: unknown[]) => fetchNotificationPreferencesMock(...args),
  updateNotificationPreference: (...args: unknown[]) => updateNotificationPreferenceMock(...args),
}));

function renderSection() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <NotificationPreferencesSection />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  fetchNotificationPreferencesMock.mockReset();
  updateNotificationPreferenceMock.mockReset().mockResolvedValue({});
});

describe("NotificationPreferencesSection — toggles par catégorie (Lot 3, jusque-là sans UI)", () => {
  it("affiche un libellé lisible pour chaque catégorie retournée par le backend", async () => {
    fetchNotificationPreferencesMock.mockResolvedValue([
      { type: "PLANNING_ALERT", inAppEnabled: true, emailEnabled: true, pushEnabled: false },
      { type: "OPEN_MISSION_AVAILABLE", inAppEnabled: true, emailEnabled: false, pushEnabled: false },
    ]);

    renderSection();

    expect(await screen.findByText("Alertes planning")).toBeInTheDocument();
    expect(screen.getByText("Nouvelles offres disponibles")).toBeInTheDocument();
  });

  it("un type inconnu du frontend retombe sur son identifiant brut plutôt que de casser l'écran", async () => {
    fetchNotificationPreferencesMock.mockResolvedValue([
      { type: "SOME_FUTURE_TYPE", inAppEnabled: true, emailEnabled: true, pushEnabled: false },
    ]);

    renderSection();

    expect(await screen.findByText("SOME_FUTURE_TYPE")).toBeInTheDocument();
  });

  it("bascule le canal e-mail d'une catégorie et appelle l'API de mise à jour", async () => {
    fetchNotificationPreferencesMock.mockResolvedValue([
      { type: "PLANNING_ALERT", inAppEnabled: true, emailEnabled: false, pushEnabled: false },
    ]);
    const user = userEvent.setup();
    renderSection();

    const emailSwitch = await screen.findByRole("switch", { name: "E-mail — Alertes planning" });
    expect(emailSwitch).not.toBeChecked();

    await user.click(emailSwitch);

    await waitFor(() =>
      expect(updateNotificationPreferenceMock).toHaveBeenCalledWith("PLANNING_ALERT", { emailEnabled: true }),
    );
  });

  it("ne casse pas l'écran si le chargement échoue", async () => {
    fetchNotificationPreferencesMock.mockRejectedValue(new Error("network"));
    renderSection();

    await waitFor(() => expect(fetchNotificationPreferencesMock).toHaveBeenCalled());
    expect(document.body.textContent).not.toMatch(/undefined/);
  });
});
