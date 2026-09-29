import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
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
      interventionLabel: "Ligamentoplastie LCA", materialLabel: null, materialReferenceCode: null,
    }],
    ...overrides,
  };
}

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={["/app/m/billing/firm-invoices/42"]}>
        <Routes><Route path="/app/m/billing/firm-invoices/:id" element={<FirmInvoiceDetailPage />} /></Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => getFirmInvoiceMock.mockReset());

describe("FirmInvoiceDetailPage — preuve de ce qui a été facturé (D-123)", () => {
  it("affiche chaque ligne snapshotée avec son contexte et un lien vers sa source", async () => {
    getFirmInvoiceMock.mockResolvedValue(invoice());
    renderPage();

    expect(await screen.findByText("Ligamentoplastie LCA")).toBeInTheDocument();
    expect(screen.getByText("Delta · Dr X")).toBeInTheDocument();
    expect(screen.getByText("02/09/2026")).toBeInTheDocument();
    expect(screen.getByText("01/09/2026 → 30/09/2026")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Mission #501" })).toHaveAttribute("href", "/app/m/missions/501");
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
