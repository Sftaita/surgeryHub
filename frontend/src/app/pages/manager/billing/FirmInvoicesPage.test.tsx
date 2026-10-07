import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import FirmInvoicesPage from "./FirmInvoicesPage";
import type { FirmBillingWorklist, WorklistAnomaly, WorklistRow } from "../../../features/billing-firm/api/firmBillingWorklist.api";

const getFirmInvoicesMock = vi.fn();
const markFirmInvoicePaidMock = vi.fn();
const createInvoiceMock = vi.fn();
vi.mock("../../../features/billing-firm/api/firmInvoice.api", () => ({
  getFirmInvoices: (...a: unknown[]) => getFirmInvoicesMock(...a),
  markFirmInvoicePaid: (...a: unknown[]) => markFirmInvoicePaidMock(...a),
  createFirmInvoiceFromCalculations: (...a: unknown[]) => createInvoiceMock(...a),
  getFirmInvoicePdfUrl: (id: number) => `/api/firm-invoices/${id}/pdf`,
}));

const getWorklistMock = vi.fn();
const exportMock = vi.fn();
const runCalculationsMock = vi.fn();
vi.mock("../../../features/billing-firm/api/firmBillingWorklist.api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../../../features/billing-firm/api/firmBillingWorklist.api")>()),
  getFirmBillingWorklist: (...a: unknown[]) => getWorklistMock(...a),
  exportFirmBillingSelection: (...a: unknown[]) => exportMock(...a),
  runFirmBillingCalculations: (...a: unknown[]) => runCalculationsMock(...a),
}));

const approveMock = vi.fn();
vi.mock("../../../features/financial-calculation/api/financialCalculation.api", () => ({
  approveFinancialCalculation: (...a: unknown[]) => approveMock(...a),
}));

vi.mock("../../../api/apiClient", () => ({
  apiClient: { get: vi.fn().mockResolvedValue({ data: [{ id: 10, name: "Arthrex" }, { id: 20, name: "Stryker" }, { id: 30, name: "Smith & Nephew" }] }) },
}));

const toastSuccess = vi.fn();
const toastError = vi.fn();
vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccess, error: toastError, warning: vi.fn() }),
}));

const ARTHREX = { id: 10, name: "Arthrex" };
const STRYKER = { id: 20, name: "Stryker" };
const MISSION = { id: 101, date: "2026-09-02", status: "VALIDATED", site: "Delta", surgeon: "Dr X" };

function row(key: string, extra: Partial<WorklistRow> = {}): WorklistRow {
  return {
    key, sourceType: "INTERVENTION", sourceId: 1, mission: MISSION, firm: ARTHREX,
    label: `Prestation ${key}`, reference: null, quantity: "1",
    billingStatus: "BILLABLE", billingStatusLabel: "Facturable", reasonCode: "BILLABLE", reasonLabel: "Facturable",
    reasonDetail: "Ligne valorisée par le calcul financier approuvé, pas encore facturée.",
    amount: "350.00", currency: "EUR", invoice: null, financialLineId: 900, calculationId: 7, canInvoice: true,
    ...extra,
  };
}

const ROWS: WorklistRow[] = [
  row("MISSION_INTERVENTION:1", { label: "Arthrodèse 2 niveaux", financialLineId: 901 }),
  row("MATERIAL_LINE:2", { sourceType: "MATERIAL", label: "Ancre 5mm", reference: "REF-55", quantity: "2", amount: "50.00", financialLineId: 902 }),
  row("MISSION_INTERVENTION:3", {
    firm: STRYKER, label: "Ligamentoplastie", billingStatus: "NOT_BILLABLE", billingStatusLabel: "Non facturable",
    reasonCode: "REPRESENTATIVE_PRESENT", reasonLabel: "Délégué présent",
    reasonDetail: "Le forfait de cette prestation est neutralisé en présence du délégué Stryker.", amount: "0.00", canInvoice: false, financialLineId: 903,
  }),
  row("MATERIAL_LINE:4", {
    sourceType: "MATERIAL", label: "Vis", billingStatus: "TO_REVIEW", billingStatusLabel: "À vérifier",
    reasonCode: "MISSING_FIRM_MATERIAL_RATE", reasonLabel: "Tarif matériel manquant", reasonDetail: "Aucun tarif…", amount: null, canInvoice: false, financialLineId: null,
  }),
  row("MISSION_INTERVENTION:5", {
    billingStatus: "INVOICED", billingStatusLabel: "Facturé", reasonCode: "INVOICED", reasonLabel: "Déjà facturé", reasonDetail: "Facturé sur la facture FIRM-2026-042 (envoyée).",
    amount: "80.00", canInvoice: false, invoice: { id: 42, number: "FIRM-2026-042", status: "SENT", statusLabel: "envoyée" },
  }),
];

