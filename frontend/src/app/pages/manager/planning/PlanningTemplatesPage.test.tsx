import * as React from "react";
import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import PlanningTemplatesPage from "./PlanningTemplatesPage";
import type { PlanningTemplate } from "../../../features/planning-manager/api/planning.api";

// ── Mocks ─────────────────────────────────────────────────────────────────────

vi.mock("../../../features/planning-manager/api/planning.api", () => ({
  getTemplates:   vi.fn(),
  createTemplate: vi.fn(),
  deleteTemplate: vi.fn(),
  patchTemplate:  vi.fn(),
  cloneTemplate:  vi.fn(),
}));

vi.mock("../../../features/sites/api/sites.api", () => ({
  fetchSites: vi.fn().mockResolvedValue([]),
}));

vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}));

// ── Helpers ───────────────────────────────────────────────────────────────────

import * as planningApi from "../../../features/planning-manager/api/planning.api";

function makeTemplate(overrides: Partial<PlanningTemplate> = {}): PlanningTemplate {
  return {
    id:        1,
    type:      "PAIR",
    label:     "Bloc standard",
    site:      { id: 1, name: "Alpha" },
    slots:     [],
    createdAt: "2026-01-01T10:00:00+01:00",
    ...overrides,
  };
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <PlanningTemplatesPage />
      </MemoryRouter>
    </QueryClientProvider>
  );
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe("PlanningTemplatesPage", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("shows empty-state illustration and message when there are no templates", async () => {
    vi.mocked(planningApi.getTemplates).mockResolvedValue([]);

    renderPage();

    await waitFor(() =>
      expect(screen.getByText("Aucun template configuré")).toBeInTheDocument()
    );
  });

  it("renders a table row for each template returned by the API", async () => {
    vi.mocked(planningApi.getTemplates).mockResolvedValue([
      makeTemplate({ label: "Bloc genou", site: { id: 1, name: "Alpha" } }),
      makeTemplate({ id: 2, label: "Bloc épaule", type: "IMPAIR", site: { id: 2, name: "Delta" } }),
    ]);

    renderPage();

    await waitFor(() => {
      expect(screen.getByText("Bloc genou")).toBeInTheDocument();
      expect(screen.getByText("Bloc épaule")).toBeInTheDocument();
      expect(screen.getByText("Alpha")).toBeInTheDocument();
      expect(screen.getByText("Delta")).toBeInTheDocument();
    });
  });

  it("shows Semaines PAIRES chip for PAIR type", async () => {
    vi.mocked(planningApi.getTemplates).mockResolvedValue([
      makeTemplate({ type: "PAIR" }),
    ]);

    renderPage();

    await waitFor(() =>
      expect(screen.getByText("Semaines PAIRES")).toBeInTheDocument()
    );
  });

  it("opens the create dialog when the header button is clicked", async () => {
    vi.mocked(planningApi.getTemplates).mockResolvedValue([]);
    const user = userEvent.setup();

    renderPage();

    // Wait for empty state to appear (data loaded)
    await waitFor(() => screen.getByText("Aucun template configuré"));

    // Click the top-right "Nouveau template" button
    await user.click(screen.getAllByRole("button", { name: /Nouveau template/i })[0]);

    expect(screen.getByRole("dialog")).toBeInTheDocument();
    // MUI Select renders label text in multiple DOM nodes — use getAllBy
    expect(screen.getAllByText("Type de semaine").length).toBeGreaterThan(0);
  });

  it("displays slot count chip for each template", async () => {
    vi.mocked(planningApi.getTemplates).mockResolvedValue([
      makeTemplate({ slots: [{} as any, {} as any, {} as any] }),
    ]);

    renderPage();

    await waitFor(() =>
      expect(screen.getByText("3 créneau(x)")).toBeInTheDocument()
    );
  });
});
