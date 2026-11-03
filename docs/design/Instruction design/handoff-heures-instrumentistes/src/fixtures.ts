// Données des maquettes (octobre 2026, tous sites) au format de l'API proposée (types.ts).
// Fictives. Cohérentes à la minute et au centime : Σ items = totals ; détail Benali = sa ligne.
import type {
  HoursComparison,
  HoursItem,
  InstrumentistHoursDetail,
  InstrumentistHoursList,
  InstrumentistHoursRow,
  PersonalAverageComparison,
} from "./types";

const h = (hours: number, minutes = 0): number => hours * 60 + minutes;

const cmp = (validatedMinutes: number, pct: number | null, reason: HoursComparison["reason"] = "ZERO_REFERENCE"): HoursComparison =>
  pct === null
    ? { validatedMinutes, pct: null, available: false, reason }
    : { validatedMinutes, pct, available: true, reason: null };

// Historique observable (CLAUDE.md § 8) : période avr.–sept. 2026 restreinte aux mois ≥ observableSince.
const avg = (
  validatedMinutes: number, pct: number | null,
  observableSince = "2025-03", monthsCounted = 6, from: string | null = "2026-04",
): PersonalAverageComparison => ({
  ...cmp(validatedMinutes, pct, monthsCounted === 0 ? "NO_REFERENCE" : "ZERO_REFERENCE"),
  monthsCounted,
  from,
  to: monthsCounted === 0 ? null : "2026-09",
  observableSince,
});

const MAX = h(104);

function row(
  id: number, firstname: string, lastname: string,
  counts: { count: number; pending: number; missing?: number },
  encoded: number, validated: number,
  estimate: InstrumentistHoursRow["estimate"],
  previousMonth: HoursComparison, personalAverage: PersonalAverageComparison,
  active = true,
): InstrumentistHoursRow {
  return {
    instrumentist: { id, firstname, lastname, active },
    count: counts.count,
    pendingCount: counts.pending,
    missingHoursCount: counts.missing ?? 0,
    encodedMinutes: encoded,
    validatedMinutes: validated,
    pendingMinutes: encoded - validated,
    estimateState: counts.count === 0 ? "NO_PRESTATION" : estimate === null ? "UNRATED" : "RATED",
    estimate,
    ratio: Math.round((validated / MAX) * 1000) / 1000,
    comparison: { previousMonth, personalAverage },
  };
}

const eur = (encodedAmount: string, validatedAmount: string | null, consultationFeeCount = 0, unratedCount = 0) =>
  ({ currency: "EUR", encodedAmount, validatedAmount, consultationFeeCount, unratedCount });

/** GET /api/manager/instrumentist-hours?month=2026-10 (tri par défaut : heures validées décroissantes). */
export const october2026List: InstrumentistHoursList = {
  month: "2026-10",
  provisional: false,
  asOfDay: null,
  siteId: null,
  q: null,
  sort: "validatedMinutes",
  direction: "desc",
  sites: [
    { id: 3, name: "CHU Brugmann" },
    { id: 5, name: "Hôpital Erasme" },
    { id: 7, name: "Clinique Saint-Jean" },
  ],
  reference: { previousMonth: "2026-09" },
  totals: {
    instrumentistCount: 10,
    count: 78,
    pendingCount: 9,
    encodedMinutes: h(603, 15),
    validatedMinutes: h(538),
    pendingMinutes: h(65, 15),
    validatedPct: 89,
    currency: "EUR",
    encodedAmount: "23183.50",
    validatedAmount: "20975.00",
    unratedInstrumentistCount: 1,
    unratedCount: 8,
  },
  items: [
    row(11, "Claire", "Dubois", { count: 14, pending: 1 }, h(112, 30), h(104), eur("5062.50", "4680.00"), cmp(h(96), 8), avg(h(98), 6)),
    row(12, "Thomas", "Leroy", { count: 12, pending: 1 }, h(98, 15), h(92, 45), eur("4126.50", "3895.50"), cmp(h(101, 30), -9), avg(h(94), -1)),
    row(13, "Sophie", "Martin", { count: 11, pending: 1 }, h(90), h(81, 30), eur("3960.00", "3586.00"), cmp(h(80), 2), avg(h(85), -4)),
    row(14, "Julien", "Peeters", { count: 11, pending: 1 }, h(84, 45), h(76), eur("3390.00", "3040.00"), cmp(h(61), 25), avg(h(70), 9)),
    row(41, "Nadia", "Benali", { count: 12, pending: 1, missing: 1 }, h(70, 30), h(68), eur("3419.00", "3304.00", 3), cmp(h(72), -6), avg(h(66), 3)),
    row(15, "Marc", "Janssens", { count: 8, pending: 2 }, h(66), h(52, 30), null, cmp(h(58), -9), avg(h(61), -14)),
    row(16, "Elise", "Wouters", { count: 5, pending: 0 }, h(41, 15), h(41, 15), eur("1567.50", "1567.50"), cmp(0, null, "NO_REFERENCE"), avg(0, null, "2026-10", 0, null)),
    row(17, "Paul", "Renard", { count: 3, pending: 0 }, h(22), h(22), eur("902.00", "902.00"), cmp(h(40), -45), avg(h(38), -42, "2026-06", 4, "2026-06"), false),
    row(18, "Laura", "Gillet", { count: 0, pending: 0 }, 0, 0, null, cmp(0, null), avg(h(10), -100)),
    row(19, "Hugo", "Lambert", { count: 2, pending: 2 }, h(18), 0, eur("756.00", null), cmp(h(24), -100), avg(h(30), -100)),
  ],
};

