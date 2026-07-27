import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AppRouter } from "./AppRouter";
import { ToastProvider } from "../ui/toast/ToastProvider";

/**
 * Lot 9A — vérifie uniquement le mappage route → page pour /app/m/dashboard et
 * /app/m/catalogue/prestations, et les deux redirections qui en dépendent (racine
 * manager, ancienne route de configuration tarifaire). N'exerce pas les autres routes
 * (déjà stables, hors périmètre de ce lot) : AppRouter les charge via React.lazy, donc
 * seules les pages réellement montées ici déclenchent leur propre import dynamique —
 * pas besoin de mocker les ~30 autres pages manager/instrumentiste/admin.
 */

vi.mock("../auth/AuthContext", () => ({
  useAuth: () => ({
    state: { status: "authenticated", user: { id: 1, role: "MANAGER", email: "manager@test.com", firstname: "Ada", lastname: "Lovelace" } },
    logout: vi.fn(),
  }),
}));

vi.mock("../features/me/ProfilePhotoPromptGate", () => ({
  ProfilePhotoPromptGate: () => null,
}));

// ── Dépendances de DesktopLayout (badges sidebar, menu compte) ──────────────────
vi.mock("../features/push/usePushNotifications", () => ({
  usePushNotifications: () => ({
    status: "permission-default", permission: "default", isSupported: true, isSubscribed: false,
    lastError: null, subscribe: vi.fn(), unsubscribe: vi.fn(), refreshStatus: vi.fn(),
  }),
}));
vi.mock("../features/pwa-install/usePwaInstallMenuState", () => ({
  usePwaInstallMenuState: () => ({ label: "Installation non proposée automatiquement sur ce navigateur", actionLabel: null, onAction: null, disabled: true, variant: "unavailable" }),
}));
vi.mock("../features/manager-catalogue/api/catalogue.api", () => ({
  getMaterialRequests: vi.fn().mockResolvedValue({ items: [], total: 0 }),
  getFirms: vi.fn().mockResolvedValue([]),
  getMaterialItems: vi.fn().mockResolvedValue({ items: [], total: 0, page: 1, limit: 200 }),
  createMaterialItem: vi.fn(),
}));
vi.mock("../features/manager-catalogue/api/interventionTypeRequests.api", () => ({
  getInterventionTypeRequests: vi.fn().mockResolvedValue({ items: [], total: 0 }),
}));

// ── Dépendances propres à DashboardPage ─────────────────────────────────────────
vi.mock("../features/missions/api/missions.api", () => ({
  fetchMissions: vi.fn().mockResolvedValue({ items: [], total: 0 }),
}));
vi.mock("../features/sites/api/sites.api", () => ({
  fetchSites: vi.fn().mockResolvedValue([]),
}));
vi.mock("../features/manager-instrumentists/api/instrumentists.api", () => ({
  getInstrumentists: vi.fn().mockResolvedValue({ items: [], total: 0 }),
}));
vi.mock("../features/manager-surgeons/api/surgeons.api", () => ({
  getSurgeons: vi.fn().mockResolvedValue({ items: [], total: 0 }),
}));
vi.mock("../features/planning-v2/api/planningV2.api", () => ({
  getAlerts: vi.fn().mockResolvedValue({ items: [], total: 0, page: 1, limit: 1 }),
}));
vi.mock("../features/financial-statistics/api/financialStatistics.api", () => ({
  getOverview: vi.fn().mockResolvedValue({ from: "", to: "", activity: { missionCount: 0, executedMissionCount: 0, validatedMissionCount: 0, averageExecutionDurationMinutes: 0 }, currencies: [] }),
  getPipeline: vi.fn().mockResolvedValue({
    validatedMissionsWithoutCalculation: 0, calculationsAwaitingApproval: 0, approvedCalculationsWithoutDocuments: 0,
    partiallyDocumentedCalculations: 0, generatedInvoicesNotIssued: 0, generatedStatementsNotIssued: 0,
    issuedInvoicesWithOpenBalance: 0, issuedStatementsWithOpenBalance: 0,
  }),
  getByFirm: vi.fn().mockResolvedValue({ items: [], total: 0, page: 1, limit: 5 }),
  getBySurgeon: vi.fn().mockResolvedValue({ items: [], total: 0, page: 1, limit: 5 }),
  getTopMaterials: vi.fn().mockResolvedValue({ items: [], total: 0, page: 1, limit: 5 }),
}));

// ── Dépendances propres à PrestationsPage ───────────────────────────────────────
vi.mock("../features/billing-firm/api/firmBilling.api", () => ({
  getFirmPricingRules: vi.fn().mockResolvedValue([]),
  createPricingRule: vi.fn(), updatePricingRule: vi.fn(), deletePricingRule: vi.fn(), replacePricingRule: vi.fn(),
  getFirmServiceOfferings: vi.fn().mockResolvedValue([]),
  createFirmServiceOffering: vi.fn(), updateFirmServiceOffering: vi.fn(),
  addSuggestedMaterial: vi.fn(), reorderSuggestedMaterials: vi.fn(), deleteSuggestedMaterial: vi.fn(),
}));
vi.mock("../features/intervention-types/api/interventionTypes.api", () => ({
  getInterventionTypes: vi.fn().mockResolvedValue([]),
  createInterventionType: vi.fn(),
}));

function renderAt(initialPath: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <ToastProvider>
        <MemoryRouter initialEntries={[initialPath]}>
          <AppRouter />
        </MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>,
  );
}

describe("AppRouter — routes Dashboard et Prestations (Lot 9A)", () => {
  it("/app/m/dashboard rend DashboardPage", async () => {
    renderAt("/app/m/dashboard");
    // Timeout généreux : premier test à déclencher l'import lazy à froid du chunk DashboardPage.
    await waitFor(() => expect(screen.getByRole("heading", { name: "Dashboard" })).toBeInTheDocument(), { timeout: 5000 });
  });

  it("/app/m redirige vers /app/m/dashboard", async () => {
    renderAt("/app/m");
    await waitFor(() => expect(screen.getByRole("heading", { name: "Dashboard" })).toBeInTheDocument(), { timeout: 5000 });
  });

  it("/app/m/catalogue/prestations rend PrestationsPage", async () => {
    renderAt("/app/m/catalogue/prestations");
    await waitFor(() => expect(screen.getByRole("heading", { name: "Prestations" })).toBeInTheDocument(), { timeout: 5000 });
  });

  it("l'ancienne route /app/m/billing/config redirige vers /app/m/catalogue/prestations", async () => {
    renderAt("/app/m/billing/config");
    await waitFor(() => expect(screen.getByRole("heading", { name: "Prestations" })).toBeInTheDocument(), { timeout: 5000 });
  });
});
