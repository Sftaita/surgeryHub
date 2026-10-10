import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import FirmInvoicesPage from "./FirmInvoicesPage";
import type { FirmBillingWorklist, WorklistAnomaly, WorklistRow } from "../../../features/billing-firm/api/firmBillingWorklist.api";

const getFirmInvoicesMock = vi.fn();
const markFirmInvoicePaidMock = vi.fn();
const createDraftMock = vi.fn();
const addToDraftMock = vi.fn();
const moveToDraftMock = vi.fn();
vi.mock("../../../features/billing-firm/api/firmInvoice.api", () => ({
  getFirmInvoices: (...a: unknown[]) => getFirmInvoicesMock(...a),
  markFirmInvoicePaid: (...a: unknown[]) => markFirmInvoicePaidMock(...a),
  createFirmInvoiceDraft: (...a: unknown[]) => createDraftMock(...a),
  addLinesToFirmInvoiceDraft: (...a: unknown[]) => addToDraftMock(...a),
  moveLinesToFirmInvoiceDraft: (...a: unknown[]) => moveToDraftMock(...a),
  getFirmInvoicePdfUrl: (id: number) => `/api/firm-invoices/${id}/pdf`,
}));

const getWorklistMock = vi.fn();
const exportMock = vi.fn();
const runCalculationsMock = vi.fn();

const historyMock = vi.fn();
vi.mock("../../../features/billing-firm/api/firmBillingWorklist.api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../../../features/billing-firm/api/firmBillingWorklist.api")>()),
  getFirmBillingWorklist: (...a: unknown[]) => getWorklistMock(...a),
  exportFirmBillingSelection: (...a: unknown[]) => exportMock(...a),
  runFirmBillingCalculations: (...a: unknown[]) => runCalculationsMock(...a),
  getFirmBillingLineHistory: (...a: unknown[]) => historyMock(...a),
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
    amount: "350.00", currency: "EUR", currentInvoice: null, invoiceState: "FREE", invoiceStateLabel: "Libre",
    sourceKey: key.startsWith("MATERIAL") ? `MATERIAL:${key.split(":")[1]}` : `INTERVENTION:${key.split(":")[1]}`, hasHistory: false,
    financialLineId: 900, calculationId: 7, canInvoice: true, canMoveToDraft: false,
    ...extra,
  };
}

const ROWS: WorklistRow[] = [
  row("MISSION_INTERVENTION:1", { label: "Arthrodèse 2 niveaux", financialLineId: 901 }),
  row("MATERIAL_LINE:2", { sourceType: "MATERIAL", label: "Ancre 5mm", reference: "REF-55", quantity: "2", amount: "50.00", financialLineId: 902, hasHistory: true }),
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
    amount: "80.00", canInvoice: false, hasHistory: true, invoiceState: "SENT", invoiceStateLabel: "Envoyée",
    currentInvoice: { id: 42, number: "FIRM-2026-042", status: "SENT", statusLabel: "envoyée", firmName: "Arthrex", editable: false },
  }),
];

function anomaly(extra: Partial<WorklistAnomaly> = {}): WorklistAnomaly {
  return {
    key: "MISSING_FIRM_INTERVENTION_RATE:501:0", code: "MISSING_FIRM_INTERVENTION_RATE", title: "Tarif d'intervention manquant",
    explanation: "Aucun tarif applicable n'est configuré pour cette prestation chez Arthrex au 02/09/2026.",
    mission: { ...MISSION, id: 501 }, firm: ARTHREX, element: { type: "INTERVENTION", label: "Arthrodèse 2 niveaux" },
    action: { code: "CONFIGURE_INTERVENTION_RATE", label: "Configurer le tarif" }, resolved: false, calculationId: null, rowKey: null, calculationLocked: false,
    currentResolution: null, referenceDate: "2026-09-02", detectedAfterFailure: false,
    ...extra,
  };
}

