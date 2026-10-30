// @vitest-environment node
import { describe, it, expect, beforeEach, vi } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import vm from "node:vm";

/**
 * D-175 — le vrai `public/sw.js`, exécuté dans un contexte isolé avec des doubles du navigateur
 * (Cache Storage, fetch, registration, clients). Couvre les accusés de réception, la file hors
 * ligne, pushsubscriptionchange et le cas 11 (reçu sans confirmation d'affichage). Aucun réseau.
 */

const SW_SOURCE = readFileSync(resolve(__dirname, "../../../../public/sw.js"), "utf8");
const API = "https://api.example.test";

type Handler = (event: Record<string, unknown>) => void;

function makeCaches() {
  const stores = new Map<string, Map<string, string>>();
  return {
    stores,
    open: async (name: string) => {
      if (!stores.has(name)) stores.set(name, new Map());
      const store = stores.get(name)!;
      return {
        match: async (key: string) => (store.has(key) ? new Response(store.get(key)!) : undefined),
        put: async (key: string, res: Response) => {
          store.set(key, await res.text());
        },
      };
    },
    keys: async () => [...stores.keys()],
    delete: async (name: string) => stores.delete(name),
  };
}

function loadServiceWorker() {
  const handlers = new Map<string, Handler>();
  const caches = makeCaches();
  const fetchMock = vi.fn(async (_url: string, _init?: RequestInit) => new Response(null, { status: 204 }));
  const showNotification = vi.fn(async () => undefined);
  const subscribe = vi.fn(async () => ({
    endpoint: "https://push.example.test/new",
    toJSON: () => ({ endpoint: "https://push.example.test/new", keys: { p256dh: "p", auth: "a" } }),
  }));
  const postMessage = vi.fn();
  const clients = {
    matchAll: async () => [{ url: "https://app.example.test/app/s", postMessage, focus: vi.fn() }],
    claim: async () => undefined,
    openWindow: vi.fn(),
  };
  const self = {
    addEventListener: (type: string, h: Handler) => handlers.set(type, h),
    skipWaiting: vi.fn(),
    registration: { showNotification, pushManager: { subscribe } },
    clients,
  };
  const context = vm.createContext({ self, clients, caches, fetch: fetchMock, Response, Set, URL, atob, Date, JSON, Promise, Object, Uint8Array, console });
  vm.runInContext(SW_SOURCE, context);

  async function dispatch(type: string, event: Record<string, unknown> = {}) {
    const pending: Promise<unknown>[] = [];
    handlers.get(type)!({ ...event, waitUntil: (p: Promise<unknown>) => pending.push(p) });
    await Promise.all(pending);
  }

  async function state(key: string): Promise<unknown> {
    const raw = caches.stores.get("surgicalhub-push-state")?.get(key);
    return raw === undefined ? undefined : JSON.parse(raw);
  }

  async function setState(key: string, value: unknown) {
    const cache = await caches.open("surgicalhub-push-state");
    await cache.put(key, new Response(JSON.stringify(value)));
  }

  const pushEvent = (payload: unknown) => ({ data: { json: () => payload, text: () => JSON.stringify(payload) } });
  const sentReceipts = () =>
    fetchMock.mock.calls
      .filter((c) => String(c[0]).endsWith("/api/push/receipts"))
      .flatMap((c) => (JSON.parse(String((c[1] as RequestInit).body)) as { receipts: Array<{ receipt: string; event: string }> }).receipts.map((r) => `${r.event}:${r.receipt}`));

  return { dispatch, state, setState, pushEvent, sentReceipts, fetchMock, showNotification, subscribe, postMessage, clients };
}

const RECEIPT = "r".repeat(22);

let sw: ReturnType<typeof loadServiceWorker>;
beforeEach(async () => {
  sw = loadServiceWorker();
  await sw.dispatch("install");
  await sw.setState("/__push/device", { apiBase: API, deviceToken: "d".repeat(43), endpoint: "https://push.example.test/old", applicationServerKey: "AQID" });
});

describe("service worker — version", () => {
  it("publie sa version à l'installation (lue par la page pour annoncer les accusés)", async () => {
    expect(await sw.state("/__push/sw")).toEqual({ version: expect.stringMatching(/^[A-Za-z0-9._-]+$/) });
  });
});

