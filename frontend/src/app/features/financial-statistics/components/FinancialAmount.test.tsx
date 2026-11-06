import { describe, it, expect } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { ExportPdfButton, formatSignedMoney } from "./FinancialAmount";

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

describe("ExportPdfButton (D-186)", () => {
  it("affiche le refus explicite du serveur au lieu d'un message générique", async () => {
    const message = "Export trop volumineux : 1200 lignes pour un maximum de 1000 par PDF. Affinez la période ou les filtres.";
    render(<ExportPdfButton onExport={() => Promise.reject(new Error(message))} />);

    fireEvent.click(screen.getByRole("button", { name: "Exporter en PDF" }));

    expect(await screen.findByText(message)).toBeInTheDocument();
  });
});
