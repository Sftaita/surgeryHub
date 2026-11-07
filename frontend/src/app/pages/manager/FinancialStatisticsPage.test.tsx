import { describe, it, expect, vi, beforeEach } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import FinancialStatisticsPage from "./FinancialStatisticsPage";

const getBySurgeonMock = vi.fn();
const getByInterventionMock = vi.fn();
const exportMock = vi.fn();

vi.mock("../../features/financial-statistics/api/financialStatistics.api", async () => {
  const actual = await vi.importActual<typeof import("../../features/financial-statistics/api/financialStatistics.api")>(
    "../../features/financial-statistics/api/financialStatistics.api",
  );
  return {
    ...actual,
    getOverview: vi.fn().mockResolvedValue({ from: "", to: "", activity: { missionCount: 0, executedMissionCount: 0, validatedMissionCount: 0, averageExecutionDurationMinutes: 0 }, currencies: [] }),
    getBySurgeon: (...args: unknown[]) => getBySurgeonMock(...args),
    getByIntervention: (...args: unknown[]) => getByInterventionMock(...args),
    exportStatisticsPdf: (...args: unknown[]) => exportMock(...args),
  };
});
vi.mock("../../features/financial-statistics/components/StatFilterBar", async () => {
  const actual = await vi.importActual<typeof import("../../features/financial-statistics/components/StatFilterBar")>(
    "../../features/financial-statistics/components/StatFilterBar",
  );
  return { ...actual, default: () => null };
});
vi.mock("../../features/financial-statistics/components/ZeroFinancialDataExplanation", () => ({ ZeroFinancialDataExplanation: () => null }));

const n = (s: string | null) => (s ?? "").replace(/[  ]/g, " ");

function surgeon(name: string, revenue: string, fees: string, net: string) {
  return { surgeonId: 1, surgeonNameSnapshot: name, currency: "EUR", missionCount: 2, executedMissionCount: 2, generatedFirmRevenue: revenue, generatedInstrumentistCompensation: fees, netBalance: net, averageMissionValue: "999.00" };
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><FinancialStatisticsPage /></QueryClientProvider>);
}

beforeEach(() => {
  getBySurgeonMock.mockReset();
  exportMock.mockReset();
  getBySurgeonMock.mockResolvedValue({
    items: [surgeon("Yorick Berger", "666.00", "666.25", "-0.25"), surgeon("Julien Vanderplasschen", "535.00", "467.50", "67.50")],
    total: 25, page: 1, limit: 20,
    totals: [{ currency: "EUR", generatedFirmRevenue: "2000.00", generatedInstrumentistCompensation: "1500.00", netBalance: "500.00" }],
  });
});

describe("FinancialStatisticsPage — Par chirurgien (D-186)", () => {
  it("affiche CA en +, frais en −, solde net et TOTAL GÉNÉRAL serveur (pas la seule page)", async () => {
    renderPage();
    fireEvent.click(screen.getByRole("tab", { name: "Par chirurgien" }));

    const row = (await screen.findByText("Yorick Berger")).closest("tr")!;
    const cells = within(row).getAllByRole("cell").map((c) => n(c.textContent));
    expect(cells).toEqual(["Yorick Berger", "2", "2", "+666,00 €", "−666,25 €", "−0,25 €"]);

    const total = screen.getByText("TOTAL GÉNÉRAL").closest("tr")!;
    expect(n(total.textContent)).toContain("+2 000,00 €");
    expect(n(total.textContent)).toContain("−1 500,00 €");
    expect(n(total.textContent)).toContain("+500,00 €");

    expect(screen.queryByText(/Valeur moy/)).not.toBeInTheDocument();
    expect(screen.queryByText(/999/)).not.toBeInTheDocument();
    expect(screen.getByText("Total recettes")).toBeInTheDocument();
    expect(screen.getByText("Total dépenses")).toBeInTheDocument();
  });

  it("trie par en-tête et exporte le PDF avec le même tri", async () => {
    exportMock.mockResolvedValue({ blob: new Blob(["%PDF"]), filename: "x.pdf" });
    (URL as any).createObjectURL = vi.fn(() => "blob:mock");
    (URL as any).revokeObjectURL = vi.fn();
    renderPage();
    fireEvent.click(screen.getByRole("tab", { name: "Par chirurgien" }));
    await screen.findByText("Yorick Berger");

    fireEvent.click(screen.getByRole("button", { name: "Solde net" }));
    await waitFor(() => expect(getBySurgeonMock).toHaveBeenLastCalledWith(expect.anything(), expect.objectContaining({ sortBy: "netBalance", sortDirection: "DESC", page: 1 })));

    fireEvent.click(screen.getByRole("button", { name: /Exporter en PDF/ }));
    await waitFor(() => expect(exportMock).toHaveBeenCalledWith("by-surgeon", expect.anything(), { sortBy: "netBalance", sortDirection: "DESC" }));
  });
});

describe("FinancialStatisticsPage — Par intervention (D-186)", () => {
  it("n'impute aucune rémunération à une intervention et distingue le coût moyen de mission associée", async () => {
    getByInterventionMock.mockResolvedValue({
      items: [{
        interventionTypeId: 3, interventionCodeSnapshot: "PTH", interventionNameSnapshot: "Prothèse totale de hanche", currency: "EUR",
        missionCount: 2, interventionCount: 3, interventionRevenue: "450.00", materialRevenue: "25.00", totalRevenue: "475.00",
        averageRevenuePerIntervention: "158.33", averageAssociatedMissionCost: "45.00", averageDurationMinutes: 90,
      }],
      total: 1, page: 1, limit: 20,
      totals: [{ currency: "EUR", interventionRevenue: "450.00", materialRevenue: "25.00", totalRevenue: "475.00", missionInstrumentistCompensation: "90.00", netBalance: "385.00" }],
    });
    renderPage();
    fireEvent.click(screen.getByRole("tab", { name: "Par intervention" }));

    const row = (await screen.findByText("Prothèse totale de hanche")).closest("tr")!;
    expect(n(row.textContent)).toContain("+475,00 €");
    expect(n(row.textContent)).toContain("45,00 €");
    expect(n(row.textContent)).not.toContain("−45,00 €");

    expect(screen.queryByText("Frais instrumentiste")).not.toBeInTheDocument();
    expect(screen.queryByText(/Non ventilé/)).not.toBeInTheDocument();
    const headers = screen.getAllByRole("columnheader").map((h) => h.textContent ?? "");
    expect(headers.some((h) => /coût par intervention|frais instrumentiste/i.test(h))).toBe(false);
    expect(screen.getByText("Coût moyen de mission associée")).toBeInTheDocument();
    expect(screen.getByText("Total dépenses (au niveau mission)")).toBeInTheDocument();
    expect(n(screen.getByText("Total dépenses (au niveau mission)").parentElement!.textContent)).toContain("−90,00 €");
  });
});
