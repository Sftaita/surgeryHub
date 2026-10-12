import { describe, expect, it } from "vitest";
import { isMissionsPeriod, periodParams } from "./missionsListPeriod";

describe("missionsListPeriod (D-147)", () => {
  const ref = new Date(2026, 9, 12, 15, 30); // 12 Oct 2026, 15:30 local

  it("upcoming starts at local midnight today, soonest first", () => {
    const p = periodParams("upcoming", ref);
    expect(new Date(p.from!).getTime()).toBe(new Date(2026, 9, 12).getTime());
    expect(p.to).toBeUndefined();
    expect(p.order).toBe("asc");
  });

  it("today and week are half-open local-day ranges", () => {
    const today = periodParams("today", ref);
    expect(new Date(today.to!).getTime()).toBe(new Date(2026, 9, 13).getTime());
    const week = periodParams("week", ref);
    expect(new Date(week.to!).getTime()).toBe(new Date(2026, 9, 19).getTime());
  });

  it("past ends at local midnight today, most recent first; all has no range", () => {
    const past = periodParams("past", ref);
    expect(past.from).toBeUndefined();
    expect(new Date(past.to!).getTime()).toBe(new Date(2026, 9, 12).getTime());
    expect(past.order).toBe("desc");
    expect(periodParams("all", ref)).toEqual({ order: "desc" });
  });

  it("validates URL values", () => {
    expect(isMissionsPeriod("week")).toBe(true);
    expect(isMissionsPeriod("yesterday")).toBe(false);
    expect(isMissionsPeriod(null)).toBe(false);
  });
});
