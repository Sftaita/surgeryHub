import { describe, it, expect, vi } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import * as React from "react";
import { useNavBadgeCount } from "./useNavBadgeCount";

function wrapper({ children }: { children: React.ReactNode }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return React.createElement(QueryClientProvider, { client }, children);
}

describe("useNavBadgeCount", () => {
  it("returns 0 while loading, then the resolved count", async () => {
    const queryFn = vi.fn().mockResolvedValue(3);
    const { result } = renderHook(() => useNavBadgeCount(["test-badge"], queryFn), { wrapper });

    expect(result.current).toBe(0);
    await waitFor(() => expect(result.current).toBe(3));
  });

  it("returns 0 if the query fails rather than throwing", async () => {
    const queryFn = vi.fn().mockRejectedValue(new Error("boom"));
    const { result } = renderHook(() => useNavBadgeCount(["test-badge-error"], queryFn), { wrapper });

    await waitFor(() => expect(queryFn).toHaveBeenCalled());
    expect(result.current).toBe(0);
  });

  it("uses the given queryKey as-is (stable — no key reconstructed internally)", async () => {
    const queryFn = vi.fn().mockResolvedValue(5);
    const key = ["test-badge-stable", "PENDING"];
    const { result, rerender } = renderHook(() => useNavBadgeCount(key, queryFn), { wrapper });

    await waitFor(() => expect(result.current).toBe(5));
    const callsAfterFirstResolve = queryFn.mock.calls.length;

    // Un re-render avec la même référence de clé ne doit pas redéclencher la query
    // (react-query ne re-fetch pas une clé identique déjà fraîche).
    rerender();
    expect(queryFn.mock.calls.length).toBe(callsAfterFirstResolve);
  });

  it("passes a custom refetchInterval through to react-query (déclenche un second appel après l'intervalle)", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const queryFn = vi.fn().mockResolvedValue(1);
    renderHook(() => useNavBadgeCount(["test-badge-interval"], queryFn, 5_000), { wrapper });

    await vi.waitFor(() => expect(queryFn).toHaveBeenCalledTimes(1));

    await vi.advanceTimersByTimeAsync(5_000);
    await vi.waitFor(() => expect(queryFn).toHaveBeenCalledTimes(2));

    vi.useRealTimers();
  });

  /**
   * Correctif workflow Demandes Catalogue (D-113) — `select` permet au badge de lire le
   * compte depuis la MÊME forme de donnée ({items,total}) que la liste qui partage sa
   * clé, au lieu de forcer une queryFn dédiée renvoyant un `number` sous une clé déjà
   * utilisée ailleurs avec une forme différente (la collision corrigée par ce lot).
   */
  it("réduit la donnée via select quand la queryFn ne renvoie pas déjà un number", async () => {
    const queryFn = vi.fn().mockResolvedValue({ items: [{ id: 1 }], total: 4 });
    const { result } = renderHook(
      () => useNavBadgeCount(["test-badge-select"], queryFn, 60_000, (data: { total: number }) => data.total),
      { wrapper },
    );

    await waitFor(() => expect(result.current).toBe(4));
  });
});