function worklist(overrides: Partial<FirmBillingWorklist> = {}): FirmBillingWorklist {
  return {
    period: { from: "2026-09-01", to: "2026-09-30" },
    summary: {
      lineCount: 46, billable: { lineCount: 31, amounts: [{ currency: "EUR", amount: "842.00" }] }, notBillable: { lineCount: 9 },
      toReview: { lineCount: 4 }, invoiced: { lineCount: 2, amounts: [{ currency: "EUR", amount: "80.00" }] }, anomalyCount: 6,
      pendingValidationMissionCount: 0, invoices: { draft: 2, generated: 1, sent: 2, paid: 3, cancelled: 0, abandoned: 1 },
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
  createDraftMock.mockReset();
  addToDraftMock.mockReset();
  moveToDraftMock.mockReset();
  markFirmInvoicePaidMock.mockReset();
  exportMock.mockReset().mockResolvedValue({ blob: new Blob(["x"]), filename: "facturation-firmes-2026-09.xlsx" });
  runCalculationsMock.mockReset();
  approveMock.mockReset();
  historyMock.mockReset();
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
    expect(within(invoiced).getByText("FIRM-2026-042 · Envoyée")).toBeInTheDocument();
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

  it("crée un brouillon avec exactement les lignes libres sélectionnées d'une firme (aucune génération directe)", async () => {
    const user = userEvent.setup();
    createDraftMock.mockResolvedValue({ id: 124, number: null, status: "DRAFT", firm: ARTHREX, totalAmount: "400.00", currency: "EUR", lines: [{}, {}] });
    renderPage();
    await screen.findByText("Arthrodèse 2 niveaux");

    await user.click(within(tableRow("MISSION_INTERVENTION:3")).getByRole("checkbox"));
    expect(screen.getByRole("button", { name: "Créer un brouillon" })).toBeDisabled(); // non facturable
    await user.click(within(tableRow("MISSION_INTERVENTION:3")).getByRole("checkbox"));

    await user.click(within(tableRow("MISSION_INTERVENTION:1")).getByRole("checkbox"));
    await user.click(within(tableRow("MATERIAL_LINE:2")).getByRole("checkbox"));
    expect(screen.queryByRole("button", { name: /Générer la facture/ })).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Créer un brouillon Arthrex" }));

    await waitFor(() => expect(createDraftMock).toHaveBeenCalledWith({
      firmId: 10, currency: "EUR", periodStart: "2026-09-01", periodEnd: "2026-09-30", financialLineIds: [901, 902],
    }));
    expect(await screen.findByText(/Brouillon Arthrex #124 — 400,00 €/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Ouvrir le brouillon" })).toBeInTheDocument();
  });

  it("ajoute des lignes libres à un brouillon existant de la même firme", async () => {
    const user = userEvent.setup();
    getFirmInvoicesMock.mockImplementation((params: { status?: string }) => Promise.resolve(params?.status === "DRAFT"
      ? [{ id: 124, number: null, status: "DRAFT", firm: ARTHREX, currency: "EUR", totalAmount: "80.00", lineCount: 1 }]
      : []));
    addToDraftMock.mockResolvedValue({ id: 124, number: null, status: "DRAFT", firm: ARTHREX, totalAmount: "430.00", currency: "EUR", lines: [{}, {}] });
    renderPage();
    await screen.findByText("Arthrodèse 2 niveaux");

    await user.click(within(tableRow("MISSION_INTERVENTION:1")).getByRole("checkbox"));
    await user.click(screen.getByRole("button", { name: "Ajouter au brouillon…" }));
    expect(getFirmInvoicesMock).toHaveBeenCalledWith({ firmId: 10, status: "DRAFT", documentType: "STANDARD" });
    await user.click(await screen.findByRole("menuitem", { name: /Brouillon Arthrex #124/ }));

    await waitFor(() => expect(addToDraftMock).toHaveBeenCalledWith(124, [901]));
  });

  it("une ligne déjà dans un brouillon ne s'ajoute pas ailleurs : seul « Déplacer vers… » est proposé, hors brouillon courant", async () => {
    const user = userEvent.setup();
    const inDraft = row("MATERIAL_LINE:7", {
      sourceType: "MATERIAL", label: "Vis", financialLineId: 907, canInvoice: false, canMoveToDraft: true,
      reasonCode: "IN_DRAFT", reasonLabel: "Dans un brouillon", invoiceState: "IN_DRAFT", invoiceStateLabel: "Dans un brouillon",
      currentInvoice: { id: 124, number: null, status: "DRAFT", statusLabel: "brouillon", firmName: "Arthrex", editable: true },
    });
    getWorklistMock.mockResolvedValue(worklist({ rows: [inDraft, ROWS[0]] }));
    getFirmInvoicesMock.mockImplementation((params: { status?: string }) => Promise.resolve(params?.status === "DRAFT" ? [
      { id: 124, number: null, status: "DRAFT", firm: ARTHREX, currency: "EUR", totalAmount: "30.00", lineCount: 1 },
      { id: 128, number: null, status: "DRAFT", firm: ARTHREX, currency: "EUR", totalAmount: "0.00", lineCount: 0 },
    ] : []));
    moveToDraftMock.mockResolvedValue({ id: 128, number: null, status: "DRAFT", firm: ARTHREX, totalAmount: "30.00", currency: "EUR", lines: [{}] });
    renderPage();

    await user.click(within(await screen.findByTestId("row-MATERIAL_LINE:7")).getByRole("checkbox"));
    expect(screen.queryByRole("button", { name: /Créer un brouillon/ })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Ajouter au brouillon…" })).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Déplacer vers…" }));

    expect(screen.queryByRole("menuitem", { name: /#124/ })).not.toBeInTheDocument(); // brouillon courant exclu
    await user.click(await screen.findByRole("menuitem", { name: /Brouillon Arthrex #128/ }));
    await waitFor(() => expect(moveToDraftMock).toHaveBeenCalledWith(128, [907]));

    // Mélange libre + brouillon : aucune action ambiguë.
    await user.click(within(screen.getByTestId("row-MATERIAL_LINE:7")).getByRole("checkbox"));
    await user.click(within(screen.getByTestId("row-MISSION_INTERVENTION:1")).getByRole("checkbox"));
    expect(screen.getByRole("button", { name: "Créer un brouillon" })).toBeDisabled();
    expect(screen.queryByRole("button", { name: "Déplacer vers…" })).not.toBeInTheDocument();
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

describe("FirmInvoicesPage — état courant de facture et historique (D-134)", () => {
  it("colonne Facture : état courant fourni par le backend, lien direct vers le document avec la ligne ciblée", async () => {
    renderPage();
    await screen.findByText("Arthrodèse 2 niveaux");

    const link = within(tableRow("MISSION_INTERVENTION:5")).getByRole("link", { name: "FIRM-2026-042 · Envoyée" });
    expect(link).toHaveAttribute("href", "/app/m/billing/firm-invoices/42?focusLine=INTERVENTION%3A5");

    const free = tableRow("MISSION_INTERVENTION:1");
    expect(within(free).getByText("Libre")).toBeInTheDocument();
    expect(within(free).queryByText("déjà passée par une facture")).not.toBeInTheDocument();

    // Libre ne signifie pas « jamais utilisée ».
    expect(within(tableRow("MATERIAL_LINE:2")).getByText("déjà passée par une facture")).toBeInTheDocument();
  });

  it("un brouillon éventuel s'affiche « Brouillon · firme · #id » (valeur editable fournie par le backend)", async () => {
    getWorklistMock.mockResolvedValue(worklist({ rows: [row("MATERIAL_LINE:9", {
      sourceType: "MATERIAL", invoiceState: "IN_DRAFT", invoiceStateLabel: "Dans un brouillon", canInvoice: false,
      currentInvoice: { id: 124, number: null, status: "DRAFT", statusLabel: "brouillon", firmName: "Arthrex", editable: true },
    })] }));
    renderPage();
    const link = await screen.findByRole("link", { name: "Brouillon · Arthrex · #124" });
    expect(link).toHaveAttribute("href", "/app/m/billing/firm-invoices/124?focusLine=MATERIAL%3A9");
  });

  it("ouvre l'historique d'une ligne dans un drawer, chronologie servie par le backend", async () => {
    const user = userEvent.setup();
    historyMock.mockResolvedValue({
      sourceKey: "MATERIAL:2", sourceType: "MATERIAL", sourceId: 2, label: "Ancre 5mm", currentInvoice: null,
      history: [
        { id: 1, eventType: "INVOICE_GENERATED", label: "Facture générée", description: "Facture FIRM-2026-040 générée", occurredAt: "2026-10-07T08:14:00+02:00", actorName: "Samy Ftaita", firmName: "Arthrex", invoice: { id: 40, number: "FIRM-2026-040", statusAtEvent: "GENERATED" }, amount: "50.00", currency: "EUR" },
        { id: 2, eventType: "INVOICE_CANCELLED", label: "Facture annulée — ligne de nouveau libre", description: "Facture FIRM-2026-040 annulée — ligne de nouveau libre", occurredAt: "2026-10-07T08:31:00+02:00", actorName: "Samy Ftaita", firmName: "Arthrex", invoice: { id: 40, number: "FIRM-2026-040", statusAtEvent: "GENERATED" }, amount: "50.00", currency: "EUR" },
      ],
    });
    renderPage();
    await screen.findByText("Arthrodèse 2 niveaux");

    await user.click(within(tableRow("MATERIAL_LINE:2")).getByRole("button", { name: "Historique de Ancre 5mm" }));
    expect(historyMock).toHaveBeenCalledWith("MATERIAL:2");

    const drawer = await screen.findByRole("region", { name: "Historique de facturation" });
    const steps = within(within(drawer).getByRole("list", { name: "Chronologie" })).getAllByRole("listitem");
    expect(steps).toHaveLength(2);
    expect(within(steps[0]).getByText("Facture FIRM-2026-040 générée")).toBeInTheDocument();
    expect(within(steps[1]).getByText("Facture FIRM-2026-040 annulée — ligne de nouveau libre")).toBeInTheDocument();
    expect(within(steps[0]).getByText("Arthrex · Samy Ftaita")).toBeInTheDocument();
    expect(within(steps[0]).getByRole("link", { name: "Ouvrir FIRM-2026-040" })).toHaveAttribute("href", "/app/m/billing/firm-invoices/40?focusLine=MATERIAL%3A2");
    expect(within(drawer).getByText("Libre")).toBeInTheDocument();
  });

  it("annonce une ligne qui n'a jamais été facturée", async () => {
    const user = userEvent.setup();
    historyMock.mockResolvedValue({ sourceKey: "INTERVENTION:1", sourceType: "INTERVENTION", sourceId: 1, label: "Arthrodèse 2 niveaux", currentInvoice: null, history: [] });
    renderPage();
    await user.click(within(await screen.findByTestId("row-MISSION_INTERVENTION:1")).getByRole("button", { name: /Historique de/ }));
    expect(await screen.findByText("Cette ligne n'a encore jamais figuré sur une facture.")).toBeInTheDocument();
  });
});

describe("FirmInvoicesPage — Factures : brouillon abandonné ≠ facture annulée (D-137)", () => {
  it("masque les brouillons abandonnés par défaut, les inclut sur demande, et distingue les libellés", async () => {
    const user = userEvent.setup();
    getFirmInvoicesMock.mockImplementation((params: { includeAbandoned?: boolean }) => Promise.resolve([
      { id: 7, number: "FIRM-2026-007", status: "CANCELLED", firm: ARTHREX, currency: "EUR", totalAmount: "100.00", lineCount: 0, periodStart: "2026-09-01", periodEnd: "2026-09-30", allowedActions: [] },
      ...(params?.includeAbandoned ? [{ id: 8, number: null, status: "ABANDONED", firm: ARTHREX, currency: "EUR", totalAmount: "0.00", lineCount: 0, periodStart: "2026-09-01", periodEnd: "2026-09-30", allowedActions: [] }] : []),
    ]));
    renderPage();
    await user.click(await screen.findByRole("tab", { name: "Factures" }));

    expect(await screen.findByText("Facture annulée", { selector: ".MuiChip-label" })).toBeInTheDocument();
    expect(getFirmInvoicesMock).toHaveBeenLastCalledWith(expect.objectContaining({ includeAbandoned: undefined }));
    expect(screen.queryByText("Brouillon abandonné", { selector: ".MuiChip-label" })).not.toBeInTheDocument();

    await user.click(screen.getByRole("checkbox", { name: /Inclure les brouillons abandonnés \(1\)/ }));
    await waitFor(() => expect(getFirmInvoicesMock).toHaveBeenLastCalledWith(expect.objectContaining({ includeAbandoned: true })));
    expect(await screen.findByText("Brouillon abandonné", { selector: ".MuiChip-label" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Brouillon #8" })).toBeInTheDocument();
    expect(screen.getAllByRole("link", { name: "PDF" })).toHaveLength(1); // facture annulée seulement, jamais le brouillon abandonné
  });
});
