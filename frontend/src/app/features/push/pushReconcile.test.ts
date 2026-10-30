import { describe, it, expect, beforeEach, vi } from "vitest";
import { reconcilePush, resetReconcileStateForTests, RENEWAL_COOLDOWN_MS, VISIBILITY_THROTTLE_MS } from "./pushReconcile";
import { isPushOptedOut, setPushOptedOut } from "./pushDeviceState";

/**
 * D-175 — réconciliation navigateur ↔ serveur. Le serveur (POST /api/push/sync) est simulé ; le
 * PushManager du navigateur aussi. Aucun envoi réel.
 */

const apiPostMock = vi.fn();
const apiGetMock = vi.fn();
vi.mock("../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
    post: (...args: unknown[]) => apiPostMock(...args),
  },
}));

let deviceState: Record<string, unknown> | null = null;
let swVersion: string | null = "2026.10.30-1";
const writeDeviceStateMock = vi.fn(async (s: Record<string, unknown>) => {
  deviceState = s;
});
vi.mock("./pushDeviceState", async (importOriginal) => ({
  ...(await importOriginal<typeof import("./pushDeviceState")>()),
  readServiceWorkerVersion: async () => swVersion,
  readDeviceState: async () => deviceState,
  writeDeviceState: (s: Record<string, unknown>) => writeDeviceStateMock(s),
}));

type FakeSub = { endpoint: string; options: { applicationServerKey: ArrayBuffer }; toJSON: () => unknown; unsubscribe: ReturnType<typeof vi.fn> };

let counter = 0;
function fakeSub(endpoint = `https://push.example.test/${++counter}`): FakeSub {
  return {
    endpoint,
    options: { applicationServerKey: new Uint8Array([1, 2, 3]).buffer },
    toJSON: () => ({ endpoint, keys: { p256dh: "p", auth: "a" } }),
    unsubscribe: vi.fn().mockResolvedValue(true),
  };
}

let current: FakeSub | null;
let subscribeImpl: () => Promise<FakeSub>;
const pushManager = {
  getSubscription: vi.fn(async () => current),
  subscribe: vi.fn(async () => {
    current = await subscribeImpl();
    return current;
  }),
};

function install(permission: NotificationPermission) {
  Object.defineProperty(window, "PushManager", { value: function () {}, configurable: true, writable: true });
  Object.defineProperty(window, "Notification", { value: { permission }, configurable: true, writable: true });
  Object.defineProperty(navigator, "serviceWorker", {
    value: { ready: Promise.resolve({ pushManager }), controller: { postMessage: vi.fn() } },
    configurable: true,
  });
}

function serverSays(...responses: Array<Record<string, unknown>>) {
  for (const r of responses) {
    apiPostMock.mockResolvedValueOnce({ data: { reason: null, autoRenew: false, deviceToken: null, device: null, ...r } });
  }
}

const syncCalls = () => apiPostMock.mock.calls.filter((c) => c[0] === "/api/push/sync").map((c) => c[1] as Record<string, unknown>);

beforeEach(() => {
  resetReconcileStateForTests();
  apiPostMock.mockReset();
  apiGetMock.mockReset().mockResolvedValue({ data: { publicKey: "QUFB" } });
  pushManager.getSubscription.mockClear();
  pushManager.subscribe.mockClear();
  writeDeviceStateMock.mockClear();
  subscribeImpl = async () => fakeSub();
  current = fakeSub("https://push.example.test/existing");
  deviceState = null;
  swVersion = "2026.10.30-1";
  localStorage.clear();
  install("granted");
});

