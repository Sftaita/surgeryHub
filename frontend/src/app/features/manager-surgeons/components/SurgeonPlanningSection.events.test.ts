import { describe, it, expect } from "vitest";
import { toSurgeonCalendarEvents } from "./SurgeonPlanningSection";
import type { SurgeonPlanningEvent } from "../api/surgeons.types";

function event(overrides: Partial<SurgeonPlanningEvent> = {}): SurgeonPlanningEvent {
  return {
    id: 1090,
    title: "Marie Dupont",
    start: "2026-10-27T07:45:00+01:00",
    end: "2026-10-27T18:00:00+01:00",
    allDay: false,
    status: "ASSIGNED",
    ...overrides,
  };
}

describe("toSurgeonCalendarEvents — D-158", () => {
  it("une mission annulée (absence du chirurgien) reste visible mais n'apparaît jamais comme un créneau occupé", () => {
    const [cancelled] = toSurgeonCalendarEvents([event({ status: "CANCELLED", title: "Mission #1090" })]);

    expect(cancelled.title).toBe("Annulée — Mission #1090");
    expect(cancelled.classNames).toEqual(["surgeon-planning-event--cancelled"]);
    expect(cancelled.backgroundColor).toBeDefined();
  });

  it("une mission active garde son rendu habituel", () => {
    const [active] = toSurgeonCalendarEvents([event()]);

    expect(active).toEqual({
      id: "1090",
      title: "Marie Dupont",
      start: "2026-10-27T07:45:00+01:00",
      end: "2026-10-27T18:00:00+01:00",
      allDay: false,
    });
  });
});
