export const AUTH_STORAGE_KEY = "surgicalhub.auth.v2";
const STORAGE_KEY = AUTH_STORAGE_KEY;

export type StoredAuth = {
  accessToken: string;
  refreshToken: string;
};

/**
 * D-157 — one stored session per browser, shared by all its tabs (localStorage), so that every tab
 * presents the same refresh-token chain: a duplicated tab never keeps a stale copy of the chain,
 * which the server would take for a replayed (stolen) token and answer by revoking the session.
 *
 * - "Rester connecté sur cet appareil": the entry survives closing the browser / the PWA.
 * - Otherwise (shared device) the entry is bound to a browser-session cookie (no expiry, never
 *   holds a token): once the browser has been closed the cookie is gone and the entry is dropped
 *   on the next read, so the session ends with the browser.
 * - Cookies blocked: the non-persistent session falls back to this tab's sessionStorage.
 */
type StoredEntry = StoredAuth & { browserSession?: string };

const BROWSER_SESSION_COOKIE = "surgicalhub.auth.bs";

function local(): Storage | null {
  try { return localStorage; } catch { return null; }
}

function tab(): Storage | null {
  try { return sessionStorage; } catch { return null; }
}

function browserSessionId(): string | null {
  try {
    const match = document.cookie.split("; ").find((c) => c.startsWith(BROWSER_SESSION_COOKIE + "="));
    return match ? match.slice(BROWSER_SESSION_COOKIE.length + 1) : null;
  } catch {
    return null;
  }
}

/** The browser-session id, created on first use; null when cookies are blocked. */
function ensureBrowserSessionId(): string | null {
  const existing = browserSessionId();
  if (existing) return existing;
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  const id = Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("");
  const secure = location.protocol === "https:" ? "; Secure" : "";
  try {
    document.cookie = `${BROWSER_SESSION_COOKIE}=${id}; path=/; SameSite=Strict${secure}`;
  } catch {
    return null;
  }
  return browserSessionId() === id ? id : null;
}

function readFrom(storage: Storage | null): StoredEntry | null {
  const raw = storage?.getItem(STORAGE_KEY);
  if (!storage || !raw) return null;
  try {
    const parsed = JSON.parse(raw) as Partial<StoredEntry>;
    if (parsed.accessToken && parsed.refreshToken) return parsed as StoredEntry;
  } catch {
    // corrupted entry: dropped below
  }
  storage.removeItem(STORAGE_KEY);
  return null;
}

/** The shared entry, unless it belonged to a browser session that has since been closed (then dropped). */
function readShared(): StoredEntry | null {
  const entry = readFrom(local());
  if (entry?.browserSession !== undefined && entry.browserSession !== browserSessionId()) {
    local()?.removeItem(STORAGE_KEY);
    return null;
  }
  return entry;
}

export function readAuth(): StoredAuth | null {
  const entry = readShared() ?? readFrom(tab());
  return entry ? { accessToken: entry.accessToken, refreshToken: entry.refreshToken } : null;
}

/** True when the stored session is the persistent one ("Rester connecté"). */
export function isPersistentAuth(): boolean {
  const entry = readShared();
  return entry !== null && entry.browserSession === undefined;
}

/**
 * Writes the tokens. `persistent` defaults to the kind of the current session, so a refresh
 * (token rotation) never turns a shared-device session into a persistent one or the reverse.
 */
export function writeAuth(auth: StoredAuth, persistent: boolean = isPersistentAuth() || readAuth() === null) {
  const tokens: StoredAuth = { accessToken: auth.accessToken, refreshToken: auth.refreshToken };
  if (persistent) {
    local()?.setItem(STORAGE_KEY, JSON.stringify(tokens));
    tab()?.removeItem(STORAGE_KEY);
    return;
  }
  const browserSession = ensureBrowserSessionId();
  if (browserSession !== null && local()) {
    local()!.setItem(STORAGE_KEY, JSON.stringify({ ...tokens, browserSession }));
    tab()?.removeItem(STORAGE_KEY);
  } else {
    tab()?.setItem(STORAGE_KEY, JSON.stringify(tokens));
    local()?.removeItem(STORAGE_KEY);
  }
}

export function clearAuth() {
  local()?.removeItem(STORAGE_KEY);
  tab()?.removeItem(STORAGE_KEY);
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