function anomaly(extra: Partial<WorklistAnomaly> = {}): WorklistAnomaly {
  return {
    key: "MISSING_FIRM_INTERVENTION_RATE:501:0", code: "MISSING_FIRM_INTERVENTION_RATE", title: "Tarif d'intervention manquant",
    explanation: "Aucun tarif applicable n'est configuré pour cette prestation chez Arthrex au 02/09/2026.",
    mission: { ...MISSION, id: 501 }, firm: ARTHREX, element: { type: "INTERVENTION", label: "Arthrodèse 2 niveaux" },
    action: { code: "CONFIGURE_INTERVENTION_RATE", label: "Configurer le tarif" }, resolved: false, calculationId: null, rowKey: null, calculationLocked: false,
    ...extra,
  };
}

function worklist(overrides: Partial<FirmBillingWorklist> = {}): FirmBillingWorklist {
  return {
    period: { from: "2026-09-01", to: "2026-09-30" },
    summary: {
      lineCount: 46, billable: { lineCount: 31, amounts: [{ currency: "EUR", amount: "842.00" }] }, notBillable: { lineCount: 9 },
      toReview: { lineCount: 4 }, invoiced: { lineCount: 2, amounts: [{ currency: "EUR", amount: "80.00" }] }, anomalyCount: 6,
      pendingValidationMissionCount: 0, invoices: { generated: 1, sent: 2, paid: 3, cancelled: 0 },
    },
    rows: ROWS,
    anomalies: [
      anomaly(),
      anomaly({ key: "MISSING_INSTRUMENTIST_RATE:501:1", code: "MISSING_INSTRUMENTIST_RATE", title: "Tarif instrumentiste manquant", firm: null, element: { type: "INSTRUMENTIST", label: "Jane Doe" }, action: { code: "CONFIGURE_INSTRUMENTIST_RATE", label: "Configurer le tarif" }, resolved: true, explanation: "Aucun tarif horaire actif pour Jane Doe." }),
      anomaly({ key: "CALCULATION_REQUIRED:600:mission", code: "CALCULATION_REQUIRED", title: "Calcul financier à effectuer", mission: { ...MISSION, id: 600 }, firm: null, element: null, action: { code: "CALCULATE", label: "Calculer" }, explanation: "La mission est validée mais n'a pas encore été valorisée." }),
    ],
    bulkActions: { recalculateFixed: [501], calculatePending: [600] },
    ...overrides,
  };
}

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <FirmInvoicesPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

function tableRow(key: string) {
  return screen.getByTestId(`row-${key}`);
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(new Date(2026, 8, 15, 10, 0, 0));
  getWorklistMock.mockReset().mockResolvedValue(worklist());
  getFirmInvoicesMock.mockReset().mockResolvedValue([]);
  createInvoiceMock.mockReset();
  markFirmInvoicePaidMock.mockReset();
  exportMock.mockReset().mockResolvedValue({ blob: new Blob(["x"]), filename: "facturation-firmes-2026-09.xlsx" });
  runCalculationsMock.mockReset();
  approveMock.mockReset();
  toastSuccess.mockReset();
  toastError.mockReset();
  (URL as any).createObjectURL = vi.fn(() => "blob:mock");
  (URL as any).revokeObjectURL = vi.fn();
});

