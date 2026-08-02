import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import FinancialCalculationCard from "./FinancialCalculationCard";
import type { FinancialCalculation } from "../api/financialCalculation.api";

const apiGetMock = vi.fn();
const apiPostMock = vi.fn();

vi.mock("../../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
    post: (...args: unknown[]) => apiPostMock(...args),
  },
}));

vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}));

function baseLine(overrides: Partial<FinancialCalculation["lines"][number]> = {}): FinancialCalculation["lines"][number] {
  return {
    id: 1,
    beneficiaryType: "FIRM",
    beneficiaryFirmId: 10,
    beneficiaryInstrumentistId: null,
    lineType: "FIRM_INTERVENTION_FEE",
    sourceType: "MISSION_INTERVENTION",
    descriptionSnapshot: "[LCA] Ligament croisé antérieur",
    quantity: "1.0000",
    durationMinutes: null,
    unitAmount: "150.00",
    grossAmount: "150.00",
    adjustmentAmount: "0.00",
    totalAmount: "150.00",
    currency: "EUR",
    effectiveAt: "2026-06-01",
    snapshot: {},
    warnings: [],
    ...overrides,
  };
}

function makeCalculation(lines: FinancialCalculation["lines"]): FinancialCalculation {
  return {
    id: 900,
    missionId: 42,
    version: 1,
    status: "CALCULATED",
    effectiveAt: "2026-06-01",
    currencyPolicy: "PER_CURRENCY",
    calculatedAt: "2026-06-01T10:00:00+02:00",
    calculatedBy: 1,
    approvedAt: null,
    approvedBy: null,
    lockedAt: null,
    cancelledAt: null,
    cancelledBy: null,
    supersededAt: null,
    supersededByCalculationId: null,
    totalsByCurrency: { EUR: { FIRM: "150.00" } },
    lines,
  };
}

function renderCard(calculation: FinancialCalculation) {
  apiGetMock.mockImplementation((url: string) => {
    if (url === "/api/missions/42/financial-calculations") return Promise.resolve({ data: [calculation] });
    return Promise.resolve({ data: [] });
  });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <FinancialCalculationCard missionId={42} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  apiGetMock.mockReset();
  apiPostMock.mockReset();
});

describe("FinancialCalculationCard — détail du calcul (D-092)", () => {
  it("déplie le détail d'une ligne neutralisée : brut, ajustement, raison, montant final", async () => {
    const user = userEvent.setup();
    const line = baseLine({
      adjustmentAmount: "-150.00",
      totalAmount: "0.00",
      snapshot: {
        representativePresentSnapshot: true,
        adjustmentReasonSnapshot: "Le forfait de cette prestation est neutralisé en présence du délégué ConMed.",
      },
    });
    renderCard(makeCalculation([line]));

    await screen.findByText("[LCA] Ligament croisé antérieur");
    await user.click(screen.getAllByRole("button")[0]); // premier bouton d'expansion de ligne

    const grossLabel = await screen.findByText("Montant brut");
    // P.U. (tableau replié) affiche aussi 150.00 EUR — on scope au bloc détail déplié.
    expect(within(grossLabel.closest(".MuiStack-root") as HTMLElement).getByText("150.00 EUR")).toBeInTheDocument();
    expect(screen.getByText(/neutralisé en présence du délégué ConMed/)).toBeInTheDocument();
    expect(screen.getByText("-150.00 EUR")).toBeInTheDocument();
    expect(screen.getByText("Oui")).toBeInTheDocument(); // délégué présent
  });

  it("une ligne non ajustée affiche brut = final sans section ajustement", async () => {
    const user = userEvent.setup();
    renderCard(makeCalculation([baseLine()]));

    await screen.findByText("[LCA] Ligament croisé antérieur");
    await user.click(screen.getAllByRole("button")[0]);

    expect(await screen.findByText("Montant brut")).toBeInTheDocument();
    expect(screen.queryByText("Ajustement")).toBeNull();
    expect(screen.queryByText("Règle appliquée")).toBeNull();
  });

  it("affiche un warning tarif/politique quand la ligne en porte un", async () => {
    const user = userEvent.setup();
    const line = baseLine({
      warnings: [{ code: "STALE_REPRESENTATIVE_PRESENCE_ANSWER", message: "Le délégué a été signalé présent mais cette politique n'affecte plus la facturation de cette prestation." }],
    });
    renderCard(makeCalculation([line]));

    await screen.findByText("[LCA] Ligament croisé antérieur");
    await user.click(screen.getAllByRole("button")[0]);

    expect(await screen.findByText(/n'affecte plus la facturation/)).toBeInTheDocument();
  });

  it("marque visuellement une ligne ajustée dans le tableau replié (badge « Ajustée »)", async () => {
    renderCard(makeCalculation([baseLine({ adjustmentAmount: "-150.00", totalAmount: "0.00" })]));

    await screen.findByText("[LCA] Ligament croisé antérieur");
    expect(screen.getByText("Ajustée")).toBeInTheDocument();
  });
});
