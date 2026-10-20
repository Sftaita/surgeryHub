export const AUTH_STORAGE_KEY = "surgicalhub.auth.v2";
const STORAGE_KEY = AUTH_STORAGE_KEY;

export type StoredAuth = {
  accessToken: string;
  refreshToken: string;
};

/**
 * D-157 — "Rester connecté sur cet appareil" keeps the session in localStorage (survives closing
 * the browser / the PWA and restarting the device); otherwise it lives in sessionStorage and ends
 * with the browser session (shared device). Both are read; a session is only ever in one of them.
 */
function stores(): Storage[] {
  const list: Storage[] = [];
  try { list.push(localStorage); } catch { /* storage unavailable */ }
  try { list.push(sessionStorage); } catch { /* storage unavailable */ }
  return list;
}

function readFrom(storage: Storage): StoredAuth | null {
  const raw = storage.getItem(STORAGE_KEY);
  if (!raw) return null;
  try {
    const parsed = JSON.parse(raw) as Partial<StoredAuth>;
    if (parsed.accessToken && parsed.refreshToken) return parsed as StoredAuth;
  } catch {
    // corrupted entry: dropped below
  }
  storage.removeItem(STORAGE_KEY);
  return null;
}

export function readAuth(): StoredAuth | null {
  for (const storage of stores()) {
    const auth = readFrom(storage);
    if (auth) return auth;
  }
  return null;
}

/** True when the stored session is the persistent one (localStorage). */
export function isPersistentAuth(): boolean {
  try {
    return localStorage.getItem(STORAGE_KEY) !== null;
  } catch {
    return false;
  }
}

/**
 * Writes the tokens. `persistent` defaults to wherever the current session already lives, so a
 * refresh (token rotation) never moves a session from one storage to the other.
 */
export function writeAuth(auth: StoredAuth, persistent: boolean = isPersistentAuth() || !hasSessionAuth()) {
  const [target, other] = persistent ? [localStorage, sessionStorage] : [sessionStorage, localStorage];
  target.setItem(STORAGE_KEY, JSON.stringify(auth));
  other.removeItem(STORAGE_KEY);
}

function hasSessionAuth(): boolean {
  try {
    return sessionStorage.getItem(STORAGE_KEY) !== null;
  } catch {
    return false;
  }
}

export function clearAuth() {
  for (const storage of stores()) storage.removeItem(STORAGE_KEY);
}

const SESSION_EXPIRED_KEY = "surgicalhub.auth.sessionExpired";

export function markSessionExpired() {
  sessionStorage.setItem(SESSION_EXPIRED_KEY, "1");
}

export function consumeSessionExpired(): boolean {
  const expired = sessionStorage.getItem(SESSION_EXPIRED_KEY) === "1";
  sessionStorage.removeItem(SESSION_EXPIRED_KEY);
  return expired;
}
