import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import PlanningVersionsListPage from "./PlanningVersionsListPage";
import type { PlanningVersionSummary } from "../../../features/planning-manager/api/planning.api";

// ── Mocks ─────────────────────────────────────────────────────────────────────

vi.mock("../../../features/planning-manager/api/planning.api", () => ({
  listPlanningVersions:      vi.fn(),
  deletePlanningVersion:     vi.fn(),
  triggerVersionPdfDownload: vi.fn(),
}));

vi.mock("../../../features/sites/api/sites.api", () => ({
  fetchSites: vi.fn().mockResolvedValue([]),
}));

vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}));

import * as planningApi from "../../../features/planning-manager/api/planning.api";

// ── Factories ─────────────────────────────────────────────────────────────────

function makeVersion(overrides: Partial<PlanningVersionSummary> = {}): PlanningVersionSummary {
  return {
    id: 1,
    versionNumber: 1,
    status: "ACTIVE",
    periodStart: "2026-03-23",
    periodEnd:   "2026-03-27",
    generatedAt: "2026-03-22T10:00:00+01:00",
    deployedAt:  "2026-03-22T11:00:00+01:00",
    archivedAt:  null,
    site: { id: 1, name: "Alpha" },
    generatedBy: { id: 1, email: "mgr@test.com" },
    summary: {
      total: 10, draft: 0, open: 0, assigned: 10,
      withoutInstrumentist: 0,
    },
    allowedActions: { view: true, deploy: false, delete: false, downloadPdf: true, viewDiff: true },
    lastDeployment: null,
    ...overrides,
  };
}

function makePage(items: ReturnType<typeof makeVersion>[], total = items.length) {
  return { items, total, page: 1, limit: 50 };
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <PlanningVersionsListPage />
      </MemoryRouter>
    </QueryClientProvider>
  );
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe("PlanningVersionsListPage", () => {
  beforeEach(() => {
    vi.resetAllMocks();
  });

  // ── Coverage badges ─────────────────────────────────────────────────────────

  it('shows "Tout couvert" chip when withoutInstrumentist = 0', async () => {
    vi.mocked(planningApi.listPlanningVersions).mockResolvedValue(
      makePage([makeVersion({ summary: { total: 10, draft: 0, open: 0, assigned: 10, withoutInstrumentist: 0 } })])
    );

    renderPage();
    await waitFor(() => expect(screen.getByText("Tout couvert")).toBeInTheDocument());
  });

  it('shows "X non couvert(s)" chip when withoutInstrumentist > 0', async () => {
    vi.mocked(planningApi.listPlanningVersions).mockResolvedValue(
      makePage([makeVersion({ summary: { total: 10, draft: 2, open: 3, assigned: 5, withoutInstrumentist: 3 } })])
    );

    renderPage();
    await waitFor(() => expect(screen.getByText("3 non couvert(s)")).toBeInTheDocument());
  });

  it('does not show "Tout couvert" when withoutInstrumentist > 0', async () => {
    vi.mocked(planningApi.listPlanningVersions).mockResolvedValue(
      makePage([makeVersion({ summary: { total: 10, draft: 2, open: 3, assigned: 5, withoutInstrumentist: 3 } })])
    );

    renderPage();
    await waitFor(() => expect(screen.queryByText("Tout couvert")).not.toBeInTheDocument());
  });

  // ── Deployment failed badge ─────────────────────────────────────────────────

  it('shows "Déploiement échoué" chip when lastDeployment.status = FAILED', async () => {
    vi.mocked(planningApi.listPlanningVersions).mockResolvedValue(
      makePage([makeVersion({
        lastDeployment: {
          status: "FAILED",
          deployedAt:  "2026-03-22T11:00:00+01:00",
          startedAt:   "2026-03-22T11:00:01+01:00",
          completedAt: null,
          hasError:    true,
        },
      })])
    );

    renderPage();
    await waitFor(() => expect(screen.getByText("Déploiement échoué")).toBeInTheDocument());
  });

  it('does not show "Déploiement échoué" when lastDeployment is null', async () => {
    vi.mocked(planningApi.listPlanningVersions).mockResolvedValue(
      makePage([makeVersion({ lastDeployment: null })])
    );

    renderPage();
    await waitFor(() => expect(screen.queryByText("Déploiement échoué")).not.toBeInTheDocument());
  });

  // ── PDF button tooltip ──────────────────────────────────────────────────────

  it('PDF button has aria-label "Télécharger PDF actuel"', async () => {
    vi.mocked(planningApi.listPlanningVersions).mockResolvedValue(
      makePage([makeVersion()])
    );

    renderPage();
    await waitFor(() =>
      expect(screen.getByRole("button", { name: /Télécharger PDF actuel/i })).toBeInTheDocument()
    );
  });

  // ── Empty state ─────────────────────────────────────────────────────────────

  it("shows empty state when no versions returned", async () => {
    vi.mocked(planningApi.listPlanningVersions).mockResolvedValue(makePage([]));

    renderPage();
    await waitFor(() => expect(screen.getByText(/Aucun planning/i)).toBeInTheDocument());
  });
});
