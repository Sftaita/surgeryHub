import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import InstrumentistVersionedRates from "./InstrumentistVersionedRates";
import type { InstrumentistRate } from "../api/instrumentistRate.api";
import { ToastProvider } from "../../../ui/toast/ToastProvider";

vi.mock("../api/instrumentistRate.api", async () => {
  const actual = await vi.importActual<typeof import("../api/instrumentistRate.api")>(
    "../api/instrumentistRate.api",
  );
  return { ...actual, getInstrumentistRates: vi.fn() };
});

import { getInstrumentistRates } from "../api/instrumentistRate.api";

beforeEach(() => {
  vi.mocked(getInstrumentistRates).mockReset();
});

function rate(overrides: Partial<InstrumentistRate> = {}): InstrumentistRate {
  return {
    id: 1,
    instrumentist: { id: 42 },
    rateType: "HOURLY_RATE",
    amount: "45.00",
    currency: "EUR",
    validFrom: "2026-01-01",
    validTo: null,
    ...overrides,
  };
}

function renderRates() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <ToastProvider>
        <InstrumentistVersionedRates instrumentistId={42} />
      </ToastProvider>
    </QueryClientProvider>,
  );
}

const NO_ACTIVE_RATE_TEXT = "Aucun tarif horaire actif";

describe("InstrumentistVersionedRates — diagnostic tarifs instrumentistes (2026-08-05)", () => {
  it("affiche « Aucun tarif horaire actif » quand aucune version n'existe", async () => {
    vi.mocked(getInstrumentistRates).mockResolvedValue([]);

    renderRates();

    await waitFor(() => expect(screen.getByText(NO_ACTIVE_RATE_TEXT, { exact: false })).toBeInTheDocument());
  });

  it("affiche « Aucun tarif horaire actif » quand seule une version future existe", async () => {
    const nextYear = String(new Date().getFullYear() + 1);
    vi.mocked(getInstrumentistRates).mockResolvedValue([
      rate({ validFrom: `${nextYear}-01-01`, validTo: null }),
    ]);

    renderRates();

    await waitFor(() => expect(screen.getByText(NO_ACTIVE_RATE_TEXT, { exact: false })).toBeInTheDocument());
  });

  it("affiche « Aucun tarif horaire actif » quand seule une version expirée existe", async () => {
    vi.mocked(getInstrumentistRates).mockResolvedValue([
      rate({ validFrom: "2020-01-01", validTo: "2020-06-01" }),
    ]);

    renderRates();

    await waitFor(() => expect(screen.getByText(NO_ACTIVE_RATE_TEXT, { exact: false })).toBeInTheDocument());
  });

  it("n'affiche pas l'avertissement quand une version couvre aujourd'hui (validTo null)", async () => {
    vi.mocked(getInstrumentistRates).mockResolvedValue([
      rate({ validFrom: "2026-01-01", validTo: null }),
    ]);

    renderRates();

    await waitFor(() => expect(screen.getByText(/Tarif horaire \(bloc\)/)).toBeInTheDocument());
    expect(screen.queryByText(NO_ACTIVE_RATE_TEXT, { exact: false })).not.toBeInTheDocument();
  });

  it("n'affiche pas l'avertissement quand une version bornée couvre aujourd'hui", async () => {
    const yesterday = new Date(Date.now() - 86400000).toISOString().slice(0, 10);
    const nextMonth = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10);
    vi.mocked(getInstrumentistRates).mockResolvedValue([
      rate({ validFrom: yesterday, validTo: nextMonth }),
    ]);

    renderRates();

    await waitFor(() => expect(screen.getByText(/Tarif horaire \(bloc\)/)).toBeInTheDocument());
    expect(screen.queryByText(NO_ACTIVE_RATE_TEXT, { exact: false })).not.toBeInTheDocument();
  });
});
