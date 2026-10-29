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
  return { ...actual, getInstrumentistRates: vi.fn(), changeInstrumentistRateEffectiveDate: vi.fn() };
});

import { changeInstrumentistRateEffectiveDate, getInstrumentistRates } from "../api/instrumentistRate.api";
import userEvent from "@testing-library/user-event";
import { fireEvent } from "@testing-library/react";

beforeEach(() => {
  vi.mocked(getInstrumentistRates).mockReset();
  vi.mocked(changeInstrumentistRateEffectiveDate).mockReset();
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

describe("InstrumentistVersionedRates — date de prise d'effet (D-172)", () => {
  const salve = [
    rate({ id: 12, amount: "65.00", validFrom: "2026-10-11", validTo: null }),
    rate({ id: 8, amount: "0.00", validFrom: "2026-09-07", validTo: "2026-10-11" }),
  ];
  const impact = (overrides: Record<string, unknown> = {}) => ({
    dryRun: true, rate: salve[0], previousRate: salve[1], oldValidFrom: "2026-10-11", newValidFrom: "2026-10-01",
    period: { from: "2026-10-01", to: "2026-10-11" }, lockedCalculations: [],
    staleCalculations: [{ calculationId: 27, missionId: 1039, missionDate: "2026-10-01", status: "CALCULATED" }],
    ...overrides,
  });

  it("n'affiche plus aucune mention de l'ancien flux", async () => {
    vi.mocked(getInstrumentistRates).mockResolvedValue(salve);
    renderRates();
    await screen.findAllByTestId("rate-effective-date-12");
    expect(screen.queryByText(/Ancien flux|Tarif bloc/)).toBeNull();
  });

  it("aperçu de l'impact (dryRun) puis confirmation ; les calculs à recalculer restent listés", async () => {
    const user = userEvent.setup();
    vi.mocked(getInstrumentistRates).mockResolvedValue(salve);
    vi.mocked(changeInstrumentistRateEffectiveDate)
      .mockResolvedValueOnce(impact() as any)
      .mockResolvedValueOnce(impact({ dryRun: false }) as any);
    renderRates();

    await user.click((await screen.findAllByTestId("rate-effective-date-12"))[0]);
    fireEvent.change(screen.getByTestId("rate-effective-date-input"), { target: { value: "2026-10-01" } });
    await user.click(screen.getByTestId("rate-effective-date-check"));

    expect(changeInstrumentistRateEffectiveDate).toHaveBeenNthCalledWith(1, 42, 12, { validFrom: "2026-10-01", dryRun: true });
    const shown = await screen.findByTestId("rate-effective-date-impact");
    expect(shown).toHaveTextContent("du 01/10/2026 au 10/10/2026 inclus");
    expect(shown).toHaveTextContent("s'arrêtera le 30/09/2026");
    expect(screen.getByTestId("rate-effective-date-stale")).toHaveTextContent("Mission #1039 du 01/10/2026");

    await user.click(screen.getByTestId("rate-effective-date-confirm"));
    expect(changeInstrumentistRateEffectiveDate).toHaveBeenNthCalledWith(2, 42, 12, { validFrom: "2026-10-01", dryRun: false });
    expect(await screen.findByTestId("rate-effective-date-done")).toHaveTextContent("Mission #1039");
  });

  it("un calcul verrouillé dans la période empêche la confirmation", async () => {
    const user = userEvent.setup();
    vi.mocked(getInstrumentistRates).mockResolvedValue(salve);
    vi.mocked(changeInstrumentistRateEffectiveDate).mockResolvedValueOnce(impact({
      staleCalculations: [], lockedCalculations: [{ calculationId: 22, missionId: 1089, missionDate: "2026-10-05", status: "LOCKED" }],
    }) as any);
    renderRates();

    await user.click((await screen.findAllByTestId("rate-effective-date-12"))[0]);
    fireEvent.change(screen.getByTestId("rate-effective-date-input"), { target: { value: "2026-10-01" } });
    await user.click(screen.getByTestId("rate-effective-date-check"));

    expect(await screen.findByTestId("rate-effective-date-locked")).toHaveTextContent("Mission #1089 du 05/10/2026 — calcul verrouillé");
    expect(screen.getByTestId("rate-effective-date-confirm")).toBeDisabled();
  });
});
