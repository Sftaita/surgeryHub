import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import { AbsenceCommunicationSettings } from "./AbsenceCommunicationSettings";
import type { AbsenceCommunicationSiteSettingV2 } from "../api/planningV2.types";

vi.mock("../api/planningV2.api", async () => {
  const actual = await vi.importActual<typeof import("../api/planningV2.api")>("../api/planningV2.api");
  return {
    ...actual,
    getAbsenceCommunicationSettings: vi.fn(),
    updateAbsenceCommunicationSettings: vi.fn(),
  };
});

const toastSuccess = vi.fn();
const toastError = vi.fn();
vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccess, error: toastError, warning: vi.fn() }),
}));

const navigateMock = vi.fn();
vi.mock("react-router-dom", async () => {
  const actual = await vi.importActual<typeof import("react-router-dom")>("react-router-dom");
  return { ...actual, useNavigate: () => navigateMock };
});

import * as api from "../api/planningV2.api";

function makeSetting(overrides: Partial<AbsenceCommunicationSiteSettingV2> = {}): AbsenceCommunicationSiteSettingV2 {
  return {
    site: { id: 1, name: "CHIREC - Hôpital Delta" },
    notifyColleaguesEnabled: false,
    notifyBlockManagementEnabled: false,
    blockManagementContactEmail: null,
    blockManagementContactCc: [],
    blockManagementDelayDays: null,
    ...overrides,
  };
}

function renderSettings() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <MemoryRouter>
      <QueryClientProvider client={client}>
        <AbsenceCommunicationSettings />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

describe("AbsenceCommunicationSettings — Lot A (D-114)", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders one row per site with the correct initial toggle state", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [
        makeSetting({ site: { id: 1, name: "Delta" }, notifyColleaguesEnabled: true }),
        makeSetting({ site: { id: 2, name: "BOSI" }, notifyColleaguesEnabled: false }),
      ],
    });

    renderSettings();

    await waitFor(() => {
      expect(screen.getByText("Delta")).toBeInTheDocument();
      expect(screen.getByText("BOSI")).toBeInTheDocument();
    });

    expect(screen.getByRole("switch", { name: /Informer les chirurgiens du site Delta/ })).toHaveAttribute("aria-checked", "true");
    expect(screen.getByRole("switch", { name: /Informer les chirurgiens du site BOSI/ })).toHaveAttribute("aria-checked", "false");
  });

  it("shows an empty state when no site exists", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({ items: [] });

    renderSettings();

    await waitFor(() => {
      expect(screen.getByText("Aucun site configuré.")).toBeInTheDocument();
    });
  });

  it("toggling the switch calls the API with the flipped value and shows a success toast", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [makeSetting({ site: { id: 1, name: "Delta" }, notifyColleaguesEnabled: false })],
    });
    vi.mocked(api.updateAbsenceCommunicationSettings).mockResolvedValue(
      makeSetting({ site: { id: 1, name: "Delta" }, notifyColleaguesEnabled: true }),
    );

    renderSettings();

    const toggle = await screen.findByRole("switch", { name: /Informer les chirurgiens du site Delta/ });
    await userEvent.click(toggle);

    await waitFor(() => {
      expect(api.updateAbsenceCommunicationSettings).toHaveBeenCalledWith(1, { notifyColleaguesEnabled: true });
      expect(toastSuccess).toHaveBeenCalled();
    });
  });

  it("shows an error toast and leaves the list intact when the update fails", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [makeSetting({ site: { id: 1, name: "Delta" }, notifyColleaguesEnabled: false })],
    });
    vi.mocked(api.updateAbsenceCommunicationSettings).mockRejectedValue(new Error("network error"));

    renderSettings();

    const toggle = await screen.findByRole("switch", { name: /Informer les chirurgiens du site Delta/ });
    await userEvent.click(toggle);

    await waitFor(() => {
      expect(toastError).toHaveBeenCalled();
    });
    expect(screen.getByRole("switch", { name: /Informer les chirurgiens du site Delta/ })).toHaveAttribute("aria-checked", "false");
  });
});

