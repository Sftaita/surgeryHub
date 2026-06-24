import { describe, it, expect, vi } from "vitest";

vi.mock("../../../api/apiClient", () => ({
  apiClient: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

import { userName, SPECIALTIES, DAY_LABELS, createIsolatedDayAbsences } from "./planning.api";
import type { UserRef, Absence } from "./planning.api";
import { apiClient } from "../../../api/apiClient";

describe("userName()", () => {
  it("returns trimmed first+last name when both are set", () => {
    const u: UserRef = { id: 1, email: "x@test.com", firstname: "Jean", lastname: "Martin" };
    expect(userName(u)).toBe("Jean Martin");
  });

  it("falls back to email when firstname and lastname are both absent", () => {
    const u: UserRef = { id: 1, email: "ole@test.com" };
    expect(userName(u)).toBe("ole@test.com");
  });

  it("falls back to email when name is blank after trim", () => {
    const u: UserRef = { id: 1, email: "blank@test.com", firstname: "  ", lastname: "" };
    expect(userName(u)).toBe("blank@test.com");
  });

  it("uses only firstname when lastname is absent", () => {
    const u: UserRef = { id: 1, email: "x@test.com", firstname: "Ole", lastname: null };
    expect(userName(u)).toBe("Ole");
  });
});

describe("SPECIALTIES constant", () => {
  it("contains exactly 12 entries", () => {
    expect(SPECIALTIES).toHaveLength(12);
  });

  it("every entry has a non-empty value and label", () => {
    for (const s of SPECIALTIES) {
      expect(s.value).toBeTruthy();
      expect(s.label).toBeTruthy();
    }
  });

  it("includes GENOU and EPAULE", () => {
    const values = SPECIALTIES.map((s) => s.value);
    expect(values).toContain("GENOU");
    expect(values).toContain("EPAULE");
  });
});

describe("DAY_LABELS", () => {
  it("has 8 entries (index 0 unused, 1-7 = lundi-dimanche)", () => {
    expect(DAY_LABELS).toHaveLength(8);
  });

  it("maps ISO day numbers correctly", () => {
    expect(DAY_LABELS[1]).toBe("Lundi");
    expect(DAY_LABELS[7]).toBe("Dimanche");
  });
});

describe("createIsolatedDayAbsences() — Cas 3 (jours isolés)", () => {
  function makeAbsence(date: string): Absence {
    return {
      id: Math.random(),
      user: { id: 1, email: "x@test.com" },
      dateStart: date, dateEnd: date, reason: null, createdAt: "2026-06-24T00:00:00Z",
    };
  }

  it("creates one Absence per date with dateStart === dateEnd, sequentially", async () => {
    const posted = vi.mocked(apiClient.post);
    posted.mockImplementation(async (_url, body: any) => ({ data: makeAbsence(body.dateStart) }));

    const result = await createIsolatedDayAbsences({ userId: 7, dates: ["2026-07-04", "2026-07-09", "2026-07-18"] });

    expect(posted).toHaveBeenCalledTimes(3);
    expect(posted.mock.calls.map((c) => c[1])).toEqual([
      { userId: 7, dateStart: "2026-07-04", dateEnd: "2026-07-04", reason: undefined },
      { userId: 7, dateStart: "2026-07-09", dateEnd: "2026-07-09", reason: undefined },
      { userId: 7, dateStart: "2026-07-18", dateEnd: "2026-07-18", reason: undefined },
    ]);
    expect(result.map((a) => a.dateStart)).toEqual(["2026-07-04", "2026-07-09", "2026-07-18"]);
  });

  it("returns an empty array for no dates without calling the API", async () => {
    const posted = vi.mocked(apiClient.post);
    posted.mockClear();

    const result = await createIsolatedDayAbsences({ userId: 7, dates: [] });

    expect(result).toEqual([]);
    expect(posted).not.toHaveBeenCalled();
  });
});
