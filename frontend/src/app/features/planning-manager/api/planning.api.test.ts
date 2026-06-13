import { describe, it, expect } from "vitest";
import { userName, SPECIALTIES, DAY_LABELS } from "./planning.api";
import type { UserRef } from "./planning.api";

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