describe("AbsenceCommunicationSettings — Gestion du bloc (Lot B, D-114, revue post-déploiement)", () => {
  beforeEach(() => { vi.clearAllMocks(); navigateMock.mockReset(); });

  it("hides the Configurer button and shows no delay form while the toggle is OFF", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [makeSetting({ site: { id: 1, name: "Delta" } })],
    });

    renderSettings();

    await screen.findByText("Delta");
    expect(screen.queryByRole("button", { name: "Configurer" })).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/Envoyer X jours/)).not.toBeInTheDocument();
  });

  it("displays the establishment's current contact read-only, with a link to edit it", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [makeSetting({
        site: { id: 1, name: "Delta" },
        blockManagementContactEmail: "bloc@delta.test", blockManagementContactCc: ["secretariat@delta.test", "coordination@delta.test"],
      })],
    });

    renderSettings();

    expect(await screen.findByText("bloc@delta.test")).toBeInTheDocument();
    expect(screen.getByText("secretariat@delta.test, coordination@delta.test")).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "Modifier les contacts de l'établissement" }));
    expect(navigateMock).toHaveBeenCalledWith("/app/m/hospitals?edit=1");
  });

  it("shows a placeholder when the establishment has no contact configured", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [makeSetting({ site: { id: 1, name: "Delta" }, blockManagementContactEmail: null })],
    });

    renderSettings();

    expect(await screen.findByText("Aucun contact configuré pour cet établissement.")).toBeInTheDocument();
  });

  // ── Verrou circulaire corrigé (audit) ───────────────────────────────────────

  it("activating a site with no established contact never sends the toggle-only PATCH — shows a blocking message with a link instead", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [makeSetting({ site: { id: 1, name: "Delta" }, blockManagementContactEmail: null })],
    });

    renderSettings();

    const toggle = await screen.findByRole("switch", { name: /Prévenir la gestion du bloc pour le site Delta/ });
    await userEvent.click(toggle);

    expect(api.updateAbsenceCommunicationSettings).not.toHaveBeenCalled();
    expect(await screen.findByText("Configurez d'abord l'adresse de la gestion du bloc dans la fiche de l'établissement.")).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "Configurer l'établissement" }));
    expect(navigateMock).toHaveBeenCalledWith("/app/m/hospitals?edit=1");
  });

  it("activating a site with a valid established contact opens the delay-only form, never a toggle-only PATCH", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [makeSetting({ site: { id: 1, name: "Delta" }, blockManagementContactEmail: "bloc@delta.test" })],
    });

    renderSettings();

    const toggle = await screen.findByRole("switch", { name: /Prévenir la gestion du bloc pour le site Delta/ });
    await userEvent.click(toggle);

    expect(api.updateAbsenceCommunicationSettings).not.toHaveBeenCalled();
    expect(await screen.findByLabelText(/Envoyer X jours avant le début du congé/)).toBeInTheDocument();
    expect(screen.getAllByText(/bloc@delta.test/).length).toBeGreaterThan(0);
  });

  it("saving the initial activation sends notifyBlockManagementEnabled and the delay together in one PATCH", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [makeSetting({ site: { id: 1, name: "Delta" }, blockManagementContactEmail: "bloc@delta.test" })],
    });
    vi.mocked(api.updateAbsenceCommunicationSettings).mockResolvedValue(
      makeSetting({ site: { id: 1, name: "Delta" }, notifyBlockManagementEnabled: true, blockManagementContactEmail: "bloc@delta.test", blockManagementDelayDays: 10 }),
    );

    renderSettings();

    await userEvent.click(await screen.findByRole("switch", { name: /Prévenir la gestion du bloc pour le site Delta/ }));
    await userEvent.type(await screen.findByLabelText(/Envoyer X jours avant le début du congé/), "10");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    await waitFor(() => {
      expect(api.updateAbsenceCommunicationSettings).toHaveBeenCalledWith(1, {
        notifyBlockManagementEnabled: true,
        blockManagementDelayDays: 10,
      });
      expect(toastSuccess).toHaveBeenCalled();
    });
  });

  it("turning OFF an already-enabled site sends the toggle-only PATCH immediately (always valid)", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [makeSetting({ site: { id: 1, name: "Delta" }, notifyBlockManagementEnabled: true, blockManagementContactEmail: "bloc@delta.test", blockManagementDelayDays: 14 })],
    });
    vi.mocked(api.updateAbsenceCommunicationSettings).mockResolvedValue(
      makeSetting({ site: { id: 1, name: "Delta" }, notifyBlockManagementEnabled: false }),
    );

    renderSettings();

    const toggle = await screen.findByRole("switch", { name: /Prévenir la gestion du bloc pour le site Delta/ });
    await userEvent.click(toggle);

    await waitFor(() => {
      expect(api.updateAbsenceCommunicationSettings).toHaveBeenCalledWith(1, { notifyBlockManagementEnabled: false });
    });
  });

  // ── Réglage du délai pour un site déjà activé ───────────────────────────────

  it("Configurer/Fermer toggles the delay form for an already-enabled site", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [makeSetting({
        site: { id: 1, name: "Delta" }, notifyBlockManagementEnabled: true,
        blockManagementContactEmail: "bloc@example.com", blockManagementDelayDays: 14,
      })],
    });

    renderSettings();

    const configureBtn = await screen.findByRole("button", { name: "Configurer" });
    await userEvent.click(configureBtn);
    expect(await screen.findByLabelText(/Envoyer X jours avant le début du congé/)).toHaveValue(14);

    await userEvent.click(screen.getByRole("button", { name: "Fermer" }));
    expect(screen.queryByLabelText(/Envoyer X jours/)).not.toBeInTheDocument();
  });

  it("an out-of-range delay disables Enregistrer, a valid one enables it", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [makeSetting({ site: { id: 1, name: "Delta" }, notifyBlockManagementEnabled: true, blockManagementContactEmail: "bloc@example.com" })],
    });

    renderSettings();

    await userEvent.click(await screen.findByRole("button", { name: "Configurer" }));
    const delayField = screen.getByLabelText(/Envoyer X jours avant le début du congé/);

    await userEvent.type(delayField, "400");
    expect(screen.getByRole("button", { name: "Enregistrer" })).toBeDisabled();

    await userEvent.clear(delayField);
    await userEvent.type(delayField, "14");
    expect(screen.getByRole("button", { name: "Enregistrer" })).toBeEnabled();
  });

  it("Enregistrer sends only the delay for an already-enabled site, shows a success toast, and closes the form", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [makeSetting({ site: { id: 1, name: "Delta" }, notifyBlockManagementEnabled: true, blockManagementContactEmail: "bloc@example.com" })],
    });
    vi.mocked(api.updateAbsenceCommunicationSettings).mockResolvedValue(
      makeSetting({ site: { id: 1, name: "Delta" }, notifyBlockManagementEnabled: true }),
    );

    renderSettings();

    await userEvent.click(await screen.findByRole("button", { name: "Configurer" }));
    await userEvent.type(screen.getByLabelText(/Envoyer X jours avant le début du congé/), "10");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    await waitFor(() => {
      expect(api.updateAbsenceCommunicationSettings).toHaveBeenCalledWith(1, { blockManagementDelayDays: 10 });
      expect(toastSuccess).toHaveBeenCalled();
    });
    await waitFor(() => {
      expect(screen.queryByLabelText(/Envoyer X jours/)).not.toBeInTheDocument();
    });
  });

  it("a failed save shows an error toast and keeps the form open with the entered value", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [makeSetting({ site: { id: 1, name: "Delta" }, notifyBlockManagementEnabled: true, blockManagementContactEmail: "bloc@example.com" })],
    });
    vi.mocked(api.updateAbsenceCommunicationSettings).mockRejectedValue(new Error("network error"));

    renderSettings();

    await userEvent.click(await screen.findByRole("button", { name: "Configurer" }));
    await userEvent.type(screen.getByLabelText(/Envoyer X jours avant le début du congé/), "10");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    await waitFor(() => {
      expect(toastError).toHaveBeenCalled();
    });
    expect(screen.getByLabelText(/Envoyer X jours avant le début du congé/)).toHaveValue(10);
  });

  it("Annuler closes the delay form without calling the API", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [makeSetting({ site: { id: 1, name: "Delta" }, notifyBlockManagementEnabled: true, blockManagementContactEmail: "bloc@example.com", blockManagementDelayDays: 14 })],
    });

    renderSettings();

    await userEvent.click(await screen.findByRole("button", { name: "Configurer" }));
    await userEvent.type(screen.getByLabelText(/Envoyer X jours avant le début du congé/), "5");
    await userEvent.click(screen.getByRole("button", { name: "Annuler" }));

    expect(screen.queryByLabelText(/Envoyer X jours/)).not.toBeInTheDocument();
    expect(api.updateAbsenceCommunicationSettings).not.toHaveBeenCalled();
  });
});
