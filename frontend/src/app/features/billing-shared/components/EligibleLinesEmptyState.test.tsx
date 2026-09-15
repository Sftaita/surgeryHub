import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import EligibleLinesEmptyState from "./EligibleLinesEmptyState";
import type { EligibleLinesDiagnostic } from "../../billing-firm/api/firmInvoice.api";

function diag(overrides: Partial<EligibleLinesDiagnostic>): EligibleLinesDiagnostic {
  return {
    code: "NO_ELIGIBLE_LINES",
    validatedMissionCount: 0,
    calculationCount: 0,
    calculatedCount: 0,
    approvedCount: 0,
    lockedCount: 0,
    missingPricingCount: 0,
    currencyMismatchCount: 0,
    alreadyInvoicedCount: 0,
    reasons: [],
    ...overrides,
  };
}

describe("EligibleLinesEmptyState", () => {
  it("falls back to a generic message when no diagnostic is provided", () => {
    render(<EligibleLinesEmptyState />);
    expect(screen.getByText(/Aucune ligne éligible pour cette période/)).toBeInTheDocument();
  });

  it("explains that no mission has been validated", () => {
    render(<EligibleLinesEmptyState diagnostic={diag({ reasons: ["NO_VALIDATED_MISSIONS"] })} />);
    expect(screen.getByText(/Suivi des encodages/)).toBeInTheDocument();
  });

  it("explains pending approval with the exact count", () => {
    render(<EligibleLinesEmptyState diagnostic={diag({ calculatedCount: 4, reasons: ["CALCULATIONS_PENDING_APPROVAL"] })} />);
    expect(screen.getByText(/4 calcul\(s\) financier\(s\)/)).toBeInTheDocument();
    expect(screen.getByText(/en attente d'approbation/)).toBeInTheDocument();
  });

  it("explains missing pricing with the exact mission count", () => {
    render(<EligibleLinesEmptyState diagnostic={diag({ missingPricingCount: 3, reasons: ["MISSING_PRICING"] })} />);
    expect(screen.getByText(/3 mission\(s\)/)).toBeInTheDocument();
    expect(screen.getByText(/tarif configuré/)).toBeInTheDocument();
  });

  it("explains that everything has already been invoiced", () => {
    render(<EligibleLinesEmptyState diagnostic={diag({ alreadyInvoicedCount: 2, reasons: ["ALREADY_INVOICED"] })} />);
    expect(screen.getByText(/déjà été facturées/)).toBeInTheDocument();
  });

  it("renders one alert per reason when several causes coexist", () => {
    render(
      <EligibleLinesEmptyState
        diagnostic={diag({ reasons: ["CALCULATIONS_PENDING_APPROVAL", "MISSING_PRICING"], calculatedCount: 1, missingPricingCount: 2 })}
      />,
    );
    expect(screen.getAllByRole("alert")).toHaveLength(2);
  });
});
