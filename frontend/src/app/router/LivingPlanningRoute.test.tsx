import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import { DesktopLayout } from "../layouts/DesktopLayout";
import PlanningSchedulePage from "../pages/manager/planning/PlanningSchedulePage";

/**
 * RC1-E — Living Planning was previously unreachable: PlanningSchedulePage existed but had
 * no <Route>, so a manager clicking through the sidebar could never open it. This test
 * proves the wiring end to end: Manager → Planning (sidebar) → Living Planning renders.
 */

vi.mock("../auth/AuthContext", () => ({
  useAuth: () => ({
    state: { status: "authenticated", user: { role: "MANAGER", email: "manager@test.com" } },
    logout: vi.fn(),
  }),
}));

vi.mock("../features/manager-catalogue/api/catalogue.api", () => ({
  getMaterialRequests: vi.fn().mockResolvedValue({ items: [] }),
}));

vi.mock("../features/missions/api/missions.api", () => ({
  fetchMissions: vi.fn().mockResolvedValue({ items: [], total: 0 }),
}));
vi.mock("../features/sites/api/sites.api", () => ({
  fetchSites: vi.fn().mockResolvedValue([{ id: 1, name: "Alpha" }]),
}));
vi.mock("../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}));
vi.mock("../api/apiClient", () => ({
  apiClient: {
    get:  vi.fn().mockResolvedValue({ data: { items: [] } }),
    post: vi.fn().mockResolvedValue({ data: {} }),
  },
}));
vi.mock("../features/planning-v2/api/planningV2.api", () => ({
  fetchCoverageSummary: vi.fn().mockResolvedValue({
    versionId: 1, total: 0, covered: 0, open: 0, cancelled: 0, coveragePercent: 0,
  }),
  releaseMission:  vi.fn().mockResolvedValue(undefined),
  cancelMission:   vi.fn().mockResolvedValue(undefined),
  reassignMission: vi.fn().mockResolvedValue(undefined),
  fetchMissionAudit: vi.fn().mockResolvedValue([]),
  fetchMissionEligibleInstrumentists: vi.fn().mockResolvedValue({
    missionId: 1, missionStatus: "OPEN", eligible: [], ineligible: [],
  }),
}));

function renderApp() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={["/app/m/planning/v2"]}>
        <Routes>
          <Route element={<DesktopLayout />}>
            <Route path="/app/m/planning/v2" element={<div>Planning V2 stub</div>} />
            <Route path="/app/m/planning/living" element={<PlanningSchedulePage />} />
          </Route>
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("Living Planning route wiring (RC1-E)", () => {
  it("is reachable from the sidebar and renders the Living Planning page", async () => {
    const user = userEvent.setup();
    renderApp();

    // Start on the Planning V2 stub route.
    expect(screen.getByText("Planning V2 stub")).toBeInTheDocument();

    // Sidebar exposes a link to Living Planning ("Planning publié").
    const navLink = screen.getByRole("link", { name: "Planning publié" });
    expect(navLink).toHaveAttribute("href", "/app/m/planning/living");

    await user.click(navLink);

    // Navigating there renders PlanningSchedulePage's own content.
    await waitFor(() => {
      expect(screen.getByRole("button", { name: /charger le planning/i })).toBeInTheDocument();
    });
    expect(screen.getByLabelText("Du")).toBeInTheDocument();
    expect(screen.getByLabelText("Au")).toBeInTheDocument();
  });

  it("still exposes the existing Planning sidebar entry unchanged", () => {
    renderApp();

    const planningLink = screen.getByRole("link", { name: "Planning" });
    expect(planningLink).toHaveAttribute("href", "/app/m/planning/v2");
  });
});
