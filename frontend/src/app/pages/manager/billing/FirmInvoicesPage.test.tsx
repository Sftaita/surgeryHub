import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import FirmInvoicesPage from "./FirmInvoicesPage";
import type { FirmBillingCockpit, ToInvoiceLine } from "../../../features/billing-firm/api/firmBillingCockpit.api";

const getFirmInvoicesMock = vi.fn();
const markFirmInvoicePaidMock = vi.fn();
const createInvoiceMock = vi.fn();
vi.mock("../../../features/billing-firm/api/firmInvoice.api", () => ({
  getFirmInvoices: (...a: unknown[]) => getFirmInvoicesMock(...a),
  markFirmInvoicePaid: (...a: unknown[]) => markFirmInvoicePaidMock(...a),
  createFirmInvoiceFromCalculations: (...a: unknown[]) => createInvoiceMock(...a),
  getFirmInvoicePdfUrl: (id: number) => `/api/firm-invoices/${id}/pdf`,
}));

const getCockpitMock = vi.fn();
vi.mock("../../../features/billing-firm/api/firmBillingCockpit.api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../../../features/billing-firm/api/firmBillingCockpit.api")>()),
  getFirmBillingCockpit: (...a: unknown[]) => getCockpitMock(...a),
}));

const calculateMock = vi.fn();
const approveMock = vi.fn();
vi.mock("../../../features/financial-calculation/api/financialCalculation.api", () => ({
  calculateMission: (...a: unknown[]) => calculateMock(...a),
  approveFinancialCalculation: (...a: unknown[]) => approveMock(...a),
}));

vi.mock("../../../api/apiClient", () => ({
  apiClient: { get: vi.fn().mockResolvedValue({ data: [{ id: 10, name: "Arthrex" }, { id: 20, name: "Stryker" }] }) },
}));

const toastSuccess = vi.fn();
const toastError = vi.fn();
vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccess, error: toastError, warning: vi.fn() }),
}));

function line(id: number, firm: { id: number; name: string }, amount: string, extra: Partial<ToInvoiceLine> = {}): ToInvoiceLine {
  return {
    id, calculationId: 7, calculationStatus: "APPROVED",
    mission: { id: 100 + id, date: "2026-09-02", status: "VALIDATED", site: "Delta", surgeon: "Dr X" },
    firm, lineType: "FIRM_INTERVENTION_FEE", lineTypeLabel: "Intervention", description: `Prestation ${id}`,
    intervention: { id: id * 10, label: `LCA ${id}` }, material: null,
    quantity: "1.0000", unitAmount: amount, totalAmount: amount, currency: "EUR",
    calculationLocked: false, missionPartiallyInvoiced: false, notice: null, ...extra,
  };
}

const ARTHREX = { id: 10, name: "Arthrex" };
const STRYKER = { id: 20, name: "Stryker" };

