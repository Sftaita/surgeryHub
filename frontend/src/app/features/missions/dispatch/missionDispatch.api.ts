import type { QueryClient } from "@tanstack/react-query";
import { apiClient } from "../../../api/apiClient";
import type { CandidateEligibility } from "../../planning-v2/api/planningV2.types";

/**
 * D-125 — the three ways a manager puts a Mission into play, whatever its origin (manual
 * creation, accepted surgeon request, addition after generation). Mirrors backend
 * App\Enum\MissionDispatchMode; the backend (MissionDispatchService) decides everything
 * else — status, eligibility, notifications. Never inferred client-side.
 *
 *   POOL     → OPEN, every eligible instrumentist may take it
 *   TARGETED → OPEN + request to one instrumentist, NOT covered until she accepts
 *   DIRECT   → ASSIGNED immediately (agreement obtained outside SurgicalHub)
 */
export type DispatchMode = "POOL" | "TARGETED" | "DIRECT";

export type MissionDispatchChoice = {
  mode: DispatchMode;
  instrumentistId: number | null;
};

export const DEFAULT_DISPATCH_CHOICE: MissionDispatchChoice = { mode: "POOL", instrumentistId: null };

/** Whether the choice is complete enough to submit (TARGETED/DIRECT need a person). */
export function isDispatchChoiceComplete(choice: MissionDispatchChoice): boolean {
  return choice.mode === "POOL" || choice.instrumentistId !== null;
}

/** Wire shape of the optional `dispatch` field accepted by the surgeon-request accept endpoint. */
export function toDispatchPayload(choice: MissionDispatchChoice): { mode: DispatchMode; instrumentistId?: number } {
  return choice.mode === "POOL" ? { mode: "POOL" } : { mode: choice.mode, instrumentistId: choice.instrumentistId ?? undefined };
}

/**
 * Applies the manager's choice to an existing DRAFT (or re-dispatchable OPEN) Mission:
 * POOL/TARGETED → POST /publish (existing endpoint), DIRECT → POST /assign-directly.
 */
export async function dispatchMission(missionId: number, choice: MissionDispatchChoice): Promise<void> {
  if (choice.mode === "DIRECT") {
    await apiClient.post(`/api/missions/${missionId}/assign-directly`, { instrumentistId: choice.instrumentistId });
    return;
  }
  await apiClient.post(
    `/api/missions/${missionId}/publish`,
    choice.mode === "POOL" ? { scope: "POOL" } : { scope: "TARGETED", targetUserId: choice.instrumentistId },
  );
}

/** The target of a pending request refuses it (accepting = the existing claim). */
export async function declineMissionOffer(missionId: number, reason?: string): Promise<void> {
  await apiClient.post(`/api/missions/${missionId}/decline-offer`, reason ? { reason } : {});
}

export type DispatchSlot = {
  siteId: number;
  /** ISO 8601 instants, as sent to the API. */
  startAt: string;
  endAt: string;
  /** Existing mission, excluded from its own conflict check. */
  missionId?: number | null;
};

/**
 * Instrumentists OF THE MISSION'S SITE with the backend's eligibility annotation
 * (selectable + reasons) — GET /api/missions/dispatch-candidates. Never recomputed here.
 */
export async function fetchDispatchCandidates(slot: DispatchSlot): Promise<CandidateEligibility[]> {
  const res = await apiClient.get("/api/missions/dispatch-candidates", {
    params: {
      siteId: slot.siteId,
      startAt: slot.startAt,
      endAt: slot.endAt,
      ...(slot.missionId ? { missionId: slot.missionId } : {}),
    },
  });
  return (res.data?.candidates ?? []) as CandidateEligibility[];
}

/**
 * Every React Query cache that shows the operational planning — invalidated after any
 * mutation that changes a Mission's existence, status or instrumentist (creation, dispatch,
 * request acceptance, claim, decline, release, reassign, cancel), so the calendar reflects
 * it without reloading, changing month or regenerating.
 */
export function invalidateOperationalPlanning(queryClient: QueryClient, missionId?: number | null): Promise<unknown> {
  return Promise.all([
    queryClient.invalidateQueries({ queryKey: ["missions"] }),
    queryClient.invalidateQueries({ queryKey: ["planning-schedule"] }),
    queryClient.invalidateQueries({ queryKey: ["planning-v2", "modification-missions"] }),
    queryClient.invalidateQueries({ queryKey: ["coverage-summary"] }),
    queryClient.invalidateQueries({ queryKey: ["dispatch-candidates"] }),
    ...(missionId ? [queryClient.invalidateQueries({ queryKey: ["mission", missionId] })] : []),
  ]);
}