describe("reconcilePush — cas nominaux", () => {
  it("1. abonnement valide et reconnu : une synchronisation, aucun nouvel abonnement, jeton conservé pour le service worker", async () => {
    serverSays({ state: "OK", deviceToken: "t".repeat(43) });
    const out = await reconcilePush({ trigger: "login", userId: 7 });

    expect(out).toMatchObject({ kind: "synced", health: { state: "OK", renewed: false, renewalPending: null } });
    expect(pushManager.subscribe).not.toHaveBeenCalled();
    expect(syncCalls()).toHaveLength(1);
    expect(syncCalls()[0]).toMatchObject({ endpoint: "https://push.example.test/existing", swVersion: "2026.10.30-1", receipts: true, hasDeviceToken: false });
    expect(deviceState).toMatchObject({ deviceToken: "t".repeat(43), endpoint: "https://push.example.test/existing", applicationServerKey: "AQID" });
  });

  it("annonce le jeton déjà détenu pour CET endpoint et le conserve quand le serveur n'en renvoie pas", async () => {
    deviceState = { apiBase: "", deviceToken: "k".repeat(43), endpoint: "https://push.example.test/existing", applicationServerKey: null };
    serverSays({ state: "OK" });
    await reconcilePush({ trigger: "login", userId: 7 });

    expect(syncCalls()[0]).toMatchObject({ hasDeviceToken: true });
    expect(deviceState).toMatchObject({ deviceToken: "k".repeat(43) });
  });

  it("ancien service worker (version inconnue) : n'annonce aucun accusé", async () => {
    swVersion = null;
    serverSays({ state: "OK" });
    await reconcilePush({ trigger: "login", userId: 7 });
    expect(syncCalls()[0]).toMatchObject({ swVersion: null, receipts: false });
  });

  it("2. aucun abonnement local mais permission accordée : en crée un sans rien demander", async () => {
    current = null;
    serverSays({ state: "REGISTERED" });
    const out = await reconcilePush({ trigger: "login", userId: 7 });

    expect(pushManager.subscribe).toHaveBeenCalledTimes(1);
    expect(out).toMatchObject({ kind: "synced", health: { state: "REGISTERED" } });
  });
});

describe("reconcilePush — renouvellement (3. invalidé 410, 4. remplacé)", () => {
  it("renouvelle sur ordre du serveur : désabonne, réabonne, signale l'endpoint remplacé", async () => {
    const old = current!;
    serverSays({ state: "RENEW_REQUIRED", reason: "INVALIDATED", autoRenew: true }, { state: "RENEWED" });
    const out = await reconcilePush({ trigger: "login", userId: 7 });

    expect(old.unsubscribe).toHaveBeenCalledTimes(1);
    expect(pushManager.subscribe).toHaveBeenCalledTimes(1);
    expect(syncCalls()[1]).toMatchObject({ replacesEndpoint: old.endpoint, hasDeviceToken: false });
    expect(syncCalls()[1].endpoint).not.toBe(old.endpoint);
    expect(out).toMatchObject({ kind: "synced", health: { state: "RENEWED", renewed: true, renewalPending: null } });
  });

  it("renvoyer le même abonnement invalidé n'est pas une réparation : sans ordre automatique, rien n'est touché", async () => {
    serverSays({ state: "RENEW_REQUIRED", reason: "INVALIDATED", autoRenew: false });
    const out = await reconcilePush({ trigger: "login", userId: 7 });

    expect(current!.unsubscribe).not.toHaveBeenCalled();
    expect(pushManager.subscribe).not.toHaveBeenCalled();
    expect(out).toMatchObject({ kind: "synced", health: { state: "RENEW_REQUIRED", renewalPending: "manual" } });
  });

  it("réparation demandée par l'utilisateur : renouvelle même sans ordre automatique, y compris un appareil seulement suspect", async () => {
    serverSays(
      { state: "OK", device: { id: 1, suspected: true } },
      { state: "RENEWED", device: { id: 1, suspected: false } },
    );
    const out = await reconcilePush({ trigger: "repair", userId: 7 });

    expect(pushManager.subscribe).toHaveBeenCalledTimes(1);
    expect(out).toMatchObject({ kind: "synced", health: { renewed: true } });
  });

  it("jamais deux renouvellements dans la même réconciliation, puis fenêtre anti-boucle de 24 h", async () => {
    let t = 1_000_000;
    const now = () => t;
    serverSays({ state: "RENEW_REQUIRED", reason: "INVALIDATED", autoRenew: true }, { state: "RENEW_REQUIRED", reason: "INVALIDATED", autoRenew: true });
    const first = await reconcilePush({ trigger: "login", userId: 7, now });
    expect(first).toMatchObject({ health: { renewalPending: "failed", renewed: false } });
    expect(syncCalls()).toHaveLength(2);
    expect(pushManager.subscribe).toHaveBeenCalledTimes(1);

    t += 60_000;
    serverSays({ state: "RENEW_REQUIRED", reason: "INVALIDATED", autoRenew: true });
    const second = await reconcilePush({ trigger: "login", userId: 7, now });
    expect(second).toMatchObject({ health: { renewalPending: "cooldown" } });
    expect(pushManager.subscribe).toHaveBeenCalledTimes(1);

    t += RENEWAL_COOLDOWN_MS;
    serverSays({ state: "RENEW_REQUIRED", reason: "INVALIDATED", autoRenew: true }, { state: "RENEWED" });
    await reconcilePush({ trigger: "login", userId: 7, now });
    expect(pushManager.subscribe).toHaveBeenCalledTimes(2);
  });

  it("renouvellement interrompu (réabonnement en échec) : l'endpoint abandonné est transmis à la synchronisation suivante", async () => {
    const old = current!;
    subscribeImpl = async () => {
      throw new Error("network");
    };
    serverSays({ state: "RENEW_REQUIRED", reason: "SUSPECTED", autoRenew: true });
    const out = await reconcilePush({ trigger: "login", userId: 7 });
    expect(out).toMatchObject({ health: { renewalPending: "failed" } });

    current = null;
    subscribeImpl = async () => fakeSub("https://push.example.test/fresh");
    serverSays({ state: "RENEWED" });
    await reconcilePush({ trigger: "login", userId: 7 });
    expect(syncCalls().at(-1)).toMatchObject({ endpoint: "https://push.example.test/fresh", replacesEndpoint: old.endpoint });

    serverSays({ state: "OK" });
    await reconcilePush({ trigger: "login", userId: 7 });
    expect(syncCalls().at(-1)).not.toHaveProperty("replacesEndpoint");
  });
});

