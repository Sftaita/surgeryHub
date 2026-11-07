import * as Sentry from "@sentry/react";
import { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";
import type { ReactNode } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { useAuth } from "../../auth/AuthContext";
import { useToast } from "../../ui/toast/useToast";
import {
  isPushSupported,
  registerServiceWorker,
  unsubscribeFromPush,
} from "./pushSubscriptionClient";
import { reconcilePush, type PushHealth, type ReconcileOutcome, type ReconcileTrigger } from "./pushReconcile";

export type PushNotificationStatus =
  | "unsupported"
  | "permission-default"
  | "permission-denied"
  | "subscribing"
  | "subscribed"
  | "error";

type PushNotificationContextValue = {
  status: PushNotificationStatus;
  permission: NotificationPermission | "unsupported";
  isSupported: boolean;
  isSubscribed: boolean;
  lastError: string | null;
  /** D-175 — dernier état de l'appareil courant rendu par le serveur (POST /api/push/sync), null avant la première réconciliation. */
  health: PushHealth | null;
  subscribe: () => Promise<void>;
  unsubscribe: () => Promise<void>;
  refreshStatus: () => Promise<void>;
  /** D-175 — réparation demandée par l'utilisateur (Mon profil) : renouvelle l'abonnement si le serveur le juge nécessaire. */
  repair: () => Promise<void>;
};

const PushNotificationContext = createContext<PushNotificationContextValue | null>(null);

function computeInitialStatus(): PushNotificationStatus {
  if (!isPushSupported()) return "unsupported";
  if (Notification.permission === "denied") return "permission-denied";
  if (Notification.permission === "granted") return "subscribed"; // confirmed/corrected by refreshStatus()
  return "permission-default";
}

/**
 * Mounted once at the app root (see AppProviders.tsx), inside AuthProvider — available to
 * every role/layout (MobileLayout AND DesktopLayout), unlike the previous hook which only
 * ever ran inside MobileLayout. Centralizes SW registration so it happens exactly once
 * regardless of how many components call usePushNotifications() (Lot 1 / audit 24-07-2026).
 */
export function PushProvider({ children }: { children: ReactNode }) {
  const { state } = useAuth();
  const toast = useToast();
  const queryClient = useQueryClient();
  // The identity that actually matters for push, not just the boolean: a shared browser
  // that goes A → B without an intervening full reload keeps this provider mounted (it
  // sits above the router, see AppProviders.tsx) — `isAuthenticated` alone stays `true`
  // across that swap and can't tell the two sessions apart (D-081).
  const currentUserId = state.status === "authenticated" ? state.user.id : null;

  const [status, setStatus] = useState<PushNotificationStatus>(computeInitialStatus);
  const [lastError, setLastError] = useState<string | null>(null);
  const [health, setHealth] = useState<PushHealth | null>(null);
  const swRegistered = useRef(false);
  // The user id (or null for anonymous) this provider has already reacted to. Distinct
  // from `undefined` (never processed yet, i.e. before the very first effect run) so
  // that a boot-time anonymous→anonymous "transition" isn't mistaken for a real one.
  const processedUserId = useRef<number | null | undefined>(undefined);

  const isSupported = isPushSupported();
  const permission = isSupported ? Notification.permission : "unsupported";

  const refreshStatus = useCallback(async () => {
    if (!isSupported) {
      setStatus("unsupported");
      return;
    }
    if (Notification.permission === "denied") {
      setStatus("permission-denied");
      return;
    }
    if (Notification.permission === "default") {
      setStatus("permission-default");
      return;
    }
    // granted: reflect whether a browser-level subscription actually exists.
    try {
      const registration = await navigator.serviceWorker.getRegistration();
      const subscription = await registration?.pushManager.getSubscription();
      setStatus(subscription ? "subscribed" : "permission-default");
    } catch {
      setStatus("permission-default");
    }
  }, [isSupported]);

  // D-175 — une réconciliation (jamais de demande de permission) et son effet sur le statut.
  const reconcile = useCallback(
    async (trigger: ReconcileTrigger, stage: string): Promise<ReconcileOutcome | null> => {
      if (currentUserId === null) return null;
      try {
        const outcome = await reconcilePush({ trigger, userId: currentUserId });
        if (outcome.kind === "synced") {
          setHealth(outcome.health);
          setStatus("subscribed");
          setLastError(outcome.health.renewalPending === "failed" ? "renewal-failed" : null);
        } else if (outcome.why === "permission-denied") {
          setStatus("permission-denied");
        } else if (outcome.why === "permission-default" || outcome.why === "opted-out") {
          // opted-out : désactivées volontairement sur cet appareil (permission toujours accordée).
          setStatus("permission-default");
        }
        return outcome;
      } catch (err) {
        setStatus("error");
        setLastError(`${stage}-failed`);
        Sentry.captureException(err, { tags: { feature: "push", stage } });
        return null;
      }
    },
    [currentUserId],
  );

  // Register the SW once at app start (any role, any auth state) — does not request
  // permission or subscribe. Not yet the cache/offline SW (out of scope for this lot).
  useEffect(() => {
    if (swRegistered.current || !isSupported) return;
    swRegistered.current = true;

    registerServiceWorker().catch((err) => {
      setLastError("service-worker-registration-failed");
      Sentry.captureException(err, { tags: { feature: "push", stage: "sw-register" } });
    });
  }, [isSupported]);

  // Nudge temps réel à la réception d'un push : toast immédiat + invalidation de la
  // requête notifications serveur (GET /api/notifications), pour que la cloche/le
  // badge se rafraîchissent sans attendre le prochain refetchInterval (60s). Remplace
  // l'ancien cache localStorage (notifications.store.ts, retiré — audit PWA/mobile/
  // admin 2026-07-29, revue post-rapport) : le backend reste la seule source de
  // vérité pour le compteur/l'historique, ceci n'est qu'un signal de fraîcheur.
  useEffect(() => {
    if (!isSupported || !("serviceWorker" in navigator)) return;

    const handler = (event: MessageEvent) => {
      if (event.data?.type !== "PUSH_NOTIFICATION") return;
      const { title, body } = event.data.payload ?? {};
      toast.info([title, body].filter(Boolean).join(" — ") || "Nouvelle notification");
      void queryClient.invalidateQueries({ queryKey: ["notifications"] });
    };

    navigator.serviceWorker.addEventListener("message", handler);
    return () => navigator.serviceWorker.removeEventListener("message", handler);
  }, [isSupported, toast, queryClient]);

  // Reacts to a change of *session identity* (anonymous → A, A → B directly, A → logout),
  // not merely to `isAuthenticated` flipping — this provider outlives every login/logout
  // in a tab (mounted above the router), so `isAuthenticated: true → true` across a same-
  // tab account switch would otherwise never re-run anything (D-081).
  useEffect(() => {
    if (processedUserId.current === currentUserId) return; // no actual identity change
    processedUserId.current = currentUserId;
    setLastError(null);

    if (currentUserId === null) {
      // Logged out (or never logged in): recompute fresh from the browser's actual state
      // — never just leave the previous user's `status` hanging around. Never revokes the
      // browser-level subscription here (AuthContext::logout() already detached it
      // server-side) and never prompts for permission.
      setHealth(null);
      if (isSupported) void refreshStatus();
      return;
    }

    // A (possibly new) authenticated session. Mirrors subscribe()'s permission handling,
    // minus ever calling Notification.requestPermission() — auto-reattachment only ever
    // happens for a permission the browser already holds.
    if (!isSupported) return;
    if (Notification.permission === "denied") {
      setStatus("permission-denied"); // not a technical error — no Sentry.
      return;
    }
    if (Notification.permission !== "granted") {
      setStatus("permission-default");
      return;
    }

    // granted: D-175 réconciliation — (re)rattache l'abonnement existant/nouveau à cette session
    // (premier login comme changement de compte dans le même onglet), et le renouvelle si le
    // serveur sait qu'il est mort. Upsert serveur indexé par endpoint (PushSubscriptionSyncService).
    void reconcile("login", "auto-resubscribe");
  }, [currentUserId, isSupported, refreshStatus, reconcile]);

  // D-175 — retour au premier plan (PWA rouverte depuis l'arrière-plan) : réconciliation, au plus
  // toutes les 6 h (pushReconcile.ts) ; changement de permission dans les réglages du navigateur.
  useEffect(() => {
    if (!isSupported || currentUserId === null) return;

    const onVisible = () => {
      if (document.visibilityState === "visible") void reconcile("visibility", "visibility-sync");
    };
    document.addEventListener("visibilitychange", onVisible);

    let permissionStatus: PermissionStatus | null = null;
    const onPermissionChange = () => {
      void refreshStatus();
      if (Notification.permission === "granted") void reconcile("permission", "permission-sync");
    };
    navigator.permissions
      ?.query({ name: "notifications" as PermissionName })
      .then((ps) => {
        permissionStatus = ps;
        ps.addEventListener?.("change", onPermissionChange);
      })
      .catch(() => {});

    return () => {
      document.removeEventListener("visibilitychange", onVisible);
      permissionStatus?.removeEventListener?.("change", onPermissionChange);
    };
  }, [isSupported, currentUserId, reconcile, refreshStatus]);

  const subscribe = useCallback(async () => {
    if (!isSupported) return;

    setStatus("subscribing");
    setLastError(null);
    try {
      const result = await Notification.requestPermission();
      if (result !== "granted") {
        // A user declining the permission prompt is expected, everyday behavior —
        // never reported to Sentry (see docs/decisions.md D-081).
        setStatus(result === "denied" ? "permission-denied" : "permission-default");
        return;
      }
    } catch (err) {
      setStatus("error");
      setLastError("subscribe-failed");
      Sentry.captureException(err, { tags: { feature: "push", stage: "subscribe" } });
      return;
    }
    await reconcile("activate", "subscribe");
  }, [isSupported, reconcile]);

  const repair = useCallback(async () => {
    if (!isSupported) return;
    await reconcile("repair", "repair");
  }, [isSupported, reconcile]);

  const unsubscribe = useCallback(async () => {
    if (!isSupported) return;

    try {
      await unsubscribeFromPush();
      setStatus("permission-default");
      setLastError(null);
      setHealth(null);
    } catch (err) {
      setLastError("unsubscribe-failed");
      Sentry.captureException(err, { tags: { feature: "push", stage: "unsubscribe" } });
    }
  }, [isSupported]);

  const value: PushNotificationContextValue = {
    status,
    permission,
    isSupported,
    isSubscribed: status === "subscribed",
    lastError,
    health,
    subscribe,
    unsubscribe,
    refreshStatus,
    repair,
  };

  return <PushNotificationContext.Provider value={value}>{children}</PushNotificationContext.Provider>;
}

export function usePushNotifications(): PushNotificationContextValue {
  const ctx = useContext(PushNotificationContext);
  if (!ctx) {
    throw new Error("usePushNotifications must be used inside PushProvider");
  }
  return ctx;
}
