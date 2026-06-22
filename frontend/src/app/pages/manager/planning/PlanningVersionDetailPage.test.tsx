import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import PlanningVersionDetailPage from "./PlanningVersionDetailPage";

// ── Mocks ─────────────────────────────────────────────────────────────────────

vi.mock("../../../features/planning-manager/api/planning.api", () => ({
  getPlanningVersion:         vi.fn(),
  getVersionDiff:             vi.fn().mockResolvedValue({ added: [], removed: [], modified: [] }),
  deployPlanning:             vi.fn(),
  previewPlanning:            vi.fn(),
  deletePlanningVersion:      vi.fn(),
  triggerVersionPdfDownload:  vi.fn(),
}));

vi.mock("../../../features/planning-manager/components/DeployModal", () => ({
  DeployModal: ({ open }: { open: boolean }) =>
    open ? <div data-testid="deploy-modal">DeployModal ouvert</div> : null,
}));

vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}));

import * as planningApi from "../../../features/planning-manager/api/planning.api";

// ── Factory ───────────────────────────────────────────────────────────────────

function makeDraftVersion(overrides: Record<string, unknown> = {}) {
  return {
    id: 42,
    versionNumber: 1,
    status: "DRAFT",
    periodStart: "2026-03-23",
    periodEnd:   "2026-03-27",
    generatedAt: "2026-03-22T10:00:00+01:00",
    deployedAt:  null,
    archivedAt:  null,
    site: { id: 1, name: "Alpha" },
    generatedBy: { id: 1, email: "mgr@test.com" },
    summary: { total: 5, draft: 5, open: 0, assigned: 0, withoutInstrumentist: 2 },
    allowedActions: { view: true, deploy: true, delete: true, downloadPdf: true, viewDiff: true },
    lastDeployment: null,
    ...overrides,
  };
}

function renderPage(id = "42") {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[`/app/m/planning/versions/${id}`]}>
        <Routes>
          <Route path="/app/m/planning/versions/:id" element={<PlanningVersionDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>
  );
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe("PlanningVersionDetailPage", () => {
  beforeEach(() => {
    vi.resetAllMocks();
    vi.mocked(planningApi.getVersionDiff).mockResolvedValue({ added: [], removed: [], modified: [] });
  });

  // ── Deploy flow — must use 2-step modal ─────────────────────────────────────

  it("clicking Déployer calls previewPlanning (not deployPlanning directly)", async () => {
    vi.mocked(planningApi.getPlanningVersion).mockResolvedValue(makeDraftVersion() as any);
    vi.mocked(planningApi.previewPlanning).mockResolvedValue([]);

    renderPage();
    await waitFor(() => expect(screen.getByText("Déployer ce planning")).toBeInTheDocument());

    await userEvent.click(screen.getByText("Déployer ce planning"));

    await waitFor(() => expect(planningApi.previewPlanning).toHaveBeenCalledWith({
      from:   "2026-03-23",
      to:     "2026-03-27",
      siteId: 1,
    }));
    // deployPlanning must NOT be called directly — it goes through the modal
    expect(planningApi.deployPlanning).not.toHaveBeenCalled();
  });

  it("DeployModal opens after previewPlanning succeeds", async () => {
    vi.mocked(planningApi.getPlanningVersion).mockResolvedValue(makeDraftVersion() as any);
    vi.mocked(planningApi.previewPlanning).mockResolvedValue([]);

    renderPage();
    await waitFor(() => expect(screen.getByText("Déployer ce planning")).toBeInTheDocument());

    await userEvent.click(screen.getByText("Déployer ce planning"));

    await waitFor(() => expect(screen.getByTestId("deploy-modal")).toBeInTheDocument());
  });

  it("does not open DeployModal when previewPlanning fails", async () => {
    vi.mocked(planningApi.getPlanningVersion).mockResolvedValue(makeDraftVersion() as any);
    vi.mocked(planningApi.previewPlanning).mockRejectedValue(new Error("network error"));

    renderPage();
    await waitFor(() => expect(screen.getByText("Déployer ce planning")).toBeInTheDocument());

    await userEvent.click(screen.getByText("Déployer ce planning"));

    await waitFor(() => expect(planningApi.previewPlanning).toHaveBeenCalled());
    expect(screen.queryByTestId("deploy-modal")).not.toBeInTheDocument();
  });

  it("Déployer button is hidden for non-DRAFT versions", async () => {
    vi.mocked(planningApi.getPlanningVersion).mockResolvedValue(
      makeDraftVersion({ status: "ACTIVE", allowedActions: { view: true, deploy: false, delete: false, downloadPdf: true, viewDiff: true } }) as any
    );

    renderPage();
    await waitFor(() => expect(screen.getByText("Télécharger PDF actuel")).toBeInTheDocument());

    expect(screen.queryByText("Déployer ce planning")).not.toBeInTheDocument();
  });

  // ── PDF label ───────────────────────────────────────────────────────────────

  it('shows "Télécharger PDF actuel" button label', async () => {
    vi.mocked(planningApi.getPlanningVersion).mockResolvedValue(makeDraftVersion() as any);

    renderPage();
    await waitFor(() => expect(screen.getByText("Télécharger PDF actuel")).toBeInTheDocument());
  });

  it('does not show old label "Télécharger PDF global"', async () => {
    vi.mocked(planningApi.getPlanningVersion).mockResolvedValue(makeDraftVersion() as any);

    renderPage();
    await waitFor(() => expect(screen.getByText("Télécharger PDF actuel")).toBeInTheDocument());
    expect(screen.queryByText("Télécharger PDF global")).not.toBeInTheDocument();
  });
});
