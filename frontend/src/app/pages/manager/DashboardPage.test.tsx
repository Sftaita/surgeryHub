import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import DashboardPage from "./DashboardPage";

const navigateMock = vi.fn();
vi.mock("react-router-dom", async () => {
  const actual = await vi.importActual<typeof import("react-router-dom")>("react-router-dom");
  return { ...actual, useNavigate: () => navigateMock };
});

const fetchMissionsMock = vi.fn();
vi.mock("../../features/missions/api/missions.api", () => ({
  fetchMissions: (...args: unknown[]) => fetchMissionsMock(...args),
}));

const fetchSitesMock = vi.fn();
vi.mock("../../features/sites/api/sites.api", () => ({
  fetchSites: (...args: unknown[]) => fetchSitesMock(...args),
}));

const getInstrumentistsMock = vi.fn();
vi.mock("../../features/manager-instrumentists/api/instrumentists.api", () => ({
  getInstrumentists: (...args: unknown[]) => getInstrumentistsMock(...args),
}));

const getSurgeonsMock = vi.fn();
vi.mock("../../features/manager-surgeons/api/surgeons.api", () => ({
  getSurgeons: (...args: unknown[]) => getSurgeonsMock(...args),
}));

const getMaterialRequestsMock = vi.fn();
vi.mock("../../features/manager-catalogue/api/catalogue.api", () => ({
  getMaterialRequests: (...args: unknown[]) => getMaterialRequestsMock(...args),
}));

const getInterventionTypeRequestsMock = vi.fn();
vi.mock("../../features/manager-catalogue/api/interventionTypeRequests.api", () => ({
  getInterventionTypeRequests: (...args: unknown[]) => getInterventionTypeRequestsMock(...args),
}));

const getAlertsMock = vi.fn();
vi.mock("../../features/planning-v2/api/planningV2.api", () => ({
  getAlerts: (...args: unknown[]) => getAlertsMock(...args),
}));

const getOverviewMock = vi.fn();
const getPipelineMock = vi.fn();
const getByFirmMock = vi.fn();
const getBySurgeonMock = vi.fn();
const getTopMaterialsMock = vi.fn();
vi.mock("../../features/financial-statistics/api/financialStatistics.api", () => ({
  getOverview: (...args: unknown[]) => getOverviewMock(...args),
  getPipeline: (...args: unknown[]) => getPipelineMock(...args),
  getByFirm: (...args: unknown[]) => getByFirmMock(...args),
  getBySurgeon: (...args: unknown[]) => getBySurgeonMock(...args),
  getTopMaterials: (...args: unknown[]) => getTopMaterialsMock(...args),
}));

function mockAllResolved(): void {
  fetchMissionsMock.mockResolvedValue({ items: [], total: 41 });
  fetchSitesMock.mockResolvedValue([{ id: 1, name: "Alpha" }, { id: 2, name: "Beta" }, { id: 3, name: "Gamma" }]);
  getInstrumentistsMock.mockResolvedValue({ items: [], total: 12 });
  getSurgeonsMock.mockResolvedValue({ items: [], total: 7 });
  getMaterialRequestsMock.mockResolvedValue({ items: [{ id: 1 }], total: 1 });
  getInterventionTypeRequestsMock.mockResolvedValue({ items: [], total: 0 });
  getAlertsMock.mockResolvedValue({ items: [], total: 9, page: 1, limit: 1 });
  getOverviewMock.mockResolvedValue({
    from: "2026-06-19", to: "2026-07-19",
    activity: { missionCount: 42, executedMissionCount: 40, validatedMissionCount: 38, averageExecutionDurationMinutes: 90 },
    currencies: [{ currency: "EUR", generatedTotalValue: "12345.67" }],
  });
  getPipelineMock.mockResolvedValue({
    validatedMissionsWithoutCalculation: 33,
    calculationsAwaitingApproval: 0,
    approvedCalculationsWithoutDocuments: 0,
    partiallyDocumentedCalculations: 0,
    generatedInvoicesNotIssued: 55,
    generatedStatementsNotIssued: 66,
    issuedInvoicesWithOpenBalance: 22,
    issuedStatementsWithOpenBalance: 0,
  });
  getByFirmMock.mockResolvedValue({ items: [{ firmId: 1, firmNameSnapshot: "Smith & Nephew", currency: "EUR", generatedRevenue: "999.00" }], total: 1, page: 1, limit: 5 });
  getBySurgeonMock.mockResolvedValue({ items: [], total: 0, page: 1, limit: 5 });
  getTopMaterialsMock.mockResolvedValue({ items: [], total: 0, page: 1, limit: 5 });
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <DashboardPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  navigateMock.mockReset();
  fetchMissionsMock.mockReset();
  fetchSitesMock.mockReset();
  getInstrumentistsMock.mockReset();
  getSurgeonsMock.mockReset();
  getMaterialRequestsMock.mockReset();
  getInterventionTypeRequestsMock.mockReset();
  getAlertsMock.mockReset();
  getOverviewMock.mockReset();
  getPipelineMock.mockReset();
  getByFirmMock.mockReset();
  getBySurgeonMock.mockReset();
  getTopMaterialsMock.mockReset();
  mockAllResolved();
});

