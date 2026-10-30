/**
 * D-175 — état partagé entre la page et le service worker (`public/sw.js`), dans le Cache Storage
 * de l'origine (accessible aux deux, contrairement à localStorage) :
 *
 *  - `/__push/sw`     écrit par le service worker : sa version (`{ version }`) — sert à savoir s'il
 *                     sait envoyer des accusés de réception ;
 *  - `/__push/device` écrit par la page après POST /api/push/sync : `{ apiBase, deviceToken,
 *                     endpoint }` — le service worker en a besoin, sans session, pour envoyer ses
 *                     accusés et traiter `pushsubscriptionchange`.
 *
 * Le jeton d'appareil ne donne qu'un droit : remplacer l'endpoint de CET appareil (voir
 * PushDeviceRenewalService). Il n'est jamais envoyé ailleurs qu'à l'API SurgicalHub. Toutes les
 * fonctions avalent les erreurs : sans Cache Storage (navigation privée, ancien navigateur), la
 * réconciliation fonctionne quand même, seuls les accusés et pushsubscriptionchange manquent.
 */

export const PUSH_STATE_CACHE = "surgicalhub-push-state";
const SW_KEY = "/__push/sw";
const DEVICE_KEY = "/__push/device";

export type PushDeviceState = {
  apiBase: string;
  deviceToken: string | null;
  endpoint: string | null;
  /** Clé VAPID publique (base64url) : le service worker en a besoin pour se réabonner seul si le navigateur ne la fournit pas. */
  applicationServerKey: string | null;
};

async function readJson<T>(key: string): Promise<T | null> {
  try {
    if (typeof caches === "undefined") return null;
    const cache = await caches.open(PUSH_STATE_CACHE);
    const res = await cache.match(key);
    return res ? ((await res.json()) as T) : null;
  } catch {
    return null;
  }
}

async function writeJson(key: string, value: unknown): Promise<void> {
  try {
    if (typeof caches === "undefined") return;
    const cache = await caches.open(PUSH_STATE_CACHE);
    await cache.put(key, new Response(JSON.stringify(value), { headers: { "Content-Type": "application/json" } }));
  } catch {
    // Best-effort : voir le commentaire du fichier.
  }
}

/** Version du service worker actif, ou null (ancien service worker sans accusés). */
export async function readServiceWorkerVersion(): Promise<string | null> {
  const value = await readJson<{ version?: unknown }>(SW_KEY);
  return typeof value?.version === "string" ? value.version : null;
}

export async function readDeviceState(): Promise<PushDeviceState | null> {
  return readJson<PushDeviceState>(DEVICE_KEY);
}

export async function writeDeviceState(state: PushDeviceState): Promise<void> {
  await writeJson(DEVICE_KEY, state);
}

/**
 * Efface le jeton d'appareil de cette origine : déconnexion, désactivation volontaire. Sans lui, le
 * service worker ne peut plus rien renouveler seul ; seule une nouvelle synchronisation authentifiée
 * en remettra un.
 */
export async function clearDeviceState(): Promise<void> {
  try {
    if (typeof caches === "undefined") return;
    const cache = await caches.open(PUSH_STATE_CACHE);
    await cache.delete(DEVICE_KEY);
  } catch {
    // Best-effort : côté serveur, le jeton meurt de toute façon avec l'abonnement supprimé.
  }
}

// « Désactiver » dans Mon profil : la permission du navigateur reste « granted » (un site ne peut
// pas la retirer), le choix est donc mémorisé sur cet appareil pour qu'aucune réconciliation
// automatique (connexion, premier plan, permission) ne réabonne. Seul « Activer » l'efface.
const OPTED_OUT_KEY = "surgicalhub.push.optedOut";

export function isPushOptedOut(): boolean {
  try {
    return localStorage.getItem(OPTED_OUT_KEY) === "1";
  } catch {
    return false;
  }
}

export function setPushOptedOut(optedOut: boolean): void {
  try {
    if (optedOut) localStorage.setItem(OPTED_OUT_KEY, "1");
    else localStorage.removeItem(OPTED_OUT_KEY);
  } catch {
    // Stockage indisponible : le choix ne survit pas au rechargement (limite connue).
  }
}
