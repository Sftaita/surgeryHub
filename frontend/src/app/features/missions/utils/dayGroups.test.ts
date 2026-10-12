import { describe, expect, it } from "vitest";
import { brusselsDayKey, formatDayLabel, groupByDay, relativeDayLabel } from "./dayGroups";

describe("dayGroups (D-147)", () => {
  it("keys days in Europe/Brussels, not UTC", () => {
    // 23:30 UTC on Oct 12 is already Oct 13 in Brussels (UTC+2 in October).
    expect(brusselsDayKey("2026-10-12T23:30:00Z")).toBe("2026-10-13");
    expect(brusselsDayKey("2026-10-12T21:30:00Z")).toBe("2026-10-12");
  });

  it("groups consecutively in server order and keeps item order", () => {
    const items = [
      { id: 3, at: "2026-10-14T08:00:00+02:00" },
      { id: 2, at: "2026-10-13T13:00:00+02:00" },
      { id: 1, at: "2026-10-13T08:00:00+02:00" },
    ];
    const groups = groupByDay(items, (i) => i.at);
    expect(groups.map((g) => g.key)).toEqual(["2026-10-14", "2026-10-13"]);
    expect(groups[1].items.map((i) => i.id)).toEqual([2, 1]);
  });

  it("labels today / tomorrow / yesterday relative to Brussels", () => {
    const now = new Date("2026-10-12T10:00:00+02:00");
    expect(relativeDayLabel("2026-10-12", now)).toBe("Aujourd'hui");
    expect(relativeDayLabel("2026-10-13", now)).toBe("Demain");
    expect(relativeDayLabel("2026-10-11", now)).toBe("Hier");
    expect(relativeDayLabel("2026-10-20", now)).toBeNull();
  });

  it("formats a capitalised French day label", () => {
    expect(formatDayLabel("2026-10-13")).toMatch(/^Mardi 13 octobre 2026$/);
  });
});
