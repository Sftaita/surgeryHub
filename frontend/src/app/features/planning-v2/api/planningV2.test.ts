import { describe, it, expect } from "vitest";
import { summarizeRecurrence } from "./planningV2.types";
import type { RecurrenceRuleV2 } from "./planningV2.types";
import { extractErrorV2 } from "./planningV2.api";

describe("summarizeRecurrence()", () => {
  it("describes weekly recurrence on specific weekdays", () => {
    const rule: RecurrenceRuleV2 = { frequency: "WEEKLY", interval: 1, weekdays: [1, 3], anchorDate: "2026-01-05", monthWeeks: [] };
    expect(summarizeRecurrence(rule)).toBe("Toutes les semaines · lun, mer");
  });

  it("describes one-week-on-two recurrence", () => {
    const rule: RecurrenceRuleV2 = { frequency: "WEEKLY", interval: 2, weekdays: [2], anchorDate: "2026-01-05", monthWeeks: [] };
    expect(summarizeRecurrence(rule)).toBe("Une semaine sur 2 · mar");
  });

  it("omits the weekday list when none are set", () => {
    const rule: RecurrenceRuleV2 = { frequency: "WEEKLY", interval: 1, weekdays: [], anchorDate: "2026-01-05", monthWeeks: [] };
    expect(summarizeRecurrence(rule)).toBe("Toutes les semaines");
  });

  it("describes monthly recurrence with no weekdays/monthWeeks set (legacy/incomplete rule)", () => {
    const rule: RecurrenceRuleV2 = { frequency: "MONTHLY", interval: 1, weekdays: [], anchorDate: "2026-01-05", monthWeeks: [] };
    expect(summarizeRecurrence(rule)).toBe("Tous les mois");
  });

  it("describes a multi-month interval", () => {
    const rule: RecurrenceRuleV2 = { frequency: "MONTHLY", interval: 3, weekdays: [], anchorDate: "2026-01-05", monthWeeks: [] };
    expect(summarizeRecurrence(rule)).toBe("Tous les 3 mois");
  });

  it("describes 1st Monday of the month", () => {
    const rule: RecurrenceRuleV2 = { frequency: "MONTHLY", interval: 1, weekdays: [1], anchorDate: "2026-01-05", monthWeeks: [1] };
    expect(summarizeRecurrence(rule)).toBe("Tous les 1ers lundis du mois");
  });

  it("describes 2nd Tuesday of the month", () => {
    const rule: RecurrenceRuleV2 = { frequency: "MONTHLY", interval: 1, weekdays: [2], anchorDate: "2026-01-05", monthWeeks: [2] };
    expect(summarizeRecurrence(rule)).toBe("Tous les 2es mardis du mois");
  });

  it("describes 2nd and 3rd Thursday of the month", () => {
    const rule: RecurrenceRuleV2 = { frequency: "MONTHLY", interval: 1, weekdays: [4], anchorDate: "2026-01-05", monthWeeks: [2, 3] };
    expect(summarizeRecurrence(rule)).toBe("Tous les 2es et 3es jeudis du mois");
  });

  it("describes 1st and 4th Friday of the month", () => {
    const rule: RecurrenceRuleV2 = { frequency: "MONTHLY", interval: 1, weekdays: [5], anchorDate: "2026-01-05", monthWeeks: [1, 4] };
    expect(summarizeRecurrence(rule)).toBe("Tous les 1ers et 4es vendredis du mois");
  });

  it("describes 1st, 2nd and 3rd Monday and Thursday of the month", () => {
    const rule: RecurrenceRuleV2 = { frequency: "MONTHLY", interval: 1, weekdays: [4, 1], anchorDate: "2026-01-05", monthWeeks: [3, 1, 2] };
    expect(summarizeRecurrence(rule)).toBe("Tous les 1ers, 2es et 3es lundis et jeudis du mois");
  });

  it("describes 5th Monday of the month", () => {
    const rule: RecurrenceRuleV2 = { frequency: "MONTHLY", interval: 1, weekdays: [1], anchorDate: "2026-01-05", monthWeeks: [5] };
    expect(summarizeRecurrence(rule)).toBe("Tous les 5es lundis du mois");
  });
});

describe("extractErrorV2()", () => {
  it("prefers the API error message when present", () => {
    const err = { response: { data: { error: { message: "Site introuvable" } } } };
    expect(extractErrorV2(err)).toBe("Site introuvable");
  });

  it("falls back to the error's own message", () => {
    const err = new Error("Network failure");
    expect(extractErrorV2(err)).toBe("Network failure");
  });

  it("falls back to String(err) when nothing else is available", () => {
    expect(extractErrorV2("boom")).toBe("boom");
  });
});
