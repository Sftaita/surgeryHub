import { describe, it, expect } from "vitest";
import { presetToRecurrence, recurrenceToPreset } from "./recurrencePresets";
import type { RecurrenceRuleV2 } from "./planningV2.types";

describe("presetToRecurrence() — MONTHLY_NTH_WEEKDAY mapping", () => {
  it("sends weekdays + monthWeeks for a monthly recurrence", () => {
    const payload = presetToRecurrence("MONTHLY_NTH_WEEKDAY", [4], "2026-01-05", [2, 3]);

    expect(payload).toEqual({
      frequency: "MONTHLY",
      interval: 1,
      weekdays: [4],
      anchorDate: "2026-01-05",
      monthWeeks: [2, 3],
    });
    expect(payload).not.toHaveProperty("monthlyNthWeekday");
  });

  it("falls back to the odd-week anchor when no startDate is set", () => {
    const payload = presetToRecurrence("MONTHLY_NTH_WEEKDAY", [1], "", [1]);
    expect(payload.anchorDate).toBe("2024-01-01");
  });

  it("sends an empty monthWeeks for WEEKLY presets", () => {
    const payload = presetToRecurrence("WEEKLY", [1, 3], "2026-01-05");
    expect(payload.monthWeeks).toEqual([]);
    expect(payload).not.toHaveProperty("monthlyNthWeekday");
  });
});

describe("recurrenceToPreset() — round-tripping existing posts", () => {
  it("maps a MONTHLY rule with a single monthWeeks value back to MONTHLY_NTH_WEEKDAY", () => {
    const rule: RecurrenceRuleV2 = { frequency: "MONTHLY", interval: 1, weekdays: [1], anchorDate: "2026-01-05", monthWeeks: [1] };
    expect(recurrenceToPreset(rule)).toBe("MONTHLY_NTH_WEEKDAY");
  });

  it("maps a MONTHLY rule with several monthWeeks values back to MONTHLY_NTH_WEEKDAY without breaking", () => {
    const rule: RecurrenceRuleV2 = { frequency: "MONTHLY", interval: 1, weekdays: [4], anchorDate: "2026-01-05", monthWeeks: [2, 3] };
    expect(recurrenceToPreset(rule)).toBe("MONTHLY_NTH_WEEKDAY");
  });
});
