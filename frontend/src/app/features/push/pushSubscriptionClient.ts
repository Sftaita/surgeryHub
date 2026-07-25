import axios from "axios";
import { apiClient } from "../../api/apiClient";

/**
 * Plain (non-React) push subscription primitives — kept free of any React/context
 * dependency so AuthContext.tsx can call detachCurrentPushSubscription() directly at
 * logout without a circular import (Lot 1 / audit PWA-push 24-07-2026).
 */

export function isPushSupported(): boolean {
  return (
    "Notification" in window &&
    "serviceWorker" in navigator &&
    "PushManager" in window
  );
}

async function getVapidPublicKey(): Promise<string> {
  const res = await apiClient.get<{ publicKey: string }>("/api/push/vapid-public-key");
  return res.data.publicKey;
}

function urlBase64ToUint8Array(base64String: string): Uint8Array {
  const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
  const rawData = atob(base64);
  return Uint8Array.from([...rawData].map((char) => char.charCodeAt(0)));
}

async function getCurrentSubscription(): Promise<PushSubscription | null> {
  if (!isPushSupported()) return null;
  const registration = await navigator.serviceWorker.getRegistration();
  if (!registration) return null;
  return registration.pushManager.getSubscription();
}

/**
 * Registers the service worker if not already registered. Idempotent and safe to call
 * from multiple mount points — `register()` on an already-registered scope is a no-op
 * that resolves to the existing registration (per the Service Worker spec).
 */
export async function registerServiceWorker(): Promise<ServiceWorkerRegistration | null> {
  if (!("serviceWorker" in navigator)) return null;
  return navigator.serviceWorker.register("/sw.js");
}

/**
 * Creates (or reuses) the browser-level PushSubscription and attaches it to the
 * currently authenticated user server-side. Throws on failure — callers decide how to
 * surface/log it (see PushProvider), instead of the previous silent `.catch(() => {})`.
 */
export async function subscribeToPush(): Promise<void> {
  const registration = await navigator.serviceWorker.ready;
  const vapidPublicKey = await getVapidPublicKey();
  const applicationServerKey = urlBase64ToUint8Array(vapidPublicKey);

  let subscription = await registration.pushManager.getSubscription();
  if (!subscription) {
    subscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: applicationServerKey as unknown as BufferSource,
    });
  }

  const json = subscription.toJSON();
  await apiClient.post("/api/push/subscribe", {
    endpoint: json.endpoint,
    keys: {
      p256dh: json.keys?.p256dh,
      auth: json.keys?.auth,
    },
  });
}

/**
 * Full opt-out on this device: detaches the subscription server-side for the current
 * user, then revokes the browser-level PushSubscription itself. Distinct from the
 * server-only detach used at logout (detachCurrentPushSubscription) — see docs/api.md.
 */
export async function unsubscribeFromPush(): Promise<void> {
  const subscription = await getCurrentSubscription();
  if (!subscription) return;

  await apiClient.delete("/api/push/unsubscribe", { data: { endpoint: subscription.endpoint } });
  await subscription.unsubscribe();
}

/**
 * Best-effort server-side detach of this browser's push subscription, called at logout
 * — BEFORE local tokens are cleared. `accessToken` must be captured by the caller ahead
 * of clearing storage: by the time getCurrentSubscription() resolves, the live token in
 * storage may already be gone, so this bypasses apiClient's storage-reading interceptor
 * and sends the captured token explicitly. Never throws — logout must never be blocked
 * or delayed by this (see AuthContext.tsx::logout, docs/decisions.md D-081).
 */
export async function detachCurrentPushSubscription(accessToken: string | null): Promise<void> {
  if (!accessToken) return;

  try {
    const subscription = await getCurrentSubscription();
    if (!subscription) return;

    await axios.delete(`${import.meta.env.VITE_API_BASE_URL}/api/push/unsubscribe`, {
      headers: { Authorization: `Bearer ${accessToken}` },
      data: { endpoint: subscription.endpoint },
      timeout: 5000,
    });
  } catch {
    // Best-effort only. A stale/orphaned subscription self-heals on next login via the
    // backend's logged reassignment path (PushSubscriptionController::subscribe).
  }
}