const BRU = { id: 3, name: "CHU Brugmann" };
const ERA = { id: 5, name: "Hôpital Erasme" };
const STJ = { id: 7, name: "Clinique Saint-Jean" };

function block(missionId: number, date: string, surgeonName: string, site: { id: number; name: string },
  start: string | null, end: string | null, minutes: number, status: HoursItem["status"], amount: string,
  durationSource: HoursItem["durationSource"] = "ACTUAL_TIMES", endsNextDay = false): HoursItem {
  return {
    missionId, date, start, end, endsNextDay, minutes, durationSource, missionType: "BLOCK", status, surgeonName, site,
    estimate: { rateType: "HOURLY_RATE", unitAmount: "46.00", amount, currency: "EUR" },
  };
}

function consultation(missionId: number, date: string, start: string | null, end: string | null, minutes: number | null): HoursItem {
  return {
    missionId, date, start, end, endsNextDay: false, minutes, durationSource: minutes === null ? null : "ACTUAL_TIMES",
    missionType: "CONSULTATION", status: "validated", surgeonName: "Dr Lucie Van Damme", site: STJ,
    estimate: { rateType: "CONSULTATION_FEE", unitAmount: "120.00", amount: "120.00", currency: "EUR" },
  };
}

/** GET /api/manager/instrumentists/41/hours?month=2026-10 — heure de Bruxelles (CEST jusqu'au 25 oct.). */
export const benaliOctober2026: InstrumentistHoursDetail = {
  month: "2026-10",
  provisional: false,
  asOfDay: null,
  siteId: null,
  instrumentist: { id: 41, firstname: "Nadia", lastname: "Benali", active: true },
  sites: [
    { id: 3, name: "CHU Brugmann", count: 5 },
    { id: 5, name: "Hôpital Erasme", count: 4 },
    { id: 7, name: "Clinique Saint-Jean", count: 3 },
  ],
  summary: { count: 12, encodedMinutes: h(70, 30), validatedMinutes: h(68), pendingMinutes: h(2, 30), validatedPct: 96, missingHoursCount: 1 },
  estimate: {
    currency: "EUR",
    encodedAmount: "3419.00",
    validatedAmount: "3304.00",
    hourlyRateMin: "46.00",
    hourlyRateMax: "46.00",
    unratedCount: 0,
    lines: [
      { siteName: "CHU Brugmann", rateType: "HOURLY_RATE", unitAmount: "46.00", minutes: h(34, 15), count: 5, amount: "1575.50" },
      { siteName: "Hôpital Erasme", rateType: "HOURLY_RATE", unitAmount: "46.00", minutes: h(32, 15), count: 4, amount: "1483.50" },
      { siteName: "Clinique Saint-Jean", rateType: "CONSULTATION_FEE", unitAmount: "120.00", minutes: null, count: 3, amount: "360.00" },
    ],
  },
  comparison: october2026List.items[4].comparison,
  items: [
    block(5130, "2026-10-30", "Dr Antoine Mertens", BRU, "2026-10-30T07:30:00+01:00", "2026-10-30T10:00:00+01:00", h(2, 30), "pending", "115.00"),
    consultation(5127, "2026-10-28", "2026-10-28T14:00:00+01:00", "2026-10-28T16:00:00+01:00", h(2)),
    block(5121, "2026-10-27", "Dr Antoine Mertens", BRU, "2026-10-27T08:00:00+01:00", "2026-10-27T16:00:00+01:00", h(8), "validated", "368.00"),
    block(5110, "2026-10-23", "Dr Karim El Idrissi", ERA, "2026-10-23T07:45:00+02:00", "2026-10-23T15:30:00+02:00", h(7, 45), "validated", "356.50"),
    consultation(5104, "2026-10-21", null, null, null),
    block(5098, "2026-10-20", "Dr Antoine Mertens", BRU, null, null, h(8, 15), "validated", "379.50", "PRESENCE_SHARE"),
    block(5087, "2026-10-16", "Dr Hélène Claes", ERA, "2026-10-16T18:00:00+02:00", "2026-10-17T03:00:00+02:00", h(9), "validated", "414.00", "ACTUAL_TIMES", true),
    consultation(5081, "2026-10-14", "2026-10-14T09:00:00+02:00", "2026-10-14T11:00:00+02:00", h(2)),
    block(5076, "2026-10-13", "Dr Antoine Mertens", BRU, "2026-10-13T08:00:00+02:00", "2026-10-13T15:00:00+02:00", h(7), "validated", "322.00"),
    block(5062, "2026-10-09", "Dr Karim El Idrissi", ERA, "2026-10-09T07:30:00+02:00", "2026-10-09T15:00:00+02:00", h(7, 30), "validated", "345.00"),
    block(5051, "2026-10-06", "Dr Antoine Mertens", BRU, "2026-10-06T07:30:00+02:00", "2026-10-06T16:00:00+02:00", h(8, 30), "validated", "391.00"),
    block(5040, "2026-10-02", "Dr Hélène Claes", ERA, "2026-10-02T08:00:00+02:00", "2026-10-02T16:00:00+02:00", h(8), "validated", "368.00"),
  ],
};