describe("FirmInvoicesPage — worklist (D-133)", () => {
  it("demande la worklist du mois courant en dates métier, toutes firmes, et affiche les tuiles avec leur unité", async () => {
    renderPage();

    expect(await screen.findByText("46 lignes")).toBeInTheDocument();
    expect(getWorklistMock).toHaveBeenCalledWith({ from: "2026-09-01", to: "2026-09-30", firmIds: [], type: undefined, status: undefined });
    expect(screen.getByText("31 lignes")).toBeInTheDocument();
    expect(screen.getByText("842,00 €")).toBeInTheDocument();
    expect(screen.getByText("9 lignes")).toBeInTheDocument();
    expect(screen.getByText("6 anomalies")).toBeInTheDocument();
    expect(screen.getByText("2 lignes")).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "À corriger (6)" })).toBeInTheDocument();
    expect(screen.queryByRole("tab", { name: /Lignes facturées/ })).not.toBeInTheDocument();
  });

  it("affiche chaque ligne avec le badge et le motif fournis par le backend, détail au survol", async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByText("Arthrodèse 2 niveaux");

    const delegated = tableRow("MISSION_INTERVENTION:3");
    expect(within(delegated).getByText("Non facturable")).toBeInTheDocument();
    expect(within(delegated).getByText("Délégué présent")).toBeInTheDocument();

    await user.hover(within(delegated).getByText("Non facturable"));
    expect(await screen.findByText("Le forfait de cette prestation est neutralisé en présence du délégué Stryker.")).toBeInTheDocument();

    const invoiced = tableRow("MISSION_INTERVENTION:5");
    expect(within(invoiced).getByText("FIRM-2026-042")).toBeInTheDocument();
    expect(within(tableRow("MATERIAL_LINE:4")).getByText("—")).toBeInTheDocument(); // aucun montant inventé
    expect(within(tableRow("MATERIAL_LINE:2")).getByText("REF-55")).toBeInTheDocument();
  });

  it("transmet au backend les filtres firmes (multi), type et statut — sans filtrer côté client", async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByText("Arthrodèse 2 niveaux");

    await user.click(screen.getByLabelText("Filtrer par firmes"));
    await user.click(await screen.findByRole("option", { name: "Arthrex" }));
    await user.click(screen.getByRole("option", { name: "Smith & Nephew" }));
    await waitFor(() => expect(getWorklistMock).toHaveBeenLastCalledWith(expect.objectContaining({ firmIds: [10, 30] })));

    await user.keyboard("{Escape}");
    await user.click(screen.getByRole("button", { name: "Matériel" }));
    await waitFor(() => expect(getWorklistMock).toHaveBeenLastCalledWith(expect.objectContaining({ firmIds: [10, 30], type: "MATERIAL" })));

    await user.click(screen.getByRole("button", { name: "Non facturables" }));
    await waitFor(() => expect(getWorklistMock).toHaveBeenLastCalledWith(expect.objectContaining({ status: "NOT_BILLABLE" })));

    // Les lignes affichées sont exactement celles renvoyées : aucune n'est masquée localement.
    expect(screen.getAllByTestId(/^row-/)).toHaveLength(ROWS.length);
  });

  it("une tuile filtre la liste par statut", async () => {
    const user = userEvent.setup();
    renderPage();
    await user.click(await screen.findByRole("button", { name: /Déjà facturées/ }));
    await waitFor(() => expect(getWorklistMock).toHaveBeenLastCalledWith(expect.objectContaining({ status: "INVOICED" })));
  });

  it("exporte exactement les lignes cochées, en Excel puis en PDF", async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByText("Arthrodèse 2 niveaux");
    expect(screen.queryByRole("button", { name: "Exporter Excel" })).not.toBeInTheDocument();

    await user.click(within(tableRow("MATERIAL_LINE:2")).getByRole("checkbox"));
    await user.click(within(tableRow("MISSION_INTERVENTION:3")).getByRole("checkbox"));
    expect(screen.getByText("2 lignes sélectionnées")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Exporter Excel" }));
    await waitFor(() => expect(exportMock).toHaveBeenCalledTimes(1));
    expect(exportMock).toHaveBeenCalledWith({
      from: "2026-09-01", to: "2026-09-30", firmIds: [], keys: ["MATERIAL_LINE:2", "MISSION_INTERVENTION:3"], format: "xlsx",
    });

    await user.click(screen.getByRole("button", { name: "Exporter PDF" }));
    await waitFor(() => expect(exportMock).toHaveBeenLastCalledWith(expect.objectContaining({ keys: ["MATERIAL_LINE:2", "MISSION_INTERVENTION:3"], format: "pdf" })));
  });

  it("« tout sélectionner » puis une ligne disparue des données serveur n'est jamais exportée", async () => {
    const user = userEvent.setup();
    const { rerender } = renderPage();
    await screen.findByText("Arthrodèse 2 niveaux");
    await user.click(screen.getByRole("checkbox", { name: "Tout sélectionner" }));
    expect(screen.getByText("5 lignes sélectionnées")).toBeInTheDocument();

    getWorklistMock.mockResolvedValue(worklist({ rows: ROWS.slice(0, 2) }));
    await user.click(screen.getByRole("button", { name: "Facturables" }));
    await waitFor(() => expect(screen.getByText("2 lignes sélectionnées")).toBeInTheDocument());

    await user.click(screen.getByRole("button", { name: "Exporter PDF" }));
    await waitFor(() => expect(exportMock).toHaveBeenCalledWith(expect.objectContaining({ keys: ["MISSION_INTERVENTION:1", "MATERIAL_LINE:2"] })));
    rerender(<></>);
  });

  it("génère une facture seulement pour des lignes facturables d'une seule firme, avec leurs identifiants financiers", async () => {
    const user = userEvent.setup();
    createInvoiceMock.mockResolvedValue({ id: 77, number: "FIRM-2026-077", firm: ARTHREX, totalAmount: "400.00", currency: "EUR", lineCount: 2 });
    renderPage();
    await screen.findByText("Arthrodèse 2 niveaux");

    await user.click(within(tableRow("MISSION_INTERVENTION:3")).getByRole("checkbox"));
    expect(screen.getByRole("button", { name: "Générer la facture" })).toBeDisabled(); // non facturable
    await user.click(within(tableRow("MISSION_INTERVENTION:3")).getByRole("checkbox"));

    await user.click(within(tableRow("MISSION_INTERVENTION:1")).getByRole("checkbox"));
    await user.click(within(tableRow("MATERIAL_LINE:2")).getByRole("checkbox"));
    await user.click(screen.getByRole("button", { name: "Générer la facture Arthrex" }));

    await waitFor(() => expect(createInvoiceMock).toHaveBeenCalledWith({
      firmId: 10, currency: "EUR", periodStart: "2026-09-01", periodEnd: "2026-09-30", selectedFinancialCalculationLineIds: [901, 902],
    }));
    expect(await screen.findByText(/Facture FIRM-2026-077 générée pour Arthrex/)).toBeInTheDocument();
  });

  it("À corriger : anomalies traduites, regroupées par mission, action « Configurer le tarif » et relance groupée des éléments corrigés", async () => {
    const user = userEvent.setup();
    runCalculationsMock.mockResolvedValue({ results: [], calculated: 1, failed: 0, skipped: 0 });
    renderPage();
    await user.click(await screen.findByRole("tab", { name: "À corriger (6)" }));

    const mission = screen.getByTestId("mission-501");
    expect(within(mission).getByText("Tarif d'intervention manquant")).toBeInTheDocument();
    expect(within(mission).getByText("Arthrodèse 2 niveaux · Arthrex")).toBeInTheDocument();
    expect(within(mission).getByText(/au 02\/09\/2026/)).toBeInTheDocument();
    expect(within(mission).getByRole("button", { name: "Configurer le tarif" })).toBeInTheDocument();
    expect(within(mission).getByText("Corrigé — à recalculer")).toBeInTheDocument();
    expect(screen.queryByText(/No active/)).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /Recalculer les éléments corrigés/ }));
    await waitFor(() => expect(runCalculationsMock).toHaveBeenCalledWith([501]));
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith("1 mission calculée."));

    await user.click(within(screen.getByTestId("mission-600")).getByRole("button", { name: "Calculer" }));
    await waitFor(() => expect(runCalculationsMock).toHaveBeenLastCalledWith([600]));
  });

  it("Factures : compteurs par statut et filtre multi-firmes transmis", async () => {
    const user = userEvent.setup();
    renderPage();
    await user.click(await screen.findByRole("tab", { name: "Factures" }));

    expect(await screen.findByText("1 facture générée")).toBeInTheDocument();
    expect(screen.getByText("2 envoyées")).toBeInTheDocument();
    expect(screen.getByText("3 payées")).toBeInTheDocument();
    expect(getFirmInvoicesMock).toHaveBeenCalledWith({ from: "2026-09-01", to: "2026-09-30", firmIds: undefined, status: undefined, documentType: "STANDARD" });
  });

  it("signale les missions encodées en attente de validation au lieu de les faire disparaître", async () => {
    getWorklistMock.mockResolvedValue(worklist({ summary: { ...worklist().summary, pendingValidationMissionCount: 3 } }));
    renderPage();
    expect(await screen.findByText(/3 missions encodées sur la période attendent encore leur validation/)).toBeInTheDocument();
  });
});
