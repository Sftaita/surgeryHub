import { describe, it, expect } from "vitest";
import { isSafeInternalPath } from "./safeInternalPath";

describe("isSafeInternalPath()", () => {
  it("accepts a plain internal path", () => {
    expect(isSafeInternalPath("/app/m/dashboard")).toBe(true);
  });

  it("accepts an internal path with a query string (deep-link Demandes Catalogue)", () => {
    expect(isSafeInternalPath("/app/m/catalogue/requests?kind=MATERIAL_ITEM&requestId=42")).toBe(true);
  });

  it("rejects a protocol-relative URL (//evil.com)", () => {
    expect(isSafeInternalPath("//evil.com")).toBe(false);
  });

  it("rejects a backslash-prefixed path some browsers treat as protocol-relative (/\\evil.com)", () => {
    expect(isSafeInternalPath("/\\evil.com")).toBe(false);
  });

  it("rejects an absolute external URL", () => {
    expect(isSafeInternalPath("http://evil.com")).toBe(false);
    expect(isSafeInternalPath("https://evil.com/app/m/dashboard")).toBe(false);
  });

  it("rejects a javascript: URI smuggled behind a leading slash", () => {
    expect(isSafeInternalPath("/javascript:alert(1)")).toBe(false);
  });

  it("rejects a path not starting with a slash", () => {
    expect(isSafeInternalPath("app/m/dashboard")).toBe(false);
  });

  it("rejects undefined, null, empty string and non-string values", () => {
    expect(isSafeInternalPath(undefined)).toBe(false);
    expect(isSafeInternalPath(null)).toBe(false);
    expect(isSafeInternalPath("")).toBe(false);
    expect(isSafeInternalPath(42)).toBe(false);
    expect(isSafeInternalPath({})).toBe(false);
  });
});
