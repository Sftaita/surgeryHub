import { describe, it, expect, beforeEach, vi } from "vitest";
import { detachCurrentPushSubscription } from "./pushSubscriptionClient";

const axiosDeleteMock = vi.fn();
vi.mock("axios", () => ({
  default: { delete: (...args: unknown[]) => axiosDeleteMock(...args) },
}));

vi.mock("../../api/apiClient", () => ({
  apiClient: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}));

function makeSubscription(endpoint: string) {
  return { endpoint, toJSON: () => ({ endpoint, keys: { p256dh: "p", auth: "a" } }) };
}

beforeEach(() => {
  axiosDeleteMock.mockReset().mockResolvedValue({});
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
