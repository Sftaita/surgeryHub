import { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";
import { apiClient } from "../api/apiClient";
import { AUTH_STORAGE_KEY, clearAuth, readAuth, writeAuth } from "./authStorage";
import { loginRequest, logoutRequest } from "./authApi";
import { SESSION_EXPIRED_EVENT } from "./sessionExpiredEvent";
import { detachCurrentPushSubscription } from "../features/push/pushSubscriptionClient";

/* ======================
   Types
====================== */

type User = {
  id: number;
  role: string;
  sites: unknown[];
  firstname?: string | null;
  lastname?: string | null;
  profilePictureUrl?: string | null;
  instrumentistOnboardingCompleted?: boolean;
};

type AuthState =
  | { status: "initializing" }
  | { status: "anonymous" }
  | { status: "loading" }
  // D-157 — a stored session could not be checked (network down, timeout, 5xx): it is kept,
  // never treated as a logout, and the restoration is retried automatically.
  | { status: "unavailable" }
  | { status: "authenticated"; user: User };

type AuthContextType = {
  state: AuthState;
  login: (email: string, password: string, rememberMe?: boolean) => Promise<void>;
  logout: () => void;
  refreshUser: () => Promise<void>;
  retryRestore: () => void;
};

const RESTORE_RETRY_DELAYS_MS = [3_000, 10_000, 30_000, 60_000];

/* ======================
   Context
====================== */

const AuthContext = createContext<AuthContextType | null>(null);

/* ======================
   Provider
====================== */

export function AuthProvider({ children }: { children: React.ReactNode }) {
  // Lazy init : lu de façon synchrone au tout premier rendu (avant tout effect,
  // donc avant tout paint) pour ne jamais afficher "anonymous" par défaut quand
  // une session stockée doit encore être vérifiée — sinon /login (et tout écran
  // sous RequireAuth) flashe brièvement son état "déconnecté" le temps que le
  // bootstrap effect ci-dessous se déclenche. "initializing" couvre exactement
  // cette fenêtre ; "anonymous" ne veut dire que "rien à vérifier, vraiment déconnecté".
  const [state, setState] = useState<AuthState>(() =>
    readAuth() ? { status: "initializing" } : { status: "anonymous" }
  );

  /**
   * BOOTSTRAP
   * Au chargement de l’app :
   * - si tokens présents → /api/me (state déjà "initializing" depuis le lazy init ci-dessus)
   * - sinon → anonymous
   */
  const statusRef = useRef(state.status);
  statusRef.current = state.status;

  const restore = useCallback(() => {
    if (!readAuth()) {
      setState({ status: "anonymous" });
      return;
    }

    apiClient
      .get("/api/me") // ⚠️ plus jamais de header ici
      .then((res) => {
        setState({ status: "authenticated", user: res.data });
      })
      .catch(() => {
        // D-157 — ne JAMAIS effacer la session ici : tout échec (réseau coupé au lancement de
        // la PWA, timeout, 500/503) la faisait perdre. L'intercepteur apiClient efface déjà
        // les tokens quand la session est réellement invalide (refresh refusé en 401) ; s'ils
        // sont encore là, le serveur était seulement injoignable.
        setState(readAuth() ? { status: "unavailable" } : { status: "anonymous" });
      });
  }, []);

  useEffect(() => {
    restore();
  }, [restore]);

  /**
   * RESTORE RETRY (D-157) — server unreachable while restoring: retry with a back-off, as soon
   * as the device is back online, and when the PWA / tab comes back to the foreground.
   */
  useEffect(() => {
    if (state.status !== "unavailable") return;
    let attempt = 0;
    let timer: ReturnType<typeof setTimeout>;
    // The status stays "unavailable" while attempts fail, so this effect does not re-run: each
    // attempt schedules the next one (back-off capped at 60 s) until the cleanup cancels it.
    const schedule = () => {
      timer = setTimeout(() => {
        attempt += 1;
        restore();
        schedule();
      }, RESTORE_RETRY_DELAYS_MS[Math.min(attempt, RESTORE_RETRY_DELAYS_MS.length - 1)]);
    };
    const now = () => restore();
    const onVisible = () => {
      if (document.visibilityState === "visible") restore();
    };
    schedule();
    window.addEventListener("online", now);
    document.addEventListener("visibilitychange", onVisible);
    return () => {
      clearTimeout(timer);
      window.removeEventListener("online", now);
      document.removeEventListener("visibilitychange", onVisible);
    };
  }, [state.status, restore]);

  /**
   * OTHER TABS (D-157) — a logout in another tab removes the shared persistent session: follow
   * it here; a login in another tab while this one shows /login restores this one too.
   */
  useEffect(() => {
    function onStorage(e: StorageEvent) {
      if (e.key !== AUTH_STORAGE_KEY && e.key !== null) return;
      if (!readAuth()) {
        setState((prev) => (prev.status === "anonymous" ? prev : { status: "anonymous" }));
      } else if (statusRef.current === "anonymous") {
        restore();
      }
    }
    window.addEventListener("storage", onStorage);
    return () => window.removeEventListener("storage", onStorage);
  }, [restore]);

  /**
   * SESSION EXPIRY (background)
   * apiClient's interceptor clears tokens from storage the moment a session is definitively
   * expired, but that alone doesn't touch this component's in-memory state — nothing else
   * re-renders RequireAuth on its own. Flipping to "anonymous" here is what actually makes
   * RequireAuth redirect to /login on the next render, wherever in the app this happened.
   */
  useEffect(() => {
    function handleSessionExpired() {
      setState((prev) => (prev.status === "anonymous" ? prev : { status: "anonymous" }));
    }
    window.addEventListener(SESSION_EXPIRED_EVENT, handleSessionExpired);
    return () => window.removeEventListener(SESSION_EXPIRED_EVENT, handleSessionExpired);
  }, []);

  /**
   * LOGIN
   */
  async function login(email: string, password: string, rememberMe = false) {
    setState({ status: "loading" });

    const tokens = await loginRequest(email, password, rememberMe);
    // D-157 — "Rester connecté" → localStorage ; sinon sessionStorage (fermée avec le navigateur).
    writeAuth(tokens, rememberMe);

    const me = await apiClient.get("/api/me");

    setState({ status: "authenticated", user: me.data });
  }

  /**
   * LOGOUT
   *
   * Detaches this browser's push subscription server-side before the access token is
   * cleared — captured now and passed explicitly, since by the time the subscription
   * lookup resolves the token may already be gone from storage. Fire-and-forget: a slow
   * or failing detach must never delay token revocation, local cleanup, or the redirect
   * to /login (docs/decisions.md D-081).
   */
  function logout() {
    const stored = readAuth();
    void detachCurrentPushSubscription(stored?.accessToken ?? null);
    if (stored?.refreshToken) {
      void logoutRequest(stored.refreshToken);
    }
    clearAuth();
    setState({ status: "anonymous" });
  }

  /**
   * REFRESH USER
   * Re-fetches /api/me and updates the authenticated state in place — used after
   * self-service changes (e.g. profile picture upload) so the rest of the app
   * (avatar, prompt modal) reflects the new data without a full reload.
   */
  async function refreshUser() {
    const me = await apiClient.get("/api/me");
    setState({ status: "authenticated", user: me.data });
  }

  return (
    <AuthContext.Provider value={{ state, login, logout, refreshUser, retryRestore: restore }}>
      {children}
    </AuthContext.Provider>
  );
}

/* ======================
   Hook
====================== */

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error("useAuth must be used inside AuthProvider");
  }
  return ctx;
}