function cockpit(overrides: Partial<FirmBillingCockpit> = {}): FirmBillingCockpit {
  return {
    period: { from: "2026-09-01", to: "2026-09-30" },
    kpis: {
      toInvoiceLineCount: 3, toInvoiceAmounts: [{ currency: "EUR", amount: "650.00" }],
      toVerifyCount: 2, invoicedLineCount: 1, invoices: { generated: 1, sent: 2, paid: 3, cancelled: 0 },
    },
    toInvoice: [
      { firm: ARTHREX, currency: "EUR", lineCount: 2, totalAmount: "500.00", lines: [line(1, ARTHREX, "350.00"), line(2, ARTHREX, "150.00")] },
      { firm: STRYKER, currency: "EUR", lineCount: 1, totalAmount: "150.00", lines: [line(3, STRYKER, "150.00", { calculationLocked: true, missionPartiallyInvoiced: true, notice: "Calcul financier verrouillé : une partie de cette mission a déjà été facturée. Le calcul ne peut plus être modifié." })] },
    ],
    toVerify: [
      {
        reason: "CALCULATION_REQUIRED", reasonLabel: "Calcul financier requis : la mission est validée mais n'a pas encore été valorisée.",
        mission: { id: 501, date: "2026-09-03", status: "VALIDATED", site: "Delta", surgeon: "Dr Y" },
        firms: [ARTHREX], calculationId: null, anomalies: [], lines: [], totalAmount: null, currency: null, allowedActions: ["calculate"],
      },
      {
        reason: "CALCULATION_PENDING_APPROVAL", reasonLabel: "Calcul à approuver : les montants sont calculés mais pas encore approuvés.",
        mission: { id: 502, date: "2026-09-04", status: "VALIDATED", site: "Delta", surgeon: "Dr Z" },
        firms: [STRYKER], calculationId: 88, anomalies: [], lines: [], totalAmount: "200.00", currency: "EUR", allowedActions: ["approve"],
      },
    ],
    invoiced: [{
      ...line(9, ARTHREX, "80.00"),
      invoice: { id: 42, number: "FIRM-2026-042", status: "SENT", generatedAt: "2026-09-12T10:00:00+02:00", invoicedAmount: "80.00" },
    }],
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

beforeEach(() => {
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(new Date(2026, 8, 15, 10, 0, 0));
  getCockpitMock.mockReset().mockResolvedValue(cockpit());
  getFirmInvoicesMock.mockReset().mockResolvedValue([]);
  createInvoiceMock.mockReset();
  markFirmInvoicePaidMock.mockReset();
  calculateMock.mockReset();
  approveMock.mockReset();
  toastSuccess.mockReset();
  toastError.mockReset();
});

describe("FirmInvoicesPage — cockpit (D-123)", () => {
  it("affiche les KPI du backend tels quels, pour le mois courant (dates métier AAAA-MM-JJ)", async () => {
    renderPage();

    expect(await screen.findByText("3 lignes")).toBeInTheDocument();
    expect(screen.getByText("650,00 €")).toBeInTheDocument();
    expect(getCockpitMock).toHaveBeenCalledWith({ from: "2026-09-01", to: "2026-09-30", firmId: undefined });
    expect(screen.queryByText(/Flux classique/)).not.toBeInTheDocument();
  });

  it("navigue au mois précédent / suivant et filtre par firme", async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByText("3 lignes");

    await user.click(screen.getByRole("button", { name: "Mois précédent" }));
    await waitFor(() => expect(getCockpitMock).toHaveBeenLastCalledWith({ from: "2026-08-01", to: "2026-08-31", firmId: undefined }));
    await user.click(screen.getByRole("button", { name: "Mois suivant" }));
    await user.click(screen.getByRole("button", { name: "Mois suivant" }));
    await waitFor(() => expect(getCockpitMock).toHaveBeenLastCalledWith({ from: "2026-10-01", to: "2026-10-31", firmId: undefined }));

    await user.click(screen.getByRole("combobox", { name: "Filtrer par firme" }));
    await user.click(await screen.findByRole("option", { name: "Stryker" }));
    await waitFor(() => expect(getCockpitMock).toHaveBeenLastCalledWith({ from: "2026-10-01", to: "2026-10-31", firmId: 20 }));
  });

  it("regroupe les lignes à facturer par firme avec nombre de lignes et total", async () => {
    renderPage();

    expect(await screen.findByText("Arthrex")).toBeInTheDocument();
    expect(screen.getByText("2 lignes · 500,00 € à facturer")).toBeInTheDocument();
    expect(screen.getByText("1 ligne · 150,00 € à facturer")).toBeInTheDocument();
    // Contrainte de verrouillage rendue visible, jamais une ligne masquée.
    expect(screen.getByText("Calcul verrouillé")).toBeInTheDocument();
  });

  it("sélection limitée à une firme, montant sélectionné dans le CTA", async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByText("Arthrex");

    await user.click(screen.getByRole("checkbox", { name: "Sélectionner la ligne 1" }));
    expect(screen.getByRole("button", { name: "Générer la facture — 1 ligne — 350,00 €" })).toBeEnabled();

    // Les lignes d'une autre firme ne sont plus sélectionnables tant que la sélection existe.
    expect(screen.getByRole("checkbox", { name: "Sélectionner la ligne 3" })).toBeDisabled();
    expect(screen.getByRole("checkbox", { name: "Tout sélectionner pour Stryker" })).toBeDisabled();

    await user.click(screen.getByRole("checkbox", { name: "Tout sélectionner pour Arthrex" }));
    expect(screen.getByRole("button", { name: "Générer la facture — 2 lignes — 500,00 €" })).toBeEnabled();
  });

  it("génère avec exactement les identifiants sélectionnés, puis recharge le cockpit et les factures", async () => {
    const user = userEvent.setup();
    createInvoiceMock.mockResolvedValue({ id: 55, number: "FIRM-2026-055", firm: ARTHREX, totalAmount: "350.00", currency: "EUR", lineCount: 1 });
    renderPage();
    await screen.findByText("Arthrex");
    const cockpitCallsBefore = getCockpitMock.mock.calls.length;
    const invoiceCallsBefore = getFirmInvoicesMock.mock.calls.length;

    await user.click(screen.getByRole("checkbox", { name: "Sélectionner la ligne 1" }));
    await user.click(screen.getByRole("button", { name: /Générer la facture — 1 ligne/ }));

    await waitFor(() => expect(createInvoiceMock).toHaveBeenCalledWith({
      firmId: 10, currency: "EUR", periodStart: "2026-09-01", periodEnd: "2026-09-30", selectedFinancialCalculationLineIds: [1],
    }));
    expect(await screen.findByText(/Facture FIRM-2026-055 générée pour Arthrex/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Ouvrir la facture" })).toBeInTheDocument();
    expect(toastSuccess).toHaveBeenCalled();
    await waitFor(() => expect(getCockpitMock.mock.calls.length).toBeGreaterThan(cockpitCallsBefore));
    await waitFor(() => expect(getFirmInvoicesMock.mock.calls.length).toBeGreaterThan(invoiceCallsBefore));
  });

  it("affiche le message métier du backend quand la génération échoue (ligne déjà facturée entre-temps)", async () => {
    const user = userEvent.setup();
    createInvoiceMock.mockRejectedValue({ response: { data: { error: { message: "Sélection invalide.", violations: [{ message: "La ligne #1 est déjà rattachée à un document." }] } } } });
    renderPage();
    await screen.findByText("Arthrex");

    await user.click(screen.getByRole("checkbox", { name: "Sélectionner la ligne 1" }));
    await user.click(screen.getByRole("button", { name: /Générer la facture — 1 ligne/ }));

    await waitFor(() => expect(toastError).toHaveBeenCalledWith("Sélection invalide. La ligne #1 est déjà rattachée à un document."));
  });

  it("« À vérifier » : raison backend + Calculer / Approuver appellent les endpoints existants puis rechargent", async () => {
    const user = userEvent.setup();
    calculateMock.mockResolvedValue({ id: 1 });
    approveMock.mockResolvedValue({ id: 88 });
    renderPage();
    await screen.findByText("3 lignes");

    await user.click(screen.getByRole("tab", { name: /À vérifier/ }));
    expect(screen.getByText(/Calcul financier requis/)).toBeInTheDocument();
    expect(screen.getByText(/Calcul à approuver/)).toBeInTheDocument();

    const callsBefore = getCockpitMock.mock.calls.length;
    await user.click(screen.getByRole("button", { name: "Calculer" }));
    await waitFor(() => expect(calculateMock).toHaveBeenCalledWith(501));
    await waitFor(() => expect(getCockpitMock.mock.calls.length).toBeGreaterThan(callsBefore));

    await user.click(screen.getByRole("button", { name: "Approuver" }));
    await waitFor(() => expect(approveMock).toHaveBeenCalledWith(88));
  });

  it("« À vérifier » : un échec de calcul affiche le message métier renvoyé", async () => {
    const user = userEvent.setup();
    calculateMock.mockRejectedValue({ response: { data: { error: { message: "Tarifs manquants.", violations: [{ message: "Aucun tarif actif pour Arthrex / LCA." }] } } } });
    renderPage();
    await screen.findByText("3 lignes");
    await user.click(screen.getByRole("tab", { name: /À vérifier/ }));

    await user.click(screen.getByRole("button", { name: "Calculer" }));

    await waitFor(() => expect(toastError).toHaveBeenCalledWith("Tarifs manquants. Aucun tarif actif pour Arthrex / LCA."));
  });

  it("historique : « Marquer comme payée » n'apparaît que si le backend l'autorise", async () => {
    const user = userEvent.setup();
    getFirmInvoicesMock.mockResolvedValue([
      { id: 1, number: "FIRM-2026-001", firm: ARTHREX, status: "GENERATED", periodStart: "2026-09-01", periodEnd: "2026-09-30", totalAmount: "100.00", currency: "EUR", lineCount: 2, generatedAt: "2026-09-10T10:00:00+02:00", sentAt: null, paidAt: null, allowedActions: ["send", "cancel"] },
      { id: 2, number: "FIRM-2026-002", firm: STRYKER, status: "SENT", periodStart: "2026-09-01", periodEnd: "2026-09-30", totalAmount: "200.00", currency: "EUR", lineCount: 1, generatedAt: "2026-09-10T10:00:00+02:00", sentAt: "2026-09-11T10:00:00+02:00", paidAt: null, allowedActions: ["markPaid"] },
    ]);
    markFirmInvoicePaidMock.mockResolvedValue({});
    renderPage();
    await screen.findByText("3 lignes");

    await user.click(screen.getByRole("tab", { name: "Factures" }));
    const generatedRow = (await screen.findByText("FIRM-2026-001")).closest("tr")!;
    const sentRow = screen.getByText("FIRM-2026-002").closest("tr")!;
    expect(within(generatedRow).queryByRole("button", { name: "Marquer comme payée" })).not.toBeInTheDocument();
    expect(within(generatedRow).getByText("01/09/2026 → 30/09/2026")).toBeInTheDocument();

    await user.click(within(sentRow).getByRole("button", { name: "Marquer comme payée" }));
    await waitFor(() => expect(markFirmInvoicePaidMock).toHaveBeenCalled());
    expect(markFirmInvoicePaidMock.mock.calls[0][0]).toBe(2);
  });

  it("« Lignes facturées » : chaque ligne référence sa facture", async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByText("3 lignes");

    await user.click(screen.getByRole("tab", { name: /Lignes facturées/ }));

    expect(screen.getByRole("link", { name: "FIRM-2026-042" })).toHaveAttribute("href", "/app/m/billing/firm-invoices/42");
    expect(screen.getByText(/Envoyée · générée le 12\/09\/2026/)).toBeInTheDocument();
  });

  it("aucune ligne à facturer : renvoie explicitement vers « À vérifier »", async () => {
    getCockpitMock.mockResolvedValue(cockpit({ toInvoice: [], kpis: { ...cockpit().kpis, toInvoiceLineCount: 0, toInvoiceAmounts: [] } }));
    renderPage();

    expect(await screen.findByText(/Aucune ligne à facturer sur cette période/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Voir les 2 élément(s) à vérifier" })).toBeInTheDocument();
  });
});
