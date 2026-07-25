import { describe, it, expect, vi, beforeEach, type Mock } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useMediaQuery } from "@mui/material";

import { MobileLayout } from "./MobileLayout";
import { useAuth } from "../auth/AuthContext";

/**
 * Push-only coverage of MobileLayout's activation banner — deliberately kept separate
 * from MobileLayout.test.tsx (nav redesign, unrelated to the Lot 1 Web Push socle) so the
 * two can be staged/committed independently (pre-commit review, D-081). Do not add
 * navigation-redesign assertions here; do not duplicate them from MobileLayout.test.tsx.
 */

vi.mock("@mui/material", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@mui/material")>();
  return { ...actual, useMediaQuery: vi.fn() };
});

vi.mock("react-router-dom", async (importOriginal) => {
  const actual = await importOriginal<typeof import("react-router-dom")>();
  return { ...actual, useNavigate: () => vi.fn() };
});

vi.mock("../auth/AuthContext", () => ({
  useAuth: vi.fn(),
}));

vi.mock("../features/missions/api/missions.api", () => ({
  fetchMissions: vi.fn().mockResolvedValue({ items: [], total: 0 }),
  fetchInstrumentistOffersWithFallback: vi.fn().mockResolvedValue({ items: [], total: 0 }),
}));

vi.mock("../features/missions/sync/useInstrumentistMissionSync", () => ({
  useInstrumentistMissionSync: vi.fn(),
}));

const mockSubscribe = vi.fn();
let mockPushStatus: string = "unsupported";
vi.mock("../features/push/usePushNotifications", () => ({
  usePushNotifications: () => ({
    status: mockPushStatus,
    permission: "default",
    isSupported: true,
    isSubscribed: false,
    lastError: null,
    subscribe: mockSubscribe,
    unsubscribe: vi.fn(),
    refreshStatus: vi.fn(),
  }),
}));

vi.mock("../features/push/useNotifications", () => ({
  useNotifications: () => ({ badgeLabel: undefined }),
}));

vi.mock("../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() }),
}));

function mockMobile() {
  (useMediaQuery as unknown as Mock).mockReturnValue(false); // <900px
}

function renderLayout(initialPath = "/app/i/today") {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialPath]}>
        <Routes>
          <Route path="/app/i" element={<MobileLayout />}>
            <Route path="today" element={<div>Today content</div>} />
            <Route path="planning" element={<div>Planning content</div>} />
            <Route path="offers" element={<div>Offers content</div>} />
          </Route>
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("MobileLayout — bannière d'activation des notifications push", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockPushStatus = "unsupported";
    // jsdom n'implémente pas window.scrollTo() — appelé par useRouteScrollRestoration,
    // sans rapport avec ce que ces tests-ci vérifient.
    vi.spyOn(window, "scrollTo").mockImplementation(() => {});
    (useAuth as unknown as Mock).mockReturnValue({
      state: {
        status: "authenticated",
        user: { id: 1, role: "INSTRUMENTIST", sites: [], firstname: "Sophie", lastname: "Collette" },
      },
      logout: vi.fn(),
    });
    mockMobile();
  });

  it("n'affiche pas la bannière quand le navigateur ne supporte pas le push", async () => {
    mockPushStatus = "unsupported";
    renderLayout();

    await screen.findByRole("navigation", { name: "Navigation instrumentiste" });
    expect(screen.queryByText("Activer")).not.toBeInTheDocument();
  });

  it("affiche la bannière avec un bouton Activer quand la permission n'a jamais été demandée", async () => {
    mockPushStatus = "permission-default";
    renderLayout();

    const activer = await screen.findByRole("button", { name: "Activer" });
    await userEvent.click(activer);

    expect(mockSubscribe).toHaveBeenCalledTimes(1);
  });

  it("n'affiche plus la bannière une fois abonné", async () => {
    mockPushStatus = "subscribed";
    renderLayout();

    await screen.findByRole("navigation", { name: "Navigation instrumentiste" });
    expect(screen.queryByText("Activer")).not.toBeInTheDocument();
  });
});
