import { describe, it, expect } from "vitest";
import { formatPeriodLabel, periodForShortcut, periodLengthDays, shiftPeriod } from "./period";

const REF = new Date(2026, 8, 15); // 15 septembre 2026 (mois 0-indexé)

describe("periodForShortcut", () => {
  it("Aujourd'hui : un jour, from inclusif to exclusif", () => {
    const p = periodForShortcut("today", REF);
    expect(p).toEqual({ from: "2026-09-15", to: "2026-09-16" });
  });

  it("Hier : le jour civil précédent uniquement", () => {
    const p = periodForShortcut("yesterday", REF);
    expect(p).toEqual({ from: "2026-09-14", to: "2026-09-15" });
  });

  it("7 jours : les 7 derniers jours civils, aujourd'hui inclus", () => {
    const p = periodForShortcut("last7Days", REF);
    expect(p).toEqual({ from: "2026-09-09", to: "2026-09-16" });
    expect(periodLengthDays(p)).toBe(7);
  });

  it("Ce mois : du 1er du mois au 1er du mois suivant", () => {
    const p = periodForShortcut("thisMonth", REF);
    expect(p).toEqual({ from: "2026-09-01", to: "2026-10-01" });
  });

  it("Ce mois à cheval sur une année (décembre)", () => {
    const p = periodForShortcut("thisMonth", new Date(2026, 11, 20));
    expect(p).toEqual({ from: "2026-12-01", to: "2027-01-01" });
  });
});

describe("shiftPeriod", () => {
  it("décale une période d'un jour de sa propre longueur, en avant", () => {
    const today = periodForShortcut("today", REF);
    expect(shiftPeriod(today, 1)).toEqual({ from: "2026-09-16", to: "2026-09-17" });
  });

  it("décale une période d'un jour de sa propre longueur, en arrière", () => {
    const today = periodForShortcut("today", REF);
    expect(shiftPeriod(today, -1)).toEqual({ from: "2026-09-14", to: "2026-09-15" });
  });

  it("décale une période de 7 jours par blocs de 7 jours entiers", () => {
    const week = periodForShortcut("last7Days", REF);
    const next = shiftPeriod(week, 1);
    expect(periodLengthDays(next)).toBe(7);
    expect(next.from).toBe("2026-09-16");
  });
});

describe("formatPeriodLabel", () => {
  it("un jour unique est affiché sans borne de fin", () => {
    expect(formatPeriodLabel(periodForShortcut("today", REF))).toBe("15 sept.");
  });

  it("une plage affiche ses deux bornes réelles (dernier jour inclus, pas la borne exclusive)", () => {
    const week = periodForShortcut("last7Days", REF);
    expect(formatPeriodLabel(week)).toBe("9 sept. → 15 sept.");
  });
});
