import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
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

vi.mock("../../../api/apiClient", () => ({
  apiClient: {
    get: vi.fn((url: string) => {
      if (url === "/api/instrumentists") {
        return Promise.resolve({ data: { items: [{ id: 9, displayName: "Diane Lefebvre" }, { id: 10, displayName: "Marc Petit" }] } });
      }
      return Promise.resolve({ data: { items: [] } });
    }),
  },
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
    <MemoryRouter>
      <QueryClientProvider client={client}>
        <GeneratePlanningTab />
      </QueryClientProvider>
    </MemoryRouter>,
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
      previewVersion: "v-1",
      generatedAt: "2026-06-01T00:00:00Z",
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
      previewVersion: "v-1",
      generatedAt: "2026-06-01T00:00:00Z",
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

describe("GeneratePlanningTab — réaffectation d'instrumentiste (Preview Editor)", () => {
  it("permet de changer l'instrumentiste d'une ligne et envoie la modification au générer", async () => {
    const user = userEvent.setup();
    // The Tab always previews the current real month by default (defaultYearMonth()) — the
    // line's date must fall in that month for generateMutation's per-month filter to pick it up.
    const now = new Date();
    const currentMonthDate = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-01`;
    const preview: PreviewResponseV2 = {
      lines: [line({ date: currentMonthDate, status: "UNCOVERED", instrumentistId: null, instrumentistName: null })],
      summary: { total: 1, covered: 0, uncovered: 1, skipped: 0, conflict: 0, modified: 0 },
      previewVersion: "v-1",
      generatedAt: "2026-06-01T00:00:00Z",
    };
    (planningV2Api.previewPlanningV2 as ReturnType<typeof vi.fn>).mockResolvedValue(preview);
    (planningV2Api.generatePlanningV2 as ReturnType<typeof vi.fn>).mockResolvedValue({ versionId: 1, created: 1, updated: 0, skipped: 0 });

    renderTab();
    await selectSite(user);
    await user.click(screen.getByRole("button", { name: "Prévisualiser" }));
    await screen.findByText("À pourvoir");

    // Clicking the instrumentist area opens a popover with a searchable instrumentist select.
    await user.click(screen.getByText("À pourvoir"));
    const popoverLabel = await screen.findByText("Instrumentiste");
    const input = popoverLabel.closest("div")!.querySelector("input")!;
    await user.click(input);
    await user.click(await screen.findByText("Diane Lefebvre"));

    // The line now shows the new instrumentist and an "Édité" badge instead of the popover trigger text.
    await waitFor(() => expect(screen.queryByText("À pourvoir")).not.toBeInTheDocument());
    expect(screen.getAllByText("Diane Lefebvre").length).toBeGreaterThan(0);
    expect(screen.getByText("Édité")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Générer avec modifications" }));

    await waitFor(() => expect(planningV2Api.generatePlanningV2).toHaveBeenCalled());
    const call = (planningV2Api.generatePlanningV2 as ReturnType<typeof vi.fn>).mock.calls[0][0];
    expect(call.previewVersion).toBe("v-1");
    expect(call.lines).toHaveLength(1);
    expect(call.lines[0]).toMatchObject({ instrumentistId: 9, instrumentistName: "Diane Lefebvre", status: "COVERED" });
  });

  it("réaffecte en masse via la sélection multiple", async () => {
    const user = userEvent.setup();
    const preview: PreviewResponseV2 = {
      lines: [
        line({ postId: 1, surgeonName: "Dr Martin", status: "UNCOVERED" }),
        line({ postId: 2, surgeonName: "Dr Martin", status: "UNCOVERED" }),
      ],
      summary: { total: 2, covered: 0, uncovered: 2, skipped: 0, conflict: 0, modified: 0 },
      previewVersion: "v-1",
      generatedAt: "2026-06-01T00:00:00Z",
    };
    (planningV2Api.previewPlanningV2 as ReturnType<typeof vi.fn>).mockResolvedValue(preview);

    renderTab();
    await selectSite(user);
    await user.click(screen.getByRole("button", { name: "Prévisualiser" }));
    await screen.findAllByText("À pourvoir");

    const checkboxes = screen.getAllByRole("checkbox");
    await user.click(checkboxes[0]);
    await user.click(checkboxes[1]);

    expect(await screen.findByText("2 postes sélectionnés")).toBeInTheDocument();

    const assignLabel = screen.getByText("Assigner à");
    const input = assignLabel.closest("div")!.querySelector("input")!;
    await user.click(input);
    await user.click(await screen.findByText("Marc Petit"));
    await user.click(screen.getByRole("button", { name: "Assigner" }));

    await waitFor(() => expect(screen.getAllByText("Marc Petit").length).toBeGreaterThanOrEqual(2));
    expect(screen.getAllByText("Édité")).toHaveLength(2);
  });
});
