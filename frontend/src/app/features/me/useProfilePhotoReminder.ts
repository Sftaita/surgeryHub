import { useState } from "react";

interface ReminderState {
  dismissedAt: number;
  dismissCount: number;
}

/** Days to wait before re-showing, indexed by (dismissCount - 1). Capped at the last value. */
const CADENCE_DAYS = [7, 14, 30];
/** After this many dismissals, stop reminding entirely — a clear signal of disinterest. */
const MAX_DISMISSALS = 4;
const DAY_MS = 24 * 60 * 60 * 1000;

function storageKey(userId: number): string {
  return `surgicalhub.profilePhotoPrompt.${userId}`;
}

function readState(userId: number): ReminderState | null {
  try {
    const raw = localStorage.getItem(storageKey(userId));
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    if (typeof parsed?.dismissedAt === "number" && typeof parsed?.dismissCount === "number") {
      return parsed;
    }
    return null;
  } catch {
    return null;
  }
}

function writeState(userId: number, state: ReminderState): void {
  try {
    localStorage.setItem(storageKey(userId), JSON.stringify(state));
  } catch {
    // localStorage unavailable (private browsing, quota) — dismiss holds for this render only.
  }
}

/** Pure scheduling rule, exported standalone so the cadence itself is unit-testable without a DOM. */
export function isReminderDue(state: ReminderState | null, now: number = Date.now()): boolean {
  if (!state) return true;
  if (state.dismissCount >= MAX_DISMISSALS) return false;
  const waitDays = CADENCE_DAYS[Math.min(state.dismissCount - 1, CADENCE_DAYS.length - 1)];
  return now - state.dismissedAt >= waitDays * DAY_MS;
}

/**
 * Per-user, persisted (not per-tab) dismiss schedule for the profile photo
 * prompt: 7 days after the first "Plus tard", 14 after the second, 30 after
 * the third, then silence for good after the fourth.
 */
export function useProfilePhotoReminder(userId: number) {
  const [state, setState] = useState<ReminderState | null>(() => readState(userId));

  function dismiss() {
    const next: ReminderState = {
      dismissedAt: Date.now(),
      dismissCount: (state?.dismissCount ?? 0) + 1,
    };
    writeState(userId, next);
    setState(next);
  }

  return { isDue: isReminderDue(state), dismiss };
}
