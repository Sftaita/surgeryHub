/**
 * D-156 lot 1 — manager views of the operational planning (Missions tab, published planning,
 * fiches) have no push channel: without this, a change made by another manager, by an
 * instrumentist (claim, declaration) or in another browser tab stayed invisible on an
 * already-open page (global `refetchOnWindowFocus: false`, no polling). Refetch on focus
 * plus a light polling while the tab is visible — no WebSocket, same API.
 */
export const OPERATIONAL_LIVE_QUERY_OPTIONS = {
  refetchOnWindowFocus: true,
  refetchInterval: 60_000,
  refetchIntervalInBackground: false,
} as const;

/**
 * Same, without polling — for editors holding unsaved local changes over server data
 * (Planning V2 modification mode): refresh when the manager comes back, never under their feet.
 */
export const OPERATIONAL_FOCUS_QUERY_OPTIONS = {
  refetchOnWindowFocus: true,
} as const;