describe("reconcilePush — endpoint d'un autre compte (D-081 révisé)", () => {
  const foreign = () => ({
    response: { status: 409, data: { code: "PUSH_SUBSCRIPTION_FOREIGN", state: "RENEW_REQUIRED", reason: "FOREIGN", autoRenew: true, deviceToken: null, device: null } },
  });

  it("changement de compte sur le même appareil : refus serveur, abonnement neuf créé sans rien demander, jeton de l'autre compte jamais conservé", async () => {
    const old = current!;
    deviceState = { apiBase: "", deviceToken: "a".repeat(43), endpoint: old.endpoint, applicationServerKey: null };
    apiPostMock.mockRejectedValueOnce(foreign());
    serverSays({ state: "REGISTERED", deviceToken: "b".repeat(43), device: { id: 9 } });

    const out = await reconcilePush({ trigger: "login", userId: 11 });

    expect(old.unsubscribe).toHaveBeenCalledTimes(1);
    expect(pushManager.subscribe).toHaveBeenCalledTimes(1);
    expect(syncCalls()).toHaveLength(2);
    expect(syncCalls()[1].endpoint).not.toBe(old.endpoint);
    expect(writeDeviceStateMock.mock.calls[0][0]).toMatchObject({ deviceToken: null });
    expect(deviceState).toMatchObject({ deviceToken: "b".repeat(43), endpoint: syncCalls()[1].endpoint });
    expect(out).toMatchObject({ kind: "synced", health: { state: "REGISTERED", renewed: true, renewalPending: null } });
  });

  it("n'attend pas la fenêtre de 24 h d'un renouvellement précédent (changement de compte légitime)", async () => {
    let t = 1_000_000;
    const now = () => t;
    serverSays({ state: "RENEW_REQUIRED", reason: "INVALIDATED", autoRenew: true }, { state: "RENEWED" });
    await reconcilePush({ trigger: "login", userId: 7, now });
    expect(pushManager.subscribe).toHaveBeenCalledTimes(1);

    t += 60_000;
    apiPostMock.mockRejectedValueOnce(foreign());
    serverSays({ state: "REGISTERED" });
    const out = await reconcilePush({ trigger: "login", userId: 11, now });
    expect(pushManager.subscribe).toHaveBeenCalledTimes(2);
    expect(out).toMatchObject({ health: { renewed: true } });
  });

  it("aucune boucle : si le navigateur rend le même endpoint, un seul essai, puis plus rien aux réconciliations suivantes", async () => {
    const same = current!.endpoint;
    subscribeImpl = async () => fakeSub(same);
    apiPostMock.mockRejectedValueOnce(foreign()).mockRejectedValueOnce(foreign());
    const first = await reconcilePush({ trigger: "login", userId: 11 });
    expect(first).toMatchObject({ health: { renewalPending: "failed", renewed: false } });
    expect(pushManager.subscribe).toHaveBeenCalledTimes(1);

    apiPostMock.mockRejectedValueOnce(foreign());
    const second = await reconcilePush({ trigger: "login", userId: 11 });
    expect(second).toMatchObject({ health: { renewalPending: "failed" } });
    expect(pushManager.subscribe).toHaveBeenCalledTimes(1);
    expect(syncCalls()).toHaveLength(3);
  });

  it("désactivées volontairement : même un endpoint d'un autre compte ne déclenche aucun réabonnement", async () => {
    setPushOptedOut(true);
    expect(await reconcilePush({ trigger: "login", userId: 11 })).toEqual({ kind: "skipped", why: "opted-out" });
    expect(apiPostMock).not.toHaveBeenCalled();
    expect(pushManager.subscribe).not.toHaveBeenCalled();
  });

  it("toute autre erreur serveur remonte sans toucher à l'abonnement local", async () => {
    apiPostMock.mockRejectedValueOnce({ response: { status: 409, data: { code: "OTHER" } } });
    await expect(reconcilePush({ trigger: "login", userId: 11 })).rejects.toBeTruthy();
    expect(current!.unsubscribe).not.toHaveBeenCalled();
  });
});

