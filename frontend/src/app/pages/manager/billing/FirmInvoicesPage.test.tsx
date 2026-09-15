import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import FirmInvoicesPage from "./FirmInvoicesPage";

vi.mock("../../../features/billing-firm/api/firmInvoice.api", () => ({
  getFirmInvoices: vi.fn().mockResolvedValue([]),
  markFirmInvoicePaid: vi.fn(),
  getFirmInvoicePdfUrl: (id: number) => `/api/firm-invoices/${id}/pdf`,
}));

vi.mock("../../../features/billing-firm/components/EligibleLinesInvoiceWizard", () => ({
  default: () => <div data-testid="eligible-lines-wizard">Nouveau flux wizard</div>,
}));

vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}));

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <FirmInvoicesPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("FirmInvoicesPage — suppression du flux legacy (D-121)", () => {
  it("never renders the legacy toggle or its labels", async () => {
    renderPage();
    const user = userEvent.setup();

    expect(screen.queryByText(/Flux classique/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Nouveau flux \(recommandé\)/)).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /Nouvelle facture/i }));

    // Le wizard s'affiche directement, sans aucun choix de flux à faire.
    expect(screen.getByTestId("eligible-lines-wizard")).toBeInTheDocument();
    expect(screen.queryByText(/Flux classique/)).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Nouveau flux/i })).not.toBeInTheDocument();
  });
});
