import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { GeneratePlanningTab } from "./GeneratePlanningTab";
import type { PreviewLineV2, PreviewResponseV2 } from "../api/planningV2.types";

vi.mock("../../sites/api/sites.api", () => ({
  fetchSites: vi.fn().mockResolvedValue([{ id: 1, name: "Delta" }]),
}));

vi.mock("../../planning-manager/api/planning.api", () => ({
  listPlanningVersions: vi.fn().mockResolvedValue({ items: [], total: 0, page: 1, limit: 10 }),
}));

vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}));

vi.mock("../api/planningV2.api", () => ({
  getSiteGroups: vi.fn().mockResolvedValue({ items: [] }),
  getSurgeonPosts: vi.fn().mockResolvedValue({ items: [{ id: 1 }, { id: 2 }] }),
  previewPlanningV2: vi.fn(),
  generatePlanningV2: vi.fn(),
  deployPlanningV2: vi.fn(),
  extractErrorV2: (e: unknown) => String(e),
}));

import * as planningV2Api from "../api/planningV2.api";

function line(overrides: Partial<PreviewLineV2>): PreviewLineV2 {
  return {
    date: "2026-06-01", postId: 1, surgeonId: 1, surgeonName: "Dr Martin",
    missionType: "BLOCK", startTime: "08:00", endTime: "13:00",
    siteId: 1, siteName: "Delta", instrumentistId: null, instrumentistName: null,
    status: "COVERED", existingMissionId: null, existingInstrumentistId: null,
    existingInstrumentistName: null, freedFrom: false,
    ...overrides,
  };
}

function renderTab() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <GeneratePlanningTab />
    </QueryClientProvider>,
  );
}

async function selectSite(user: ReturnType<typeof userEvent.setup>) {
  const label = screen.getByText("Site ou groupe de sites");
  const container = label.closest("div")!;
  const input = container.querySelector("input")!;
  await user.click(input);
  await user.click(await screen.findByText("Delta"));
}

describe("GeneratePlanningTab — sélection multi-mois", () => {
  it("démarre avec le mois courant sélectionné et le bouton Prévisualiser activé une fois un site choisi", async () => {
    const user = userEvent.setup();
    renderTab();

    const previewBtn = screen.getByRole("button", { name: "Prévisualiser" });
    expect(previewBtn).toBeDisabled();

    await selectSite(user);
    await waitFor(() => expect(previewBtn).toBeEnabled());
  });

  it("désactive Prévisualiser si tous les mois sont désélectionnés", async () => {
    const user = userEvent.setup();
    renderTab();
    await selectSite(user);

    // Deselect the only initially-selected month chip (current month).
    const chips = screen.getAllByRole("button").filter((b) => /\d{4}/.test(b.textContent ?? ""));
    expect(chips.length).toBeGreaterThan(0);
    await user.click(chips[0]);

    await waitFor(() => expect(screen.getByRole("button", { name: "Prévisualiser" })).toBeDisabled());
  });

  it("regroupe la prévisualisation par jour puis par chirurgien, avec filtres cliquables", async () => {
    const user = userEvent.setup();
    const preview: PreviewResponseV2 = {
      lines: [
        line({ date: "2026-06-01", surgeonId: 1, surgeonName: "Dr Martin", status: "COVERED", instrumentistId: 9, instrumentistName: "Diane Lefebvre" }),
        line({ date: "2026-06-01", surgeonId: 2, surgeonName: "Dr Dupont", status: "CONFLICT", postId: 2 }),
      ],
      summary: { total: 2, covered: 1, uncovered: 0, skipped: 0, conflict: 1, modified: 0 },
    };
    (planningV2Api.previewPlanningV2 as ReturnType<typeof vi.fn>).mockResolvedValue(preview);

    renderTab();
    await selectSite(user);
    await user.click(screen.getByRole("button", { name: "Prévisualiser" }));

    expect(await screen.findByText("Dr Martin")).toBeInTheDocument();
    expect(screen.getByText("Dr Dupont")).toBeInTheDocument();
    expect(screen.getByText("Diane Lefebvre")).toBeInTheDocument();
    expect(screen.getByText("À pourvoir")).toBeInTheDocument();

    // "Conflits" filter (count 1) hides the COVERED line for Dr Martin.
    await user.click(screen.getByText("Conflits"));
    await waitFor(() => expect(screen.queryByText("Dr Martin")).not.toBeInTheDocument());
    expect(screen.getByText("Dr Dupont")).toBeInTheDocument();
  });

  it("affiche un état vide quand le filtre actif ne renvoie aucun poste", async () => {
    const user = userEvent.setup();
    const preview: PreviewResponseV2 = {
      lines: [line({ status: "COVERED" })],
      summary: { total: 1, covered: 1, uncovered: 0, skipped: 0, conflict: 0, modified: 0 },
    };
    (planningV2Api.previewPlanningV2 as ReturnType<typeof vi.fn>).mockResolvedValue(preview);

    renderTab();
    await selectSite(user);
    await user.click(screen.getByRole("button", { name: "Prévisualiser" }));
    await screen.findByText("Dr Martin");

    await user.click(screen.getByText("Conflits"));
    expect(await screen.findByText(/Aucun poste dans ce filtre/)).toBeInTheDocument();
  });
});
