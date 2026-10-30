import { apiClient } from "../../api/apiClient";
import { getApplicationServerKey, isPushSupported } from "./pushSubscriptionClient";
import { isPushOptedOut, readDeviceState, readServiceWorkerVersion, setPushOptedOut, writeDeviceState } from "./pushDeviceState";

/**
 * D-175 — réconciliation navigateur ↔ serveur des notifications push.
 *
 * Le navigateur décrit l'abonnement qu'il détient (POST /api/push/sync) ; le serveur dit s'il le
 * reconnaît, l'a enregistré ou rattaché, ou s'il faut le renouveler (endpoint déclaré mort par le
 * fournisseur, ou appareil suspect si cette réparation est activée). Le client ne décide rien
 * seul : il ne renouvelle que sur l'ordre du serveur (`autoRenew`) ou sur demande explicite de
 * l'utilisateur (Mon profil), jamais à cause de l'ancienneté d'un abonnement.
 *
 * Garde-fous :
 *  - jamais de demande de permission ici, et rien si la permission n'est pas « granted » (un refus
 *    explicite n'est jamais contourné) ;
 *  - une seule réconciliation à la fois (promesse partagée + verrou inter-onglets Web Locks) ;
 *  - au retour au premier plan, au plus une réconciliation toutes les 6 h ;
 *  - au plus un renouvellement automatique par 24 h sur cet appareil (le serveur applique la même
 *    fenêtre de son côté), et jamais deux renouvellements dans la même réconciliation.
 */

export type ReconcileTrigger = "login" | "visibility" | "permission" | "activate" | "repair";

export type PushSyncState = "OK" | "REGISTERED" | "REATTACHED" | "RENEWED" | "RENEW_REQUIRED";

export type PushDeviceHealth = {
  id: number;
  provider: string;
  registeredAt: string | null;
  lastAcceptedAt: string | null;
  lastReceiptAt: string | null;
  receiptsCapable: boolean;
  unacknowledged: number;
  suspected: boolean;
  otherDevices: number;
};

export type PushHealth = {
  state: PushSyncState;
  reason: "INVALIDATED" | "SUSPECTED" | null;
  /** Renouvellement effectué automatiquement pendant cette réconciliation. */
  renewed: boolean;
  /** Renouvellement nécessaire mais non effectué : fenêtre anti-boucle, réparation coupée côté serveur, ou échec. */
  renewalPending: "cooldown" | "manual" | "failed" | null;
  device: PushDeviceHealth | null;
  checkedAt: string;
};

export type ReconcileOutcome =
  | { kind: "skipped"; why: "unsupported" | "permission-denied" | "permission-default" | "opted-out" | "throttled" | "busy" }
  | { kind: "synced"; health: PushHealth };

type SyncResponse = {
  state: PushSyncState;
  reason: "INVALIDATED" | "SUSPECTED" | null;
  autoRenew: boolean;
  deviceToken: string | null;
  device: PushDeviceHealth | null;
};

export const VISIBILITY_THROTTLE_MS = 6 * 60 * 60 * 1000;
export const RENEWAL_COOLDOWN_MS = 24 * 60 * 60 * 1000;
const LOCK_NAME = "surgicalhub-push-reconcile";
const lastSyncKey = (userId: number) => `surgicalhub.push.lastSync.${userId}`;
const LAST_RENEWAL_KEY = "surgicalhub.push.lastRenewal";
// Endpoint abandonné par un renouvellement interrompu (désabonné, nouveau non transmis) : envoyé
// à la synchronisation suivante pour que le serveur déplace l'appareil au lieu d'en créer un second.
const PENDING_REPLACES_KEY = "surgicalhub.push.pendingReplaces";

function readString(key: string): string | null {
  try {
    return localStorage.getItem(key);
  } catch {
    return null;
  }
}

function writeString(key: string, value: string | null): void {
  try {
    if (value === null) localStorage.removeItem(key);
    else localStorage.setItem(key, value);
  } catch {
    // voir writeNumber.
  }
}

function readNumber(key: string): number | null {
  try {
    const raw = localStorage.getItem(key);
    const n = raw === null ? NaN : Number(raw);
    return Number.isFinite(n) ? n : null;
  } catch {
    return null;
  }
}

function writeNumber(key: string, value: number): void {
  try {
    localStorage.setItem(key, String(value));
  } catch {
    // stockage indisponible : la fenêtre serveur reste la protection.
  }
}

let inFlight: Promise<ReconcileOutcome> | null = null;

/** Tests uniquement. */
export function resetReconcileStateForTests(): void {
  inFlight = null;
}

