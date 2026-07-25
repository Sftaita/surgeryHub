import * as Sentry from "@sentry/react";
import { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";
import type { ReactNode } from "react";
import { useAuth } from "../../auth/AuthContext";
import {
  isPushSupported,
  registerServiceWorker,
  subscribeToPush,
  unsubscribeFromPush,
} from "./pushSubscriptionClient";

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
  subscribe: () => Promise<void>;
  unsubscribe: () => Promise<void>;
  refreshStatus: () => Promise<void>;
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
  // The identity that actually matters for push, not just the boolean: a shared browser
  // that goes A → B without an intervening full reload keeps this provider mounted (it
  // sits above the router, see AppProviders.tsx) — `isAuthenticated` alone stays `true`
  // across that swap and can't tell the two sessions apart (D-081).
  const currentUserId = state.status === "authenticated" ? state.user.id : null;

  const [status, setStatus] = useState<PushNotificationStatus>(computeInitialStatus);
  const [lastError, setLastError] = useState<string | null>(null);
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

    // granted: idempotently (re)attach the existing/new browser subscription to whichever
    // user this session now is — safe to call for both a first-ever login and a same-tab
    // account switch (backend upsert is keyed by endpoint, see PushSubscriptionController).
    subscribeToPush()
      .then(() => setStatus("subscribed"))
      .catch((err) => {
        setStatus("error");
        setLastError("auto-resubscribe-failed");
        Sentry.captureException(err, { tags: { feature: "push", stage: "auto-resubscribe" } });
      });
  }, [currentUserId, isSupported, refreshStatus]);

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
      await subscribeToPush();
      setStatus("subscribed");
    } catch (err) {
      setStatus("error");
      setLastError("subscribe-failed");
      Sentry.captureException(err, { tags: { feature: "push", stage: "subscribe" } });
    }
  }, [isSupported]);

  const unsubscribe = useCallback(async () => {
    if (!isSupported) return;

    try {
      await unsubscribeFromPush();
      setStatus("permission-default");
      setLastError(null);
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
    subscribe,
    unsubscribe,
    refreshStatus,
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
