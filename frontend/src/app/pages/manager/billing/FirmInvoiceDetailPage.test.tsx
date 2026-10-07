import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import FirmInvoiceDetailPage from "./FirmInvoiceDetailPage";

const getFirmInvoiceMock = vi.fn();
const removeLineMock = vi.fn();
const generateMock = vi.fn();
const abandonMock = vi.fn();
const candidatesMock = vi.fn();
const addLinesMock = vi.fn();
vi.mock("../../../features/billing-firm/api/firmInvoice.api", () => ({
  getFirmInvoice: (...a: unknown[]) => getFirmInvoiceMock(...a),
  removeFirmInvoiceDraftLine: (...a: unknown[]) => removeLineMock(...a),
  generateFirmInvoiceDraft: (...a: unknown[]) => generateMock(...a),
  abandonFirmInvoiceDraft: (...a: unknown[]) => abandonMock(...a),
  getFirmInvoiceDraftCandidates: (...a: unknown[]) => candidatesMock(...a),
  addLinesToFirmInvoiceDraft: (...a: unknown[]) => addLinesMock(...a),
  sendFirmInvoice: vi.fn(),
  markFirmInvoicePaid: vi.fn(),
  getFirmInvoicePdfUrl: (id: number) => `/api/firm-invoices/${id}/pdf`,
}));
vi.mock("../../../features/billing-shared/components/DocumentFinancePanel", () => ({ default: () => null }));
vi.mock("../../../ui/toast/useToast", () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }) }));

function invoice(overrides: Record<string, unknown> = {}) {
  return {
    id: 42, number: "FIRM-2026-042", firm: { id: 10, name: "Arthrex" }, status: "SENT", documentType: "STANDARD",
    periodStart: "2026-09-01", periodEnd: "2026-09-30", totalAmount: "350.00", currency: "EUR",
    generatedAt: "2026-09-12T10:00:00+02:00", sentAt: "2026-09-13T10:00:00+02:00", paidAt: null,
    billingEmailTo: "billing@arthrex.test", billingEmailCc: [], allowedActions: ["markPaid"],
    lines: [{
      id: 1, missionId: 501, missionDate: "2026-09-02", interventionId: 11, materialLineId: null,
      lineType: "INTERVENTION_FEE", descriptionSnapshot: "Forfait LCA — Arthrex", firmNameSnapshot: "Arthrex",
      unitPrice: "350.00", quantity: "1.00", totalAmount: "350.00", currency: "EUR",
      financialCalculationLineId: 9001, financialCalculationId: 77, siteName: "Delta", surgeonName: "Dr X",
      interventionLabel: "Ligamentoplastie LCA", materialLabel: null, materialReferenceCode: null, sourceKey: "INTERVENTION:11",
    }, {
      id: 2, missionId: 501, missionDate: "2026-09-02", interventionId: null, materialLineId: 456,
      lineType: "MATERIAL_FEE", descriptionSnapshot: "Ancre — Arthrex", firmNameSnapshot: "Arthrex",
      unitPrice: "25.00", quantity: "2.00", totalAmount: "50.00", currency: "EUR",
      financialCalculationLineId: 9002, financialCalculationId: 77, siteName: "Delta", surgeonName: "Dr X",
      interventionLabel: null, materialLabel: "Ancre 5mm", materialReferenceCode: "REF-55", sourceKey: "MATERIAL:456",
    }],
    ...overrides,
  };
}

function renderPage(url = "/app/m/billing/firm-invoices/42") {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[url]}>
        <Routes><Route path="/app/m/billing/firm-invoices/:id" element={<FirmInvoiceDetailPage />} /></Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

const scrollSpy = vi.fn();
beforeEach(() => {
  getFirmInvoiceMock.mockReset();
  removeLineMock.mockReset();
  generateMock.mockReset();
  abandonMock.mockReset();
  candidatesMock.mockReset();
  addLinesMock.mockReset();
  scrollSpy.mockReset();
  (Element.prototype as any).scrollIntoView = scrollSpy;
});

