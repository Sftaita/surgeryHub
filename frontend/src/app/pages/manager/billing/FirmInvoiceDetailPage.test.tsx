import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import FirmInvoiceDetailPage from "./FirmInvoiceDetailPage";

const getFirmInvoiceMock = vi.fn();
vi.mock("../../../features/billing-firm/api/firmInvoice.api", () => ({
  getFirmInvoice: (...a: unknown[]) => getFirmInvoiceMock(...a),
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

    expect(await screen.findByText("Annulée")).toBeInTheDocument();
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

    expect(await screen.findByText(/La ligne recherchée ne figure plus sur cette facture/)).toBeInTheDocument();
    expect(screen.queryByText("Ligne recherchée")).not.toBeInTheDocument();
    expect(scrollSpy).not.toHaveBeenCalled();
  });
});
