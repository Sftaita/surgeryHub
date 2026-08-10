import * as React from "react";
import { useQueries, useQuery } from "@tanstack/react-query";
import { fetchRosterEligibility } from "./planningV2.api";
import type { CandidateEligibility, EligibilityEnforcementPolicy } from "./planningV2.types";

export interface RosterSlot {
  siteId: number | null;
  date: string;
  startTime: string;
  endTime: string;
  excludeMissionId?: number | null;
}

function slotKey(slot: RosterSlot): string {
  return `${slot.siteId ?? "null"}|${slot.date}|${slot.startTime}|${slot.endTime}|${slot.excludeMissionId ?? "null"}`;
}

function isCompleteSlot(slot: RosterSlot): boolean {
  return slot.date !== "" && slot.startTime !== "" && slot.endTime !== "";
}

/**
 * D-102 — eligibility-aware roster for a single mission-editor slot (Preview Editor's
 * single-line picker, create-mission draft). Backend (`evaluateRoster()`) stays the sole
 * source of truth for ABSENT/SCHEDULE_CONFLICT/INACTIVE/NO_SITE_MEMBERSHIP — never
 * recomputed client-side.
 */
export function useRosterEligibility(slot: RosterSlot | null, policy: EligibilityEnforcementPolicy) {
  return useQuery({
    queryKey: ["planning-v2", "roster-eligibility", slot ? slotKey(slot) : null, policy],
    queryFn: () => fetchRosterEligibility({ ...slot!, policy }),
    enabled: slot !== null && isCompleteSlot(slot),
    staleTime: 30_000,
  });
}

/**
 * D-102 — bulk-assign context: several lines, potentially different site/date/time each.
 * A candidate is offered only when selectable across every distinct slot in the selection
 * (conservative — never lets a manager bulk-assign someone unavailable for at least one of
 * the targeted missions without seeing that explicitly).
 */
export function useMergedRosterEligibility(slots: RosterSlot[], policy: EligibilityEnforcementPolicy) {
  const uniqueSlots = React.useMemo(() => {
    const seen = new Map<string, RosterSlot>();
    for (const s of slots) {
      if (isCompleteSlot(s)) seen.set(slotKey(s), s);
    }
    return [...seen.values()];
  }, [slots]);

  const results = useQueries({
    queries: uniqueSlots.map((slot) => ({
      queryKey: ["planning-v2", "roster-eligibility", slotKey(slot), policy],
      queryFn: () => fetchRosterEligibility({ ...slot, policy }),
      staleTime: 30_000,
    })),
  });

  const isLoading = uniqueSlots.length > 0 && results.some((r) => r.isLoading);

  const candidates = React.useMemo<CandidateEligibility[]>(() => {
    if (uniqueSlots.length === 0) return [];
    const byId = new Map<number, CandidateEligibility>();
    for (const r of results) {
      if (!r.data) continue;
      for (const c of r.data.candidates) {
        const existing = byId.get(c.id);
        if (!existing) {
          byId.set(c.id, c);
        } else {
          byId.set(c.id, {
            ...existing,
            selectable: existing.selectable && c.selectable,
            eligible: existing.eligible && c.eligible,
            reasons: Array.from(new Set([...existing.reasons, ...c.reasons])),
          });
        }
      }
    }
    return [...byId.values()];
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [results, uniqueSlots.length]);

  return { candidates, isLoading };
}
