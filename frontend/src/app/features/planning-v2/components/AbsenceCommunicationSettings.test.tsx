import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
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

import * as api from "../api/planningV2.api";

function makeSetting(overrides: Partial<AbsenceCommunicationSiteSettingV2> = {}): AbsenceCommunicationSiteSettingV2 {
  return {
    site: { id: 1, name: "CHIREC - Hôpital Delta" },
    notifyColleaguesEnabled: false,
    notifyBlockManagementEnabled: false,
    blockManagementEmailTo: null,
    blockManagementEmailCc: [],
    blockManagementDelayDays: null,
    ...overrides,
  };
}

function renderSettings() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <AbsenceCommunicationSettings />
    </QueryClientProvider>,
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

describe("AbsenceCommunicationSettings — Gestion du bloc (Lot B, D-114)", () => {
  beforeEach(() => vi.clearAllMocks());

  it("hides the Configurer button and shows no form while the toggle is OFF", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [makeSetting({ site: { id: 1, name: "Delta" } })],
    });

    renderSettings();

    await screen.findByText("Delta");
    expect(screen.queryByRole("button", { name: "Configurer" })).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/Adresse principale/)).not.toBeInTheDocument();
  });

  it("turning the toggle ON calls the API and automatically opens the config form once the list refetches as enabled", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings)
      .mockResolvedValueOnce({ items: [makeSetting({ site: { id: 1, name: "Delta" } })] })
      .mockResolvedValue({ items: [makeSetting({ site: { id: 1, name: "Delta" }, notifyBlockManagementEnabled: true })] });
    vi.mocked(api.updateAbsenceCommunicationSettings).mockResolvedValue(
      makeSetting({ site: { id: 1, name: "Delta" }, notifyBlockManagementEnabled: true }),
    );

    renderSettings();

    const toggle = await screen.findByRole("switch", { name: /Prévenir la gestion du bloc pour le site Delta/ });
    await userEvent.click(toggle);

    await waitFor(() => {
      expect(api.updateAbsenceCommunicationSettings).toHaveBeenCalledWith(1, { notifyBlockManagementEnabled: true });
    });
    // The form only renders once the site is actually reported as enabled — driven by the
    // invalidated query's refetch, not an optimistic local flag the component doesn't keep.
    expect(await screen.findByLabelText(/Adresse principale/)).toBeInTheDocument();
  });

  it("Configurer/Fermer toggles the form for an already-enabled site", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [makeSetting({
        site: { id: 1, name: "Delta" }, notifyBlockManagementEnabled: true,
        blockManagementEmailTo: "bloc@example.com", blockManagementDelayDays: 14,
      })],
    });

    renderSettings();

    const configureBtn = await screen.findByRole("button", { name: "Configurer" });
    await userEvent.click(configureBtn);
    expect(await screen.findByLabelText(/Adresse principale/)).toHaveValue("bloc@example.com");

    await userEvent.click(screen.getByRole("button", { name: "Fermer" }));
    expect(screen.queryByLabelText(/Adresse principale/)).not.toBeInTheDocument();
  });

  it("an invalid To address disables Enregistrer", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [makeSetting({ site: { id: 1, name: "Delta" }, notifyBlockManagementEnabled: true, blockManagementDelayDays: 14 })],
    });

    renderSettings();

    await userEvent.click(await screen.findByRole("button", { name: "Configurer" }));
    const toField = screen.getByLabelText(/Adresse principale/);
    await userEvent.type(toField, "pas-un-email");

    expect(screen.getByRole("button", { name: "Enregistrer" })).toBeDisabled();
  });

  it("an out-of-range delay disables Enregistrer, a valid one enables it", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [makeSetting({ site: { id: 1, name: "Delta" }, notifyBlockManagementEnabled: true, blockManagementEmailTo: "bloc@example.com" })],
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

  it("adds a valid CC address and rejects an invalid or duplicate one", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [makeSetting({
        site: { id: 1, name: "Delta" }, notifyBlockManagementEnabled: true,
        blockManagementEmailTo: "bloc@example.com", blockManagementEmailCc: ["existing@example.com"], blockManagementDelayDays: 14,
      })],
    });

    renderSettings();

    await userEvent.click(await screen.findByRole("button", { name: "Configurer" }));
    const ccInput = screen.getByPlaceholderText("ajouter une adresse CC");
    const addBtn = screen.getByRole("button", { name: "Ajouter" });

    // Invalid format — rejected, never added.
    await userEvent.type(ccInput, "pas-un-email");
    await userEvent.click(addBtn);
    expect(toastError).toHaveBeenCalledWith("Adresse email invalide.");
    expect(screen.queryByText("pas-un-email")).not.toBeInTheDocument();

    // Duplicate of an existing CC — rejected.
    await userEvent.clear(ccInput);
    await userEvent.type(ccInput, "existing@example.com");
    await userEvent.click(addBtn);
    expect(toastError).toHaveBeenCalledWith("Cette adresse figure déjà dans la liste.");

    // Duplicate of the To address — rejected.
    await userEvent.clear(ccInput);
    await userEvent.type(ccInput, "bloc@example.com");
    await userEvent.click(addBtn);
    expect(toastError).toHaveBeenCalledWith("Cette adresse figure déjà dans la liste.");

    // A genuinely new address is added.
    await userEvent.clear(ccInput);
    await userEvent.type(ccInput, "new@example.com");
    await userEvent.click(addBtn);
    expect(await screen.findByText("new@example.com")).toBeInTheDocument();
    expect(ccInput).toHaveValue("");
  });

  it("removes a CC address via its delete button", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [makeSetting({
        site: { id: 1, name: "Delta" }, notifyBlockManagementEnabled: true,
        blockManagementEmailTo: "bloc@example.com", blockManagementEmailCc: ["secretariat@example.com"], blockManagementDelayDays: 14,
      })],
    });

    renderSettings();

    await userEvent.click(await screen.findByRole("button", { name: "Configurer" }));
    expect(await screen.findByText("secretariat@example.com")).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "Supprimer secretariat@example.com" }));
    expect(screen.queryByText("secretariat@example.com")).not.toBeInTheDocument();
  });

  it("Enregistrer sends the exact payload, shows a success toast, and closes the form", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [makeSetting({ site: { id: 1, name: "Delta" }, notifyBlockManagementEnabled: true, blockManagementEmailCc: ["cc@example.com"] })],
    });
    vi.mocked(api.updateAbsenceCommunicationSettings).mockResolvedValue(
      makeSetting({ site: { id: 1, name: "Delta" }, notifyBlockManagementEnabled: true }),
    );

    renderSettings();

    await userEvent.click(await screen.findByRole("button", { name: "Configurer" }));
    await userEvent.type(screen.getByLabelText(/Adresse principale/), "bloc@example.com");
    await userEvent.type(screen.getByLabelText(/Envoyer X jours avant le début du congé/), "10");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    await waitFor(() => {
      expect(api.updateAbsenceCommunicationSettings).toHaveBeenCalledWith(1, {
        blockManagementEmailTo: "bloc@example.com",
        blockManagementEmailCc: ["cc@example.com"],
        blockManagementDelayDays: 10,
      });
      expect(toastSuccess).toHaveBeenCalled();
    });
    await waitFor(() => {
      expect(screen.queryByLabelText(/Adresse principale/)).not.toBeInTheDocument();
    });
  });

  it("a failed save shows an error toast and keeps the form open with the entered values", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [makeSetting({ site: { id: 1, name: "Delta" }, notifyBlockManagementEnabled: true })],
    });
    vi.mocked(api.updateAbsenceCommunicationSettings).mockRejectedValue(new Error("network error"));

    renderSettings();

    await userEvent.click(await screen.findByRole("button", { name: "Configurer" }));
    await userEvent.type(screen.getByLabelText(/Adresse principale/), "bloc@example.com");
    await userEvent.type(screen.getByLabelText(/Envoyer X jours avant le début du congé/), "10");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    await waitFor(() => {
      expect(toastError).toHaveBeenCalled();
    });
    expect(screen.getByLabelText(/Adresse principale/)).toHaveValue("bloc@example.com");
  });

  it("Annuler closes the form without calling the API", async () => {
    vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
      items: [makeSetting({ site: { id: 1, name: "Delta" }, notifyBlockManagementEnabled: true, blockManagementEmailTo: "bloc@example.com", blockManagementDelayDays: 14 })],
    });

    renderSettings();

    await userEvent.click(await screen.findByRole("button", { name: "Configurer" }));
    await userEvent.type(screen.getByLabelText(/Adresse principale/), "extra text");
    await userEvent.click(screen.getByRole("button", { name: "Annuler" }));

    expect(screen.queryByLabelText(/Adresse principale/)).not.toBeInTheDocument();
    expect(api.updateAbsenceCommunicationSettings).not.toHaveBeenCalled();
  });
});
