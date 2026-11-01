import { describe, it, expect } from "vitest";
import { clock, currentMonth, dateLong, duration, euro, hourlyRate, monthLabel, rateRange, shiftMonth } from "./format";

describe("format (D-179, présentation seule)", () => {
  it("durées en 82h30", () => {
    expect(duration(4950)).toBe("82h30");
    expect(duration(0)).toBe("0h00");
    expect(duration(300)).toBe("5h00");
  });

  it("heure murale lue dans la chaîne serveur, indépendante du fuseau du navigateur", () => {
    expect(clock("2026-10-30T22:00:00+01:00")).toBe("22h00");
    expect(clock("2026-07-15T07:05:00+02:00")).toBe("07h05");
  });

  it("montants en 3 482,75 €", () => {
    expect(euro("3482.75")).toBe("3 482,75 €");
    expect(euro("120")).toBe("120,00 €");
    expect(hourlyRate("42.00")).toBe("42,00 €/h");
    expect(rateRange("40.00", "45.00")).toBe("40 à 45 €/h");
    expect(rateRange("42.00", "42.00")).toBe("42 €/h");
  });

  it("mois et dates", () => {
    expect(monthLabel("2026-10")).toBe("Octobre 2026");
    expect(dateLong("2026-10-29")).toBe("Jeu. 29 oct.");
    expect(shiftMonth("2026-01", -1)).toBe("2025-12");
    expect(shiftMonth("2025-12", 1)).toBe("2026-01");
    expect(currentMonth(new Date(2026, 9, 31))).toBe("2026-10");
  });
});