describe("FirmInvoiceDetailPage — preuve de ce qui a été facturé (D-123)", () => {
  it("affiche chaque ligne snapshotée avec son contexte et un lien vers sa source", async () => {
    getFirmInvoiceMock.mockResolvedValue(invoice());
    renderPage();

    expect(await screen.findByText("Ligamentoplastie LCA")).toBeInTheDocument();
    expect(screen.getAllByText("Delta · Dr X")).toHaveLength(2);
    expect(screen.getAllByText("02/09/2026")).toHaveLength(2);
    expect(screen.getByText("01/09/2026 → 30/09/2026")).toBeInTheDocument();
    expect(screen.getAllByRole("link", { name: "Mission #501" })[0]).toHaveAttribute("href", "/app/m/missions/501");
    expect(screen.getByText("Ligne financière #9001 · calcul #77")).toBeInTheDocument();
  });

  it("SENT : « Marquer comme payée » proposé, pas d'envoi", async () => {
    getFirmInvoiceMock.mockResolvedValue(invoice());
    renderPage();

    expect(await screen.findByRole("button", { name: "Marquer comme payée" })).toBeInTheDocument();
    expect(screen.queryByText("Envoyer par email")).not.toBeInTheDocument();
  });

  it("GENERATED : envoi proposé, jamais « Marquer comme payée »", async () => {
    getFirmInvoiceMock.mockResolvedValue(invoice({ status: "GENERATED", sentAt: null, allowedActions: ["send", "cancel"] }));
    renderPage();

    expect(await screen.findByText("Envoyer par email")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Marquer comme payée" })).not.toBeInTheDocument();
  });

  it("CANCELLED / PAID : aucune action de transition", async () => {
    getFirmInvoiceMock.mockResolvedValue(invoice({ status: "CANCELLED", allowedActions: [] }));
    renderPage();

    expect(await screen.findByText("Facture annulée")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Marquer comme payée" })).not.toBeInTheDocument();
    expect(screen.queryByText("Envoyer par email")).not.toBeInTheDocument();
  });
});

describe("FirmInvoiceDetailPage — deep-link depuis la worklist (D-134)", () => {
  it("?focusLine= fait défiler jusqu'à la ligne, la met en évidence et la marque « Ligne recherchée »", async () => {
    getFirmInvoiceMock.mockResolvedValue(invoice());
    renderPage("/app/m/billing/firm-invoices/42?focusLine=MATERIAL:456");

    const target = await screen.findByTestId("invoice-line-MATERIAL:456");
    expect(within(target).getByText("Ligne recherchée")).toBeInTheDocument();
    expect(target).toHaveAttribute("aria-current", "true");
    expect(scrollSpy).toHaveBeenCalledTimes(1);
    expect(scrollSpy.mock.contexts[0]).toBe(target);
    expect(within(screen.getByTestId("invoice-line-INTERVENTION:11")).queryByText("Ligne recherchée")).not.toBeInTheDocument();
  });

  it("le focus survit à un rechargement : même URL, même ligne ciblée", async () => {
    getFirmInvoiceMock.mockResolvedValue(invoice());
    const first = renderPage("/app/m/billing/firm-invoices/42?focusLine=MATERIAL:456");
    await screen.findByTestId("invoice-line-MATERIAL:456");
    first.unmount();

    renderPage("/app/m/billing/firm-invoices/42?focusLine=MATERIAL:456");
    const target = await screen.findByTestId("invoice-line-MATERIAL:456");
    expect(within(target).getByText("Ligne recherchée")).toBeInTheDocument();
    expect(scrollSpy).toHaveBeenCalledTimes(2);
  });

  it("une ligne qui n'est plus sur la facture est signalée, sans mise en évidence trompeuse", async () => {
    getFirmInvoiceMock.mockResolvedValue(invoice());
    renderPage("/app/m/billing/firm-invoices/42?focusLine=MATERIAL:999");

    expect(await screen.findByText(/La ligne recherchée ne figure plus sur ce document/)).toBeInTheDocument();
    expect(screen.queryByText("Ligne recherchée")).not.toBeInTheDocument();
    expect(scrollSpy).not.toHaveBeenCalled();
  });
});

describe("FirmInvoiceDetailPage — générateur de brouillon (D-135)", () => {
  function draft(overrides: Record<string, unknown> = {}) {
    const base = invoice({ status: "DRAFT", number: null, generatedAt: null, sentAt: null, allowedActions: ["editLines", "generate", "abandon"] }) as any;
    base.lines = base.lines.map((l: any) => ({ ...l, stale: false }));
    return { ...base, ...overrides };
  }

  it("ouvre le brouillon (pas la liste) sur la ligne recherchée, sans numéro ni PDF ni envoi", async () => {
    getFirmInvoiceMock.mockResolvedValue(draft());
    renderPage("/app/m/billing/firm-invoices/42?focusLine=MATERIAL:456");

    expect(await screen.findByText("Brouillon Arthrex #42")).toBeInTheDocument();
    expect(screen.getByRole("region", { name: "Générateur de facture" })).toBeInTheDocument();
    expect(within(screen.getByTestId("invoice-line-MATERIAL:456")).getByText("Ligne recherchée")).toBeInTheDocument();
    expect(scrollSpy).toHaveBeenCalledTimes(1);
    expect(screen.queryByText("Télécharger PDF")).not.toBeInTheDocument();
    expect(screen.queryByText("Envoyer par email")).not.toBeInTheDocument();
  });

  it("retire une ligne du brouillon par son endpoint dédié", async () => {
    const user = userEvent.setup();
    getFirmInvoiceMock.mockResolvedValue(draft());
    removeLineMock.mockResolvedValue(draft({ lines: [] }));
    renderPage();

    await user.click(await screen.findByRole("button", { name: "Retirer Ancre 5mm du brouillon" }));
    await waitFor(() => expect(removeLineMock).toHaveBeenCalledWith(42, 2));
  });

  it("une ligne obsolète bloque la génération et est signalée", async () => {
    getFirmInvoiceMock.mockResolvedValue(draft({ lines: (draft().lines as any[]).map((l, i) => ({ ...l, stale: i === 1 })) }));
    renderPage();

    expect(await screen.findByText(/1 ligne obsolète/)).toBeInTheDocument();
    expect(within(screen.getByTestId("invoice-line-MATERIAL:456")).getByText("Obsolète")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Générer la facture" })).toBeDisabled();
  });

  it("génère la facture puis abandonne un autre brouillon après confirmation explicite", async () => {
    const user = userEvent.setup();
    getFirmInvoiceMock.mockResolvedValue(draft());
    generateMock.mockResolvedValue(invoice({ status: "GENERATED", number: "FIRM-2026-050" }));
    abandonMock.mockResolvedValue(draft({ status: "CANCELLED", allowedActions: [] }));
    renderPage();

    await user.click(await screen.findByRole("button", { name: "Générer la facture" }));
    await waitFor(() => expect(generateMock).toHaveBeenCalledWith(42));

    await user.click(screen.getByRole("button", { name: "Abandonner le brouillon" }));
    expect(abandonMock).not.toHaveBeenCalled();
    await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: "Abandonner" }));
    await waitFor(() => expect(abandonMock).toHaveBeenCalledWith(42));
  });
});