describe("service worker — accusés de réception", () => {
  it("reçu puis affichage demandé, envoyés à l'API ; la clé n'est jamais transmise aux pages", async () => {
    await sw.dispatch("push", sw.pushEvent({ title: "T", body: "B", data: { url: "/app/s" }, receipt: RECEIPT }));

    expect(sw.showNotification).toHaveBeenCalledWith("T", expect.objectContaining({ data: { url: "/app/s", receipt: RECEIPT } }));
    expect(sw.sentReceipts()).toEqual([`RECEIVED:${RECEIPT}`, `DISPLAY_REQUESTED:${RECEIPT}`]);
    expect(sw.fetchMock.mock.calls[0][0]).toBe(`${API}/api/push/receipts`);
    expect(JSON.stringify(sw.postMessage.mock.calls)).not.toContain(RECEIPT);
    expect(await sw.state("/__push/receipts")).toEqual([]);
  });

  it("11. affichage en échec : « reçu » et « échec d'affichage », jamais « affichage demandé »", async () => {
    sw.showNotification.mockRejectedValueOnce(new Error("blocked"));
    await sw.dispatch("push", sw.pushEvent({ title: "T", body: "B", receipt: RECEIPT }));
    expect(sw.sentReceipts()).toEqual([`RECEIVED:${RECEIPT}`, `DISPLAY_FAILED:${RECEIPT}`]);
  });

  it("10. hors ligne : les accusés restent en file puis partent au prochain message de la page", async () => {
    sw.fetchMock.mockRejectedValueOnce(new TypeError("Failed to fetch"));
    await sw.dispatch("push", sw.pushEvent({ title: "T", body: "B", receipt: RECEIPT }));
    expect((await sw.state("/__push/receipts")) as unknown[]).toHaveLength(2);

    await sw.dispatch("message", { data: { type: "PUSH_FLUSH_RECEIPTS" } });
    expect(await sw.state("/__push/receipts")).toEqual([]);
    expect(sw.sentReceipts()).toContain(`RECEIVED:${RECEIPT}`);
  });

  it("réponse 5xx / 429 : conservés ; 400 : abandonnés (jamais renvoyés en boucle)", async () => {
    sw.fetchMock.mockResolvedValueOnce(new Response(null, { status: 503 }));
    await sw.dispatch("push", sw.pushEvent({ title: "T", body: "B", receipt: RECEIPT }));
    expect((await sw.state("/__push/receipts")) as unknown[]).toHaveLength(2);

    sw.fetchMock.mockResolvedValueOnce(new Response(null, { status: 400 }));
    await sw.dispatch("message", { data: { type: "PUSH_FLUSH_RECEIPTS" } });
    expect(await sw.state("/__push/receipts")).toEqual([]);
  });

  it("page jamais synchronisée (adresse de l'API inconnue) : file conservée, aucun appel", async () => {
    sw = loadServiceWorker();
    await sw.dispatch("push", sw.pushEvent({ title: "T", body: "B", receipt: RECEIPT }));
    expect(sw.fetchMock).not.toHaveBeenCalled();
    expect((await sw.state("/__push/receipts")) as unknown[]).toHaveLength(2);
  });

  it("notification ouverte : accusé « ouvert » ; ancien contenu sans clé : comportement inchangé", async () => {
    await sw.dispatch("notificationclick", { notification: { close: vi.fn(), data: { url: "/app/s", receipt: RECEIPT } } });
    expect(sw.sentReceipts()).toEqual([`OPENED:${RECEIPT}`]);

    sw.fetchMock.mockClear();
    await sw.dispatch("push", sw.pushEvent({ title: "T", body: "B" }));
    expect(sw.showNotification).toHaveBeenLastCalledWith("T", expect.objectContaining({ data: {} }));
    expect(sw.fetchMock).not.toHaveBeenCalled();
  });
});

describe("service worker — pushsubscriptionchange (4. abonnement remplacé par le navigateur)", () => {
  it("signale le nouvel abonnement avec le jeton d'appareil et mémorise le jeton renouvelé", async () => {
    sw.fetchMock.mockResolvedValueOnce(new Response(JSON.stringify({ state: "RENEWED", deviceToken: "n".repeat(43) }), { status: 200 }));
    const newSubscription = { endpoint: "https://push.example.test/by-browser", toJSON: () => ({ endpoint: "https://push.example.test/by-browser", keys: { p256dh: "p", auth: "a" } }) };
    await sw.dispatch("pushsubscriptionchange", { newSubscription, oldSubscription: null });

    const [url, init] = sw.fetchMock.mock.calls[0];
    expect(url).toBe(`${API}/api/push/device/renew`);
    expect(JSON.parse(String((init as RequestInit).body))).toMatchObject({ deviceToken: "d".repeat(43), subscription: { endpoint: "https://push.example.test/by-browser" } });
    expect(sw.subscribe).not.toHaveBeenCalled();
    expect(await sw.state("/__push/device")).toMatchObject({ deviceToken: "n".repeat(43), endpoint: "https://push.example.test/by-browser" });
  });

  it("sans nouvel abonnement fourni : se réabonne avec la clé serveur mémorisée", async () => {
    sw.fetchMock.mockResolvedValueOnce(new Response(JSON.stringify({ state: "RENEWED", deviceToken: "n".repeat(43) }), { status: 200 }));
    await sw.dispatch("pushsubscriptionchange", { newSubscription: null, oldSubscription: null });
    expect(sw.subscribe).toHaveBeenCalledWith(expect.objectContaining({ userVisibleOnly: true }));
    expect(sw.fetchMock).toHaveBeenCalledTimes(1);
  });

  it("aucun jeton d'appareil : ne fait rien (la réconciliation au démarrage prendra le relais)", async () => {
    await sw.setState("/__push/device", { apiBase: API, deviceToken: null, endpoint: null, applicationServerKey: null });
    await sw.dispatch("pushsubscriptionchange", { newSubscription: null, oldSubscription: null });
    expect(sw.fetchMock).not.toHaveBeenCalled();
    expect(sw.subscribe).not.toHaveBeenCalled();
  });

  it("refus serveur (401) : jeton inchangé, aucune exception", async () => {
    sw.fetchMock.mockResolvedValueOnce(new Response(JSON.stringify({ code: "PUSH_DEVICE_UNKNOWN" }), { status: 401 }));
    await sw.dispatch("pushsubscriptionchange", { newSubscription: null, oldSubscription: null });
    expect(await sw.state("/__push/device")).toMatchObject({ deviceToken: "d".repeat(43) });
  });
});
