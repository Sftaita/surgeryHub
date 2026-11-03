// D-180 — données de test au format de l'API (reprises des maquettes du handoff, octobre 2026).
import type { InstrumentistHoursDetail, InstrumentistHoursList, InstrumentistHoursRow, PersonalAverageComparison } from "./api/instrumentistHours.types";

const h = (hours: number, minutes = 0) => hours * 60 + minutes;

const prev = (validatedMinutes: number, pct: number | null, reason: "ZERO_REFERENCE" | "NO_REFERENCE" | null = null) =>
  ({ validatedMinutes, pct, available: pct !== null, reason: pct === null ? reason ?? "ZERO_REFERENCE" : null });

const avg = (validatedMinutes: number, pct: number | null, extra: Partial<PersonalAverageComparison> = {}): PersonalAverageComparison => ({
  validatedMinutes, pct, available: pct !== null, reason: pct === null ? "ZERO_REFERENCE" : null,
  monthsCounted: 6, from: "2026-04", to: "2026-09", observableSince: "2025-03", ...extra,
});

function row(id: number, firstname: string, lastname: string, p: Partial<InstrumentistHoursRow>): InstrumentistHoursRow {
  return {
    instrumentist: { id, firstname, lastname, active: true },
    count: 10, pendingCount: 0, missingHoursCount: 0,
    encodedMinutes: 0, validatedMinutes: 0, pendingMinutes: 0,
    estimateState: "RATED",
    estimate: null,
    ratio: 0,
    comparison: { previousMonth: prev(0, null), personalAverage: avg(0, null) },
    ...p,
  };
}

const eur = (encodedAmount: string, validatedAmount: string | null, consultationFeeCount = 0, unratedCount = 0) =>
  ({ currency: "EUR", encodedAmount, validatedAmount, consultationFeeCount, unratedCount });

export function listFixture(month = "2026-10", previousMonth = "2026-09"): InstrumentistHoursList {
  return {
    month, provisional: false, asOfDay: null, siteId: null, q: null, sort: "validatedMinutes", direction: "desc",
    sites: [{ id: 3, name: "CHU Brugmann" }, { id: 5, name: "Hôpital Erasme" }],
    reference: { previousMonth },
    totals: {
      instrumentistCount: 7, count: 70, pendingCount: 5, encodedMinutes: h(538, 0), validatedMinutes: h(476, 0), pendingMinutes: h(62, 0),
      validatedPct: 88, currency: "EUR", encodedAmount: "20000.00", validatedAmount: "18000.00", unratedInstrumentistCount: 1, unratedCount: 8,
    },
    items: [
      row(11, "Claire", "Dubois", { encodedMinutes: h(112, 30), validatedMinutes: h(104), pendingMinutes: h(8, 30), ratio: 1, estimate: eur("5062.50", "4680.00"),
        comparison: { previousMonth: prev(h(96), 8), personalAverage: avg(h(98), 6) } }),
      row(12, "Thomas", "Leroy", { encodedMinutes: h(98, 15), validatedMinutes: h(92, 45), pendingMinutes: h(5, 30), ratio: 0.892, estimate: eur("4126.50", "3895.50"),
        comparison: { previousMonth: prev(h(101, 30), -9), personalAverage: avg(h(94), -1) } }),
      row(41, "Nadia", "Benali", { encodedMinutes: h(70, 30), validatedMinutes: h(68), pendingMinutes: h(2, 30), ratio: 0.654, estimate: eur("3419.00", "3304.00", 3),
        comparison: { previousMonth: prev(h(72), -6), personalAverage: avg(h(66), 3) } }),
      row(15, "Marc", "Janssens", { encodedMinutes: h(66), validatedMinutes: h(52, 30), pendingMinutes: h(13, 30), ratio: 0.505, estimateState: "UNRATED",
        comparison: { previousMonth: prev(h(58), -9), personalAverage: avg(h(61), -14) } }),
      row(16, "Elise", "Wouters", { encodedMinutes: h(41, 15), validatedMinutes: h(41, 15), ratio: 0.397, estimate: eur("1567.50", "1567.50"),
        comparison: { previousMonth: prev(0, null, "NO_REFERENCE"), personalAverage: avg(0, null, { reason: "NO_REFERENCE", monthsCounted: 0, from: null, to: null, observableSince: month }) } }),
      row(17, "Paul", "Renard", { instrumentist: { id: 17, firstname: "Paul", lastname: "Renard", active: false }, encodedMinutes: h(22), validatedMinutes: h(22), ratio: 0.212, estimate: eur("902.00", "902.00"),
        comparison: { previousMonth: prev(h(40), -45), personalAverage: avg(h(38), -42, { monthsCounted: 4, from: "2026-06", observableSince: "2026-06" }) } }),
      row(18, "Laura", "Gillet", { count: 0, estimateState: "NO_PRESTATION",
        comparison: { previousMonth: prev(0, null), personalAverage: avg(h(10), -100) } }),
    ],
  };
}

export function detailFixture(month = "2026-10"): InstrumentistHoursDetail {
  return {
    month, provisional: false, asOfDay: null, siteId: null,
    instrumentist: { id: 41, firstname: "Nadia", lastname: "Benali", active: true },
    sites: [{ id: 3, name: "CHU Brugmann", count: 2 }, { id: 7, name: "Clinique Saint-Jean", count: 1 }],
    summary: { count: 3, encodedMinutes: h(17, 15), validatedMinutes: h(17, 15), pendingMinutes: 0, validatedPct: 100, missingHoursCount: 1 },
    estimate: {
      currency: "EUR", encodedAmount: "913.50", validatedAmount: "913.50", hourlyRateMin: "46.00", hourlyRateMax: "46.00", unratedCount: 0,
      lines: [
        { siteName: "CHU Brugmann", rateType: "HOURLY_RATE", unitAmount: "46.00", minutes: h(17, 15), count: 2, amount: "793.50" },
        { siteName: "Clinique Saint-Jean", rateType: "CONSULTATION_FEE", unitAmount: "120.00", minutes: null, count: 1, amount: "120.00" },
      ],
    },
    comparison: { previousMonth: prev(h(72), -6), personalAverage: avg(h(66), 3, { monthsCounted: 4, from: "2026-06", observableSince: "2026-06" }) },
    items: [
      { missionId: 5098, date: `${month}-20`, start: null, end: null, endsNextDay: false, minutes: h(8, 15), durationSource: "PRESENCE_SHARE", missionType: "BLOCK",
        status: "validated", surgeonName: "Dr Antoine Mertens", site: { id: 3, name: "CHU Brugmann" },
        estimate: { rateType: "HOURLY_RATE", unitAmount: "46.00", amount: "379.50", currency: "EUR" } },
      { missionId: 5104, date: `${month}-21`, start: null, end: null, endsNextDay: false, minutes: null, durationSource: null, missionType: "CONSULTATION",
        status: "validated", surgeonName: "Dr Lucie Van Damme", site: { id: 7, name: "Clinique Saint-Jean" },
        estimate: { rateType: "CONSULTATION_FEE", unitAmount: "120.00", amount: "120.00", currency: "EUR" } },
      { missionId: 5087, date: `${month}-16`, start: `${month}-16T18:00:00+02:00`, end: `${month}-17T03:00:00+02:00`, endsNextDay: true, minutes: h(9), durationSource: "ACTUAL_TIMES",
        missionType: "BLOCK", status: "validated", surgeonName: "Dr Hélène Claes", site: { id: 3, name: "CHU Brugmann" },
        estimate: { rateType: "HOURLY_RATE", unitAmount: "46.00", amount: "414.00", currency: "EUR" } },
    ],
  };
}
