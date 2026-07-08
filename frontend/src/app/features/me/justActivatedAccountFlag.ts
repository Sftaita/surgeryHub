const KEY = "surgicalhub.justActivatedAccount";

/** Set by CompleteAccountPage right after a successful activation. */
export function markAccountJustActivated(): void {
  try {
    sessionStorage.setItem(KEY, "1");
  } catch {
    // sessionStorage unavailable (private browsing, etc.) — best effort only.
  }
}

/**
 * True for the rest of the browser session following account activation —
 * used to avoid showing the photo prompt seconds after onboarding already
 * offered (and the user possibly declined) the same choice.
 */
export function wasAccountJustActivated(): boolean {
  try {
    return sessionStorage.getItem(KEY) === "1";
  } catch {
    return false;
  }
}
