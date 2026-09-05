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

    expect(screen.getByRole("switch", { name: /Delta/ })).toHaveAttribute("aria-checked", "true");
    expect(screen.getByRole("switch", { name: /BOSI/ })).toHaveAttribute("aria-checked", "false");
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

    const toggle = await screen.findByRole("switch", { name: /Delta/ });
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

    const toggle = await screen.findByRole("switch", { name: /Delta/ });
    await userEvent.click(toggle);

    await waitFor(() => {
      expect(toastError).toHaveBeenCalled();
    });
    expect(screen.getByRole("switch", { name: /Delta/ })).toHaveAttribute("aria-checked", "false");
  });
});
