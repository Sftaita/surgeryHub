import { describe, it, expect } from "vitest";
import { formatSignedMoney } from "./FinancialAmount";

const n = (s: string) => s.replace(/[  ]/g, " ");

describe("formatSignedMoney (D-186)", () => {
  it("affiche une recette en positif", () => {
    expect(n(formatSignedMoney("666.00", "revenue", "EUR"))).toBe("+666,00 €");
  });

  it("affiche une dépense (magnitude positive de l'API) en négatif, une seule fois", () => {
    expect(n(formatSignedMoney("666.25", "expense", "EUR"))).toBe("−666,25 €");
  });

  it("affiche un solde avec le signe porté par la donnée", () => {
    expect(n(formatSignedMoney("-0.25", "net", "EUR"))).toBe("−0,25 €");
    expect(n(formatSignedMoney("67.50", "net", "EUR"))).toBe("+67,50 €");
    expect(n(formatSignedMoney("0.00", "net", "EUR"))).toBe("0,00 €");
  });

  it("n'ajoute aucun signe à zéro et garde la devise non-EUR", () => {
    expect(n(formatSignedMoney("0", "revenue", "USD"))).toBe("0,00 USD");
    expect(n(formatSignedMoney("1234.5", "revenue", "USD"))).toBe("+1 234,50 USD");
  });
});
