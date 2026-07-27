import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { renderHook, act } from "@testing-library/react";
import { useDebouncedValue } from "./useDebouncedValue";

describe("useDebouncedValue", () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it("returns the initial value immediately", () => {
    const { result } = renderHook(() => useDebouncedValue("a", 300));
    expect(result.current).toBe("a");
  });

  it("only updates after the delay has elapsed, keeping the latest value", () => {
    const { result, rerender } = renderHook(({ value }) => useDebouncedValue(value, 300), {
      initialProps: { value: "a" },
    });

    rerender({ value: "ab" });
    act(() => vi.advanceTimersByTime(100));
    expect(result.current).toBe("a"); // not yet

    rerender({ value: "abc" });
    act(() => vi.advanceTimersByTime(299));
    expect(result.current).toBe("a"); // still debouncing on the latest change

    act(() => vi.advanceTimersByTime(1));
    expect(result.current).toBe("abc");
  });

  it("defaults the delay to 300ms", () => {
    const { result, rerender } = renderHook(({ value }) => useDebouncedValue(value), {
      initialProps: { value: "x" },
    });
    rerender({ value: "y" });
    act(() => vi.advanceTimersByTime(299));
    expect(result.current).toBe("x");
    act(() => vi.advanceTimersByTime(1));
    expect(result.current).toBe("y");
  });

  it("clears the pending timeout on unmount", () => {
    const clearTimeoutSpy = vi.spyOn(globalThis, "clearTimeout");
    const { rerender, unmount } = renderHook(({ value }) => useDebouncedValue(value, 300), {
      initialProps: { value: "a" },
    });

    rerender({ value: "b" });
    unmount();

    expect(clearTimeoutSpy).toHaveBeenCalled();
    // No pending state update fires after unmount — advancing timers must not throw
    // (React would warn/throw on a state update to an unmounted component otherwise).
    expect(() => act(() => vi.advanceTimersByTime(300))).not.toThrow();
  });
});
