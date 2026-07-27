import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import { DesktopLayout } from "../layouts/DesktopLayout";
import PlanningSchedulePage from "../pages/manager/planning/PlanningSchedulePage";

/**
 * RC1-E — Living Planning was previously unreachable: PlanningSchedulePage existed but had
 * no <Route>, so a manager clicking through the sidebar could never open it. This test
 * proves the route itself resolves and renders. D-079 removed the direct sidebar link to
 * "Planning publié" (it's now reachable via a button inside Planning > Construire instead,
 * see PlanningV2Page.tsx) — this test covers route-level reachability; the in-page button
 * is covered by live verification per docs/decisions.md D-079.
 */

vi.mock("../auth/AuthContext", () => ({
  useAuth: () => ({
    state: { status: "authenticated", user: { role: "MANAGER", email: "manager@test.com" } },
    logout: vi.fn(),
  }),
}));

// DesktopLayout now reads push state (Lot 1 / audit PWA-push 24-07-2026) — this test
// renders DesktopLayout directly without the app's PushProvider tree, so the hook is
// mocked here the same way useAuth is above, rather than pulling in the full provider.
vi.mock("../features/push/usePushNotifications", () => ({
  usePushNotifications: () => ({
    status: "permission-default",
    permission: "default",
    isSupported: true,
    isSubscribed: false,
    lastError: null,
    subscribe: vi.fn(),
    unsubscribe: vi.fn(),
    refreshStatus: vi.fn(),
  }),
}));

// DesktopLayout now also reads PWA install state (D-079) — same rendering-without-provider
// situation as usePushNotifications above; mocked with the same neutral value already used
// in DesktopLayout.test.tsx for this exact hook.
vi.mock("../features/pwa-install/usePwaInstallMenuState", () => ({
  usePwaInstallMenuState: () => ({
    label: "Installation non proposée automatiquement sur ce navigateur",
    actionLabel: null,
    onAction: null,
    disabled: true,
    variant: "unavailable",
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

function renderApp(initialPath: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[initialPath]}>
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
  it("the /app/m/planning/living route resolves and renders the Living Planning page", async () => {
    renderApp("/app/m/planning/living");

    await waitFor(() => {
      expect(screen.getByRole("button", { name: /charger le planning/i })).toBeInTheDocument();
    });
    expect(screen.getByLabelText("Du")).toBeInTheDocument();
    expect(screen.getByLabelText("Au")).toBeInTheDocument();
  });

  it("renders the Planning V2 stub route when navigated to directly", () => {
    renderApp("/app/m/planning/v2");

    expect(screen.getByText("Planning V2 stub")).toBeInTheDocument();
  });
});
