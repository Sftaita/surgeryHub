import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ZeroFinancialDataExplanation } from "./ZeroFinancialDataExplanation";
import type { EncodingTrackingSummary } from "../../encoding-tracking/api/encodingTracking.api";

const getEncodingTrackingSummaryMock = vi.fn();
vi.mock("../../encoding-tracking/api/encodingTracking.api", async () => {
  const actual = await vi.importActual<typeof import("../../encoding-tracking/api/encodingTracking.api")>(
    "../../encoding-tracking/api/encodingTracking.api",
  );
  return { ...actual, getEncodingTrackingSummary: (...args: unknown[]) => getEncodingTrackingSummaryMock(...args) };
});

function emptySummary(overrides: Partial<EncodingTrackingSummary> = {}): EncodingTrackingSummary {
  return {
    totalMissions: 0, encodingExpected: 0, upcoming: 0, toEncode: 0, inProgress: 0,
    submitted: 0, validated: 0, locked: 0, notApplicable: 0, staleInProgress: 0,
    financialAnomalies: 0, encoded: 0, missingEncoding: 0, toTreat: 0,
    hasFinanciallyEligibleMissions: false,
    ...overrides,
  };
}

function renderWith(summary: ReturnType<typeof emptySummary> | null, isError = false) {
  if (isError) {
    getEncodingTrackingSummaryMock.mockRejectedValue(new Error("network error"));
  } else {
    getEncodingTrackingSummaryMock.mockResolvedValue({ period: { from: "", to: "" }, summary });
  }
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <ZeroFinancialDataExplanation filter={{ from: "2026-09-01T00:00:00", to: "2026-10-01T00:00:00" }} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  getEncodingTrackingSummaryMock.mockReset();
});

describe("ZeroFinancialDataExplanation", () => {
  it("explique une période sans mission à encoder", async () => {
    renderWith(emptySummary());
    await waitFor(() => expect(screen.getByText(/ne nécessite d'encodage/)).toBeInTheDocument());
  });

  it("explique le cas 11 exécutées / 0 validées avec la ventilation canonique du backend", async () => {
    renderWith(emptySummary({ encodingExpected: 11, toEncode: 6, inProgress: 2, submitted: 3, financialAnomalies: 1 }));

    await waitFor(() => expect(screen.getByText(/11 mission\(s\) nécessitent un encodage/)).toBeInTheDocument());
    expect(screen.getByText(/6 à encoder/)).toBeInTheDocument();
    expect(screen.getByText(/2 en cours d'encodage/)).toBeInTheDocument();
    expect(screen.getByText(/3 soumise\(s\) non validée\(s\)/)).toBeInTheDocument();
    expect(screen.getByText(/1 anomalie\(s\) financière\(s\)/)).toBeInTheDocument();
  });

  it("n'affiche jamais une catégorie à zéro (pas de fausse ventilation)", async () => {
    renderWith(emptySummary({ encodingExpected: 5, toEncode: 5 }));

    await waitFor(() => expect(screen.getByText(/5 à encoder/)).toBeInTheDocument());
    expect(screen.queryByText(/en cours d'encodage/)).toBeNull();
    expect(screen.queryByText(/soumise\(s\)/)).toBeNull();
    expect(screen.queryByText(/anomalie\(s\)/)).toBeNull();
  });

  it("distingue « validé mais calcul pas encore lancé » du cas « rien n'est encodé »", async () => {
    renderWith(emptySummary({ encodingExpected: 4, validated: 3, locked: 1, hasFinanciallyEligibleMissions: true }));

    await waitFor(() => expect(screen.getByText(/4 mission\(s\) sont validées/)).toBeInTheDocument());
    expect(screen.getByText(/Pipeline/)).toBeInTheDocument();
    expect(screen.queryByText(/ne nécessite d'encodage/)).toBeNull();
  });

  it("retombe sur le message historique si le résumé d'encodage échoue (dégradation gracieuse)", async () => {
    renderWith(null, true);
    await waitFor(() => expect(screen.getByText("Aucune donnée financière sur cette période/ces filtres.")).toBeInTheDocument());
  });

  it("ne recompose jamais de catégorie non fournie par le backend (ex. « tarif manquant »)", async () => {
    renderWith(emptySummary({ encodingExpected: 2, toEncode: 2 }));
    await waitFor(() => expect(screen.getByText(/2 à encoder/)).toBeInTheDocument());
    expect(screen.queryByText(/tarif manquant/i)).toBeNull();
    expect(screen.queryByText(/configuration firme/i)).toBeNull();
  });
});