describe("reconcilePush — garde-fous", () => {
  it("désactivées volontairement (Mon profil) : aucune réconciliation automatique ne réabonne ; seul « Activer » lève le choix", async () => {
    setPushOptedOut(true);
    current = null;
    for (const trigger of ["login", "visibility", "permission", "repair"] as const) {
      expect(await reconcilePush({ trigger, userId: 7 })).toEqual({ kind: "skipped", why: "opted-out" });
    }
    expect(apiPostMock).not.toHaveBeenCalled();
    expect(pushManager.subscribe).not.toHaveBeenCalled();

    serverSays({ state: "REGISTERED", deviceToken: "t".repeat(43) });
    expect((await reconcilePush({ trigger: "activate", userId: 7 })).kind).toBe("synced");
    expect(isPushOptedOut()).toBe(false);
    expect(pushManager.subscribe).toHaveBeenCalledTimes(1);
  });

  it("5. notifications refusées : aucun appel, aucun abonnement, jamais de demande de permission", async () => {
    install("denied");
    expect(await reconcilePush({ trigger: "login", userId: 7 })).toEqual({ kind: "skipped", why: "permission-denied" });
    install("default");
    expect(await reconcilePush({ trigger: "visibility", userId: 7 })).toEqual({ kind: "skipped", why: "permission-default" });
    expect(apiPostMock).not.toHaveBeenCalled();
    expect(pushManager.subscribe).not.toHaveBeenCalled();
  });

  it("6. ouvertures et retours au premier plan répétés : une seule synchronisation par 6 h au premier plan", async () => {
    let t = 5_000_000;
    const now = () => t;
    serverSays({ state: "OK" });
    await reconcilePush({ trigger: "login", userId: 7, now });
    for (let i = 0; i < 5; i++) {
      t += 60_000;
      expect(await reconcilePush({ trigger: "visibility", userId: 7, now })).toEqual({ kind: "skipped", why: "throttled" });
    }
    t += VISIBILITY_THROTTLE_MS;
    serverSays({ state: "OK" });
    await reconcilePush({ trigger: "visibility", userId: 7, now });
    expect(syncCalls()).toHaveLength(2);
    expect(pushManager.subscribe).not.toHaveBeenCalled();
  });

  it("12. appels concurrents : une seule synchronisation en vol", async () => {
    let release!: (v: unknown) => void;
    apiPostMock.mockImplementationOnce(() => new Promise((r) => (release = r)));
    const a = reconcilePush({ trigger: "login", userId: 7 });
    const b = reconcilePush({ trigger: "activate", userId: 7 });
    await Promise.resolve();
    await new Promise((r) => setTimeout(r, 0));
    release({ data: { state: "OK", reason: null, autoRenew: false, deviceToken: null, device: null } });

    expect(await a).toEqual(await b);
    expect(syncCalls()).toHaveLength(1);
  });

  it("verrou inter-onglets occupé : n'agit pas", async () => {
    Object.defineProperty(navigator, "locks", {
      value: { request: async (_n: string, _o: unknown, cb: (lock: unknown) => Promise<void>) => cb(null) },
      configurable: true,
    });
    try {
      expect(await reconcilePush({ trigger: "login", userId: 7 })).toEqual({ kind: "skipped", why: "busy" });
      expect(apiPostMock).not.toHaveBeenCalled();
    } finally {
      delete (navigator as unknown as Record<string, unknown>).locks;
    }
  });

  it("10. absence de réseau : l'erreur remonte (l'appelant la signale), l'abonnement local est intact", async () => {
    apiPostMock.mockRejectedValueOnce(new Error("Network Error"));
    await expect(reconcilePush({ trigger: "login", userId: 7 })).rejects.toThrow("Network Error");
    expect(current!.unsubscribe).not.toHaveBeenCalled();
    expect(pushManager.subscribe).not.toHaveBeenCalled();

    // Au retour du réseau, la réconciliation suivante n'est pas bloquée.
    serverSays({ state: "OK" });
    expect(await reconcilePush({ trigger: "login", userId: 7 })).toMatchObject({ kind: "synced" });
  });
});
