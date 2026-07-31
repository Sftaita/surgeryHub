import { describe, it, expect } from "vitest";
import { dvh } from "./dvh";

describe("dvh — fallback 100vh → 100dvh (Lot 4, audit PWA/mobile/admin 2026-07-29)", () => {
  it("garde la valeur vh en fallback direct", () => {
    expect(dvh("minHeight", "100vh").minHeight).toBe("100vh");
  });

  it("expose la variante dvh sous @supports (height: 100dvh)", () => {
    const result = dvh("minHeight", "100vh");
    expect(result["@supports (height: 100dvh)"]).toEqual({ minHeight: "100dvh" });
  });

  it("convertit correctement une expression calc()", () => {
    const result = dvh("maxHeight", "calc(100vh - 24px)");
    expect(result.maxHeight).toBe("calc(100vh - 24px)");
    expect(result["@supports (height: 100dvh)"]).toEqual({ maxHeight: "calc(100dvh - 24px)" });
  });

  it("fonctionne pour height comme pour minHeight/maxHeight", () => {
    expect(dvh("height", "100vh")["@supports (height: 100dvh)"]).toEqual({ height: "100dvh" });
  });
});
