/**
 * Fired by apiClient's response interceptor the moment a session is *definitively* expired
 * (refresh-token retry already failed, or there was no refresh token to try) — never for a
 * transient 401 that the interceptor's own refresh-and-retry already recovered from silently.
 *
 * AuthContext listens for this to flip its in-memory state to "anonymous" immediately, so
 * RequireAuth redirects to /login on the very next render — without this, a background
 * mutation's 401 clears tokens from storage but leaves the SPA's React state (and whatever
 * screen the user is on) untouched, since nothing else re-renders RequireAuth on its own.
 */
export const SESSION_EXPIRED_EVENT = "surgicalhub:session-expired";

export function dispatchSessionExpired() {
  window.dispatchEvent(new Event(SESSION_EXPIRED_EVENT));
}
