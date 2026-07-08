import { describe, it, expect, beforeEach } from "vitest";
import { markAccountJustActivated, wasAccountJustActivated } from "./justActivatedAccountFlag";

beforeEach(() => {
  sessionStorage.clear();
});

describe("justActivatedAccountFlag", () => {
  it("est faux par défaut", () => {
    expect(wasAccountJustActivated()).toBe(false);
  });

  it("devient vrai après markAccountJustActivated()", () => {
    markAccountJustActivated();
    expect(wasAccountJustActivated()).toBe(true);
  });
});
