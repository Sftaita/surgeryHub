import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import InstrumentistStatementsPage from "./InstrumentistStatementsPage";

vi.mock("../../../features/billing-instrumentist/api/statement.api", () => ({
  getStatements: vi.fn().mockResolvedValue([]),
  markStatementPaid: vi.fn(),
  getStatementPdfUrl: (id: number) => `/api/instrumentist-statements/${id}/pdf`,
}));

vi.mock("../../../features/billing-instrumentist/components/EligibleLinesStatementWizard", () => ({
  default: () => <div data-testid="eligible-lines-statement-wizard">Nouveau flux wizard</div>,
}));

vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}));

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <InstrumentistStatementsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("InstrumentistStatementsPage — suppression du flux legacy (D-121)", () => {
  it("never renders the legacy toggle or its labels", async () => {
    renderPage();
    const user = userEvent.setup();

    expect(screen.queryByText(/Flux classique/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Nouveau flux \(recommandé\)/)).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /Nouveau décompte/i }));

    expect(screen.getByTestId("eligible-lines-statement-wizard")).toBeInTheDocument();
    expect(screen.queryByText(/Flux classique/)).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Nouveau flux/i })).not.toBeInTheDocument();
  });
});