describe("DashboardPage", () => {
  it("renders every section with data resolved from existing queries only", async () => {
    renderPage();

    expect(screen.getByText("Dashboard")).toBeInTheDocument();

    await waitFor(() => expect(screen.getAllByText("41").length).toBeGreaterThan(0)); // missions today/week/open (same mock total)
    expect(screen.getByText("12")).toBeInTheDocument(); // instrumentistes actifs
    expect(screen.getByText("7")).toBeInTheDocument(); // chirurgiens actifs
    expect(screen.getByText("3")).toBeInTheDocument(); // établissements
    expect(screen.getByText("9")).toBeInTheDocument(); // alertes planning ouvertes

    await waitFor(() => expect(screen.getByText("1")).toBeInTheDocument()); // demandes en attente (1 material + 0 intervention)

    await waitFor(() => expect(screen.getByText("33")).toBeInTheDocument()); // pipeline: validated w/o calculation
    expect(screen.getByText("55")).toBeInTheDocument(); // pipeline: factures générées non émises
    await waitFor(() => expect(screen.getByText("Smith & Nephew")).toBeInTheDocument()); // top firms ranking preview
  });

  it("affiche un tiret d'attente le temps du chargement, avant résolution des requêtes", () => {
    renderPage();
    // Avant toute résolution, chaque StatCard affiche "—" (value ?? "—") — vérifié
    // avant le waitFor qui attendrait la vraie valeur.
    expect(screen.getAllByText("—").length).toBeGreaterThan(0);
  });

  it("n'affiche aucune donnée patient — uniquement des agrégats numériques et des libellés d'entités déjà autorisées", async () => {
    renderPage();
    await waitFor(() => expect(screen.getAllByText("41").length).toBeGreaterThan(0));
    expect(screen.queryByText(/patient/i)).toBeNull();
  });

  it("navigue vers /app/m/missions au clic sur le raccourci « Aujourd'hui »", async () => {
    const user = userEvent.setup();
    renderPage();

    await waitFor(() => expect(screen.getAllByText("41").length).toBeGreaterThan(0));
    await user.click(screen.getByText("Aujourd'hui"));

    expect(navigateMock).toHaveBeenCalledWith("/app/m/missions");
  });
});

describe("DashboardPage — état vide", () => {
  it("affiche 0 sans planter quand toutes les requêtes renvoient des collections vides", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [], total: 0 });
    getInstrumentistsMock.mockResolvedValue({ items: [], total: 0 });
    getSurgeonsMock.mockResolvedValue({ items: [], total: 0 });
    fetchSitesMock.mockResolvedValue([]);
    getMaterialRequestsMock.mockResolvedValue({ items: [], total: 0 });
    getInterventionTypeRequestsMock.mockResolvedValue({ items: [], total: 0 });
    getAlertsMock.mockResolvedValue({ items: [], total: 0, page: 1, limit: 1 });
    getByFirmMock.mockResolvedValue({ items: [], total: 0, page: 1, limit: 5 });

    renderPage();

    await waitFor(() => expect(screen.getAllByText("0").length).toBeGreaterThan(0));
    await waitFor(() => expect(screen.getAllByText("Aucune donnée sur la période.").length).toBeGreaterThan(0));
  });
});

describe("DashboardPage — erreur backend", () => {
  it("n'affiche pas de crash quand une requête échoue — la carte reste sur son état d'attente", async () => {
    getInstrumentistsMock.mockRejectedValue(new Error("network error"));

    renderPage();

    expect(screen.getByText("Dashboard")).toBeInTheDocument();
    await waitFor(() => expect(screen.getAllByText("41").length).toBeGreaterThan(0)); // les autres cartes résolvent normalement
    expect(screen.getAllByText("—").length).toBeGreaterThan(0); // la carte en échec reste sur "—", jamais de crash
  });
});
