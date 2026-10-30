import { describe, it, expect, beforeEach, vi } from "vitest";
import { detachCurrentPushSubscription, unsubscribeFromPush } from "./pushSubscriptionClient";
import { isPushOptedOut } from "./pushDeviceState";

const axiosDeleteMock = vi.fn();
vi.mock("axios", () => ({
  default: { delete: (...args: unknown[]) => axiosDeleteMock(...args) },
}));

const apiDeleteMock = vi.fn();
vi.mock("../../api/apiClient", () => ({
  apiClient: { get: vi.fn(), post: vi.fn(), delete: (...args: unknown[]) => apiDeleteMock(...args) },
}));

const clearDeviceStateMock = vi.fn(async () => {});
vi.mock("./pushDeviceState", async (importOriginal) => ({
  ...(await importOriginal<typeof import("./pushDeviceState")>()),
  clearDeviceState: () => clearDeviceStateMock(),
}));

function makeSubscription(endpoint: string) {
  return { endpoint, toJSON: () => ({ endpoint, keys: { p256dh: "p", auth: "a" } }) };
}

beforeEach(() => {
  axiosDeleteMock.mockReset().mockResolvedValue({});
  apiDeleteMock.mockReset().mockResolvedValue({});
  clearDeviceStateMock.mockClear();
  localStorage.clear();
  Object.defineProperty(window, "Notification", { value: function () {}, configurable: true });
});

describe("detachCurrentPushSubscription", () => {
  it("ne fait rien si aucun token n'est fourni (utilisateur jamais réellement connecté)", async () => {
    await detachCurrentPushSubscription(null);
    expect(axiosDeleteMock).not.toHaveBeenCalled();
  });

  it("ne fait rien si le navigateur ne supporte pas le push (pas de serviceWorker)", async () => {
    Object.defineProperty(navigator, "serviceWorker", { value: undefined, configurable: true });
    await detachCurrentPushSubscription("token-abc");
    expect(axiosDeleteMock).not.toHaveBeenCalled();
  });

  it("ne fait rien si aucune subscription navigateur n'existe", async () => {
    Object.defineProperty(window, "PushManager", { value: function () {}, configurable: true });
    Object.defineProperty(navigator, "serviceWorker", {
      value: { getRegistration: vi.fn().mockResolvedValue(undefined) },
      configurable: true,
    });
    await detachCurrentPushSubscription("token-abc");
    expect(axiosDeleteMock).not.toHaveBeenCalled();
  });

  it("envoie le token capturé explicitement (pas via l'intercepteur apiClient) avec l'endpoint courant", async () => {
    const subscription = makeSubscription("https://push.example/xyz");
    Object.defineProperty(window, "PushManager", { value: function () {}, configurable: true });
    Object.defineProperty(navigator, "serviceWorker", {
      value: {
        getRegistration: vi.fn().mockResolvedValue({
          pushManager: { getSubscription: vi.fn().mockResolvedValue(subscription) },
        }),
      },
      configurable: true,
    });

    await detachCurrentPushSubscription("captured-token");

    expect(axiosDeleteMock).toHaveBeenCalledWith(
      expect.stringContaining("/api/push/unsubscribe"),
      expect.objectContaining({
        headers: { Authorization: "Bearer captured-token" },
        data: { endpoint: "https://push.example/xyz" },
      }),
    );
  });

  it("n'échoue jamais même si l'appel réseau échoue (best-effort, ne doit jamais bloquer le logout)", async () => {
    const subscription = makeSubscription("https://push.example/xyz");
    Object.defineProperty(window, "PushManager", { value: function () {}, configurable: true });
    Object.defineProperty(navigator, "serviceWorker", {
      value: {
        getRegistration: vi.fn().mockResolvedValue({
          pushManager: { getSubscription: vi.fn().mockResolvedValue(subscription) },
        }),
      },
      configurable: true,
    });
    axiosDeleteMock.mockRejectedValueOnce(new Error("network down"));

    await expect(detachCurrentPushSubscription("captured-token")).resolves.toBeUndefined();
  });
});

describe("D-175 — révocation du jeton d'appareil", () => {
  function withSubscription(subscription: unknown) {
    Object.defineProperty(window, "PushManager", { value: function () {}, configurable: true });
    Object.defineProperty(navigator, "serviceWorker", {
      value: { getRegistration: vi.fn().mockResolvedValue({ pushManager: { getSubscription: vi.fn().mockResolvedValue(subscription) } }) },
      configurable: true,
    });
  }

  it("déconnexion : le jeton d'appareil est effacé, que le détachement serveur réussisse ou non", async () => {
    withSubscription(makeSubscription("https://push.example/xyz"));
    await detachCurrentPushSubscription("captured-token");
    axiosDeleteMock.mockRejectedValueOnce(new Error("network down"));
    await detachCurrentPushSubscription("captured-token");
    await detachCurrentPushSubscription(null);
    expect(clearDeviceStateMock).toHaveBeenCalledTimes(3);
  });

  it("désactivation volontaire : détache côté serveur, mémorise le choix, efface le jeton, puis désabonne le navigateur", async () => {
    const unsubscribe = vi.fn().mockResolvedValue(true);
    withSubscription({ ...makeSubscription("https://push.example/xyz"), unsubscribe });
    await unsubscribeFromPush();
    expect(apiDeleteMock).toHaveBeenCalledWith("/api/push/unsubscribe", { data: { endpoint: "https://push.example/xyz" } });
    expect(isPushOptedOut()).toBe(true);
    expect(clearDeviceStateMock).toHaveBeenCalledTimes(1);
    expect(unsubscribe).toHaveBeenCalledTimes(1);
  });

  it("désactivation refusée par le serveur : rien n'est mémorisé (l'appareil reste abonné et le dit)", async () => {
    withSubscription({ ...makeSubscription("https://push.example/xyz"), unsubscribe: vi.fn() });
    apiDeleteMock.mockRejectedValueOnce(new Error("500"));
    await expect(unsubscribeFromPush()).rejects.toThrow();
    expect(isPushOptedOut()).toBe(false);
  });
});
