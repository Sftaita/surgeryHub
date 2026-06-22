import { describe, it, expect } from "vitest";
import { summarizeRecurrence } from "./planningV2.types";
import type { RecurrenceRuleV2 } from "./planningV2.types";
import { extractErrorV2 } from "./planningV2.api";

describe("summarizeRecurrence()", () => {
  it("describes weekly recurrence on specific weekdays", () => {
    const rule: RecurrenceRuleV2 = { frequency: "WEEKLY", interval: 1, weekdays: [1, 3], anchorDate: "2026-01-05", monthlyNthWeekday: null };
    expect(summarizeRecurrence(rule)).toBe("Toutes les semaines · lun, mer");
  });

  it("describes one-week-on-two recurrence", () => {
    const rule: RecurrenceRuleV2 = { frequency: "WEEKLY", interval: 2, weekdays: [2], anchorDate: "2026-01-05", monthlyNthWeekday: null };
    expect(summarizeRecurrence(rule)).toBe("Une semaine sur 2 · mar");
  });

  it("omits the weekday list when none are set", () => {
    const rule: RecurrenceRuleV2 = { frequency: "WEEKLY", interval: 1, weekdays: [], anchorDate: "2026-01-05", monthlyNthWeekday: null };
    expect(summarizeRecurrence(rule)).toBe("Toutes les semaines");
  });

  it("describes monthly recurrence", () => {
    const rule: RecurrenceRuleV2 = { frequency: "MONTHLY", interval: 1, weekdays: [], anchorDate: "2026-01-05", monthlyNthWeekday: null };
    expect(summarizeRecurrence(rule)).toBe("Tous les mois");
  });

  it("describes a multi-month interval", () => {
    const rule: RecurrenceRuleV2 = { frequency: "MONTHLY", interval: 3, weekdays: [], anchorDate: "2026-01-05", monthlyNthWeekday: null };
    expect(summarizeRecurrence(rule)).toBe("Tous les 3 mois");
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