describe("FirmInvoiceDetailPage — ajouter des prestations depuis le brouillon (D-137)", () => {
  function draft(overrides: Record<string, unknown> = {}) {
    const base = invoice({ status: "DRAFT", number: null, generatedAt: null, sentAt: null, allowedActions: ["editLines", "generate", "abandon"] }) as any;
    base.lines = base.lines.map((l: any) => ({ ...l, stale: false }));
    return { ...base, ...overrides };
  }
  function candidate(key: string, id: number, extra: Record<string, unknown> = {}) {
    return {
      key, sourceType: "MATERIAL", sourceId: id, sourceKey: `MATERIAL:${id}`,
      mission: { id: 600 + id, date: "2026-09-20", status: "VALIDATED", site: "Delta", surgeon: "Dr Y" },
      firm: { id: 10, name: "Arthrex" }, label: `Vis ${id}`, reference: `REF-${id}`, quantity: "3",
      billingStatus: "BILLABLE", billingStatusLabel: "Facturable", reasonCode: "BILLABLE", reasonLabel: "Facturable", reasonDetail: "",
      amount: "60.00", currency: "EUR", currentInvoice: null, invoiceState: "FREE", invoiceStateLabel: "Libre", hasHistory: false,
      financialLineId: 9100 + id, calculationId: 80, canInvoice: true, canMoveToDraft: false, ...extra,
    };
  }

  it("liste les prestations fournies par le backend, sélection multiple, ajout puis rafraîchissement du brouillon", async () => {
    const user = userEvent.setup();
    getFirmInvoiceMock.mockResolvedValue(draft());
    candidatesMock.mockResolvedValue([candidate("MATERIAL_LINE:1", 1), candidate("MATERIAL_LINE:2", 2, { sourceType: "INTERVENTION", label: "LCA bis", reference: null, quantity: "1", amount: "300.00" })]);
    addLinesMock.mockResolvedValue(draft());
    renderPage();

    await user.click(await screen.findByRole("button", { name: "+ Ajouter des prestations" }));
    const dialog = await screen.findByRole("dialog", { name: /Ajouter des prestations — Arthrex/ });
    expect(candidatesMock).toHaveBeenCalledWith(42);
    const row1 = await within(dialog).findByTestId("candidate-MATERIAL_LINE:1");
    expect(within(row1).getByText("Vis 1")).toBeInTheDocument();
    expect(within(row1).getByText("REF-1")).toBeInTheDocument();
    expect(within(row1).getByText("Matériel")).toBeInTheDocument();
    expect(within(row1).getByText(/60,00/)).toBeInTheDocument();
    expect(within(within(dialog).getByTestId("candidate-MATERIAL_LINE:2")).getByText("Intervention")).toBeInTheDocument();

    expect(within(dialog).getByRole("button", { name: "Ajouter au brouillon" })).toBeDisabled();
    await user.click(within(dialog).getByRole("checkbox", { name: "Sélectionner Vis 1" }));
    await user.click(within(dialog).getByRole("checkbox", { name: "Sélectionner LCA bis" }));
    await user.click(within(dialog).getByRole("button", { name: "Ajouter au brouillon (2)" }));

    await waitFor(() => expect(addLinesMock).toHaveBeenCalledWith(42, [9101, 9102]));
    await waitFor(() => expect(getFirmInvoiceMock).toHaveBeenCalledTimes(2)); // brouillon (lignes + total) rechargé
  });

  it("affiche un état vide clair quand aucune prestation n'est disponible", async () => {
    const user = userEvent.setup();
    getFirmInvoiceMock.mockResolvedValue(draft());
    candidatesMock.mockResolvedValue([]);
    renderPage();

    await user.click(await screen.findByRole("button", { name: "+ Ajouter des prestations" }));
    expect(await screen.findByText("Aucune autre prestation disponible pour cette firme.")).toBeInTheDocument();
  });

  it("un brouillon abandonné s'affiche comme tel, en lecture seule, sans générateur ni ajout", async () => {
    getFirmInvoiceMock.mockResolvedValue(invoice({ status: "ABANDONED", number: null, generatedAt: null, sentAt: null, allowedActions: [], lines: [] }));
    renderPage();

    expect(await screen.findByText("Brouillon abandonné Arthrex #42")).toBeInTheDocument();
    expect(screen.getByText("Brouillon abandonné", { selector: ".MuiChip-label" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "+ Ajouter des prestations" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Générer la facture" })).not.toBeInTheDocument();
    expect(screen.getByText(/il n'a jamais été une facture/)).toBeInTheDocument();
    expect(screen.queryByText("Télécharger PDF")).not.toBeInTheDocument();
    expect(screen.queryByText("Lignes facturées (0)")).not.toBeInTheDocument();
  });
});
