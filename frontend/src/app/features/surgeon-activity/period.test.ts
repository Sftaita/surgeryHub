import { describe, it, expect } from "vitest";
import { getActivityRange, shiftActivityPeriod, formatActivityPeriodLabel, formatDateToYmd, todayYmd } from "./period";

describe("getActivityRange", () => {
  it("mode année → [1er janvier, 1er janvier année suivante) demi-ouvert", () => {
    expect(getActivityRange("year", "2026-08-06")).toEqual({ from: "2026-01-01", to: "2027-01-01" });
  });

  it("mode mois → [1er du mois, 1er du mois suivant) demi-ouvert", () => {
    expect(getActivityRange("month", "2026-08-06")).toEqual({ from: "2026-08-01", to: "2026-09-01" });
  });

  it("mode mois en décembre → bascule correctement sur janvier de l'année suivante", () => {
    expect(getActivityRange("month", "2026-12-15")).toEqual({ from: "2026-12-01", to: "2027-01-01" });
  });
});

describe("shiftActivityPeriod", () => {
  it("mode année, +1 → année suivante", () => {
    expect(shiftActivityPeriod("2026-08-06", "year", 1)).toBe("2027-01-01");
  });

  it("mode année, -1 → année précédente", () => {
    expect(shiftActivityPeriod("2026-08-06", "year", -1)).toBe("2025-01-01");
  });

  it("mode mois, +1 → mois suivant", () => {
    expect(shiftActivityPeriod("2026-08-06", "month", 1)).toBe("2026-09-01");
  });

  it("mode mois, -1 en janvier → décembre de l'année précédente", () => {
    expect(shiftActivityPeriod("2026-01-15", "month", -1)).toBe("2025-12-01");
  });
});

describe("formatActivityPeriodLabel", () => {
  it("mode année → juste l'année", () => {
    expect(formatActivityPeriodLabel("2026-08-06", "year")).toBe("2026");
  });

  it("mode mois → \"Mois Année\" capitalisé", () => {
    expect(formatActivityPeriodLabel("2026-08-06", "month")).toBe("Août 2026");
  });
});

describe("todayYmd/formatDateToYmd", () => {
  it("formatDateToYmd formate en YYYY-MM-DD avec zero-padding", () => {
    expect(formatDateToYmd(new Date(2026, 0, 5))).toBe("2026-01-05");
  });

  it("todayYmd retourne une chaîne YYYY-MM-DD valide", () => {
    expect(todayYmd()).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  });
});