export function reconcilePush(opts: { trigger: ReconcileTrigger; userId: number; now?: () => number }): Promise<ReconcileOutcome> {
  if (!isPushSupported()) return Promise.resolve({ kind: "skipped", why: "unsupported" });
  // « activate » suit immédiatement un Notification.requestPermission() résolu à « granted » :
  // on s'y fie plutôt qu'à Notification.permission, qu'un navigateur peut mettre à jour en différé.
  const permission = opts.trigger === "activate" ? "granted" : Notification.permission;
  if (permission === "denied") return Promise.resolve({ kind: "skipped", why: "permission-denied" });
  if (permission !== "granted") return Promise.resolve({ kind: "skipped", why: "permission-default" });
  if (opts.trigger === "activate") setPushOptedOut(false);
  else if (isPushOptedOut()) return Promise.resolve({ kind: "skipped", why: "opted-out" });

  const now = opts.now ?? Date.now;
  if (opts.trigger === "visibility") {
    const last = readNumber(lastSyncKey(opts.userId));
    if (last !== null && now() - last < VISIBILITY_THROTTLE_MS) return Promise.resolve({ kind: "skipped", why: "throttled" });
  }

  if (inFlight) return inFlight;

  const run = async (): Promise<ReconcileOutcome> => {
    const locks = (navigator as Navigator & { locks?: LockManager }).locks;
    if (!locks?.request) return reconcileOnce(opts.trigger, opts.userId, now);

    let outcome: ReconcileOutcome = { kind: "skipped", why: "busy" };
    await locks.request(LOCK_NAME, { ifAvailable: true }, async (lock) => {
      if (lock) outcome = await reconcileOnce(opts.trigger, opts.userId, now);
    });
    return outcome;
  };

  inFlight = run().finally(() => {
    inFlight = null;
  });
  return inFlight;
}

async function postSync(subscription: PushSubscription, extra: Record<string, unknown>): Promise<SyncResponse | null> {
  const json = subscription.toJSON();
  const swVersion = await readServiceWorkerVersion();
  const res = await apiClient.post<SyncResponse>("/api/push/sync", {
    endpoint: json.endpoint,
    keys: { p256dh: json.keys?.p256dh, auth: json.keys?.auth },
    swVersion,
    receipts: swVersion !== null,
    ...extra,
  });
  return res?.data && typeof res.data === "object" && "state" in res.data ? res.data : null;
}

function toBase64Url(buffer: ArrayBuffer | null | undefined): string | null {
  if (!buffer) return null;
  const bytes = new Uint8Array(buffer);
  let binary = "";
  bytes.forEach((b) => (binary += String.fromCharCode(b)));
  return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}

async function remember(subscription: PushSubscription, response: SyncResponse | null, previous: { deviceToken: string | null; endpoint: string | null } | null): Promise<void> {
  const keepToken = previous?.endpoint === subscription.endpoint ? previous.deviceToken : null;
  await writeDeviceState({
    apiBase: import.meta.env.VITE_API_BASE_URL ?? "",
    deviceToken: response?.deviceToken ?? keepToken,
    endpoint: subscription.endpoint,
    applicationServerKey: toBase64Url(subscription.options?.applicationServerKey),
  });
  // Accusés mis en file hors ligne, ou avant que le service worker connaisse l'adresse de l'API.
  navigator.serviceWorker.controller?.postMessage({ type: "PUSH_FLUSH_RECEIPTS" });
}

async function reconcileOnce(trigger: ReconcileTrigger, userId: number, now: () => number): Promise<ReconcileOutcome> {
  const registration = await navigator.serviceWorker.ready;
  let subscription = await registration.pushManager.getSubscription();
  if (!subscription) {
    // Permission accordée mais aucun abonnement local (jamais créé, ou perdu) : en créer un ne
    // demande rien à l'utilisateur.
    subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: await getApplicationServerKey() });
  }

  const stored = await readDeviceState();
  const hasDeviceToken = Boolean(stored?.deviceToken) && stored?.endpoint === subscription.endpoint;
  const pendingReplaces = readString(PENDING_REPLACES_KEY);
  let response = await postSync(subscription, {
    hasDeviceToken,
    ...(pendingReplaces && pendingReplaces !== subscription.endpoint ? { replacesEndpoint: pendingReplaces } : {}),
  });
  writeString(PENDING_REPLACES_KEY, null);
  await remember(subscription, response, stored);
  writeNumber(lastSyncKey(userId), now());

  let renewed = false;
  let renewalPending: PushHealth["renewalPending"] = null;

  const manual = trigger === "repair";
  // Réparation demandée par l'utilisateur : aussi pour un appareil seulement suspect (le
  // renouvellement automatique sur suspicion reste coupé côté serveur, D-175).
  if (response?.state === "RENEW_REQUIRED" || (manual && response?.device?.suspected)) {
    const lastRenewal = readNumber(LAST_RENEWAL_KEY);
    if (!response?.autoRenew && !manual) {
      renewalPending = "manual";
    } else if (!manual && lastRenewal !== null && now() - lastRenewal < RENEWAL_COOLDOWN_MS) {
      renewalPending = "cooldown";
    } else {
      writeNumber(LAST_RENEWAL_KEY, now());
      try {
        const previousEndpoint = subscription.endpoint;
        // Clé obtenue AVANT le désabonnement : une coupure réseau ne doit pas laisser l'appareil sans abonnement.
        const applicationServerKey = await getApplicationServerKey();
        writeString(PENDING_REPLACES_KEY, previousEndpoint);
        await subscription.unsubscribe();
        subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey });
        response = await postSync(subscription, { hasDeviceToken: false, replacesEndpoint: previousEndpoint });
        writeString(PENDING_REPLACES_KEY, null);
        await remember(subscription, response, null);
        // Jamais de second renouvellement dans la même réconciliation : un nouvel ordre
        // attendra la prochaine fenêtre (protection contre les boucles).
        renewed = response?.state !== "RENEW_REQUIRED";
        renewalPending = renewed ? null : "failed";
      } catch {
        renewalPending = "failed";
      }
    }
  }

  return {
    kind: "synced",
    health: {
      state: response?.state ?? "OK",
      reason: response?.reason ?? null,
      renewed,
      renewalPending,
      device: response?.device ?? null,
      checkedAt: new Date(now()).toISOString(),
    },
  };
}
