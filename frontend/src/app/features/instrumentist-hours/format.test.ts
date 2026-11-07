import { describe, expect, it } from "vitest";
import { listFixture } from "./testFixtures";
import { approx, averagePeriodNote, encodedCost, validatedCost, averagePeriodRange, averageReason, deltaAria, pctLabel, previousMonthHeading, previousMonthReason } from "./format";

const base = { validatedMinutes: 0, monthsCounted: 6, from: "2026-04", to: "2026-09", observableSince: "2025-03" };

describe("D-180 — formats du suivi manager", () => {
  it("affiche les pourcentages du serveur sans les recalculer, « — » si indisponible", () => {
    expect(pctLabel(8)).toBe("+8 %");
    expect(pctLabel(-13)).toBe("−13 %");
    expect(pctLabel(0)).toBe("0 %");
    expect(pctLabel(null)).toBe("—");
  });

  it("porte le signe ≈ sur toute estimation", () => {
    expect(approx("20975.00")).toBe("≈ 20 975,00 €");
  });

  it("libelle la référence du mois précédent, à date équivalente pour le mois en cours", () => {
    expect(previousMonthHeading("2026-09", null)).toBe("vs sept.");
    expect(previousMonthHeading("2026-10", 3)).toBe("vs 1–3 oct.");
  });

  it("explique une comparaison indisponible sans jamais parler d'entrée en activité", () => {
    expect(previousMonthReason({ validatedMinutes: 0, pct: null, available: false, reason: "NO_REFERENCE" }, "2026-09")).toBe("Pas d'historique en septembre");
    expect(previousMonthReason({ validatedMinutes: 0, pct: null, available: false, reason: "ZERO_REFERENCE" }, "2026-09")).toBe("Aucune heure validée en septembre");
    const none = { ...base, pct: null, available: false, reason: "NO_REFERENCE" as const, monthsCounted: 0, from: null, to: null, observableSince: "2026-10" };
    expect(averageReason(none)).toBe("Pas d'historique avant octobre 2026");
    expect(averageReason({ ...none, observableSince: null })).toBe("Aucune prestation antérieure connue");
    expect(averageReason({ ...base, pct: null, available: false, reason: "ZERO_REFERENCE" })).toBe("Aucune heure validée sur la période de référence");
    for (const text of [averageReason(none), averageReason({ ...none, observableSince: null })]) {
      expect(text).not.toMatch(/entrée|embauche|activation|ancienneté/i);
    }
  });

  it("signale une période raccourcie par l'historique observable", () => {
    const short = { ...base, pct: -42, available: true, reason: null, monthsCounted: 4, from: "2026-06" };
    expect(averagePeriodNote(short)).toBe("sur 4 mois");
    expect(averagePeriodRange(short)).toBe("juin–sept.");
    expect(averagePeriodNote({ ...base, pct: 3, available: true, reason: null })).toBeNull();
  });

  it("produit un libellé accessible complet", () => {
    expect(deltaAria(-9, "septembre", null)).toBe("En baisse de 9 % par rapport à septembre");
    expect(deltaAria(null, "septembre", "Pas d'historique en septembre")).toBe("Comparaison septembre indisponible : Pas d'historique en septembre");
  });
});

describe("D-180 — coût estimé validé d'une ligne", () => {
  const base = listFixture().items[0];
  const eur = (encodedAmount: string, validatedAmount: string | null, unratedCount = 0) =>
    ({ currency: "EUR", encodedAmount, validatedAmount, consultationFeeCount: 0, unratedCount });

  it("affiche ≈ 0,00 € quand un tarif s'applique mais que rien n'est validé", () => {
    const row = { ...base, estimateState: "RATED" as const, validatedMinutes: 0, estimate: eur("120.00", "0.00") };
    expect(validatedCost(row)).toEqual({ text: "≈ 0,00 €", missing: false });
    expect(encodedCost(row)).toEqual({ text: "≈ 120,00 €", missing: false });
  });

  it("garde « — » sans estimation exploitable et « Tarif manquant » sans tarif", () => {
    expect(validatedCost({ ...base, estimateState: "RATED", estimate: eur("60.00", null, 1) })).toEqual({ text: "—", missing: false });
    expect(validatedCost({ ...base, estimateState: "NO_PRESTATION", estimate: null })).toEqual({ text: "—", missing: false });
    expect(validatedCost({ ...base, estimateState: "UNRATED", estimate: null })).toEqual({ text: "Tarif manquant", missing: true });
  });
});
