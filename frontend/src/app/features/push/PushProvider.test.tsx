import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { render, screen, waitFor, act } from "@testing-library/react";
import type { ReactElement } from "react";
import { PushProvider, usePushNotifications } from "./PushProvider";

const apiGetMock = vi.fn();
const apiPostMock = vi.fn();
const apiDeleteMock = vi.fn();

vi.mock("../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
    post: (...args: unknown[]) => apiPostMock(...args),
    delete: (...args: unknown[]) => apiDeleteMock(...args),
  },
}));

const captureExceptionMock = vi.fn();
vi.mock("@sentry/react", () => ({
  captureException: (...args: unknown[]) => captureExceptionMock(...args),
}));

type MockAuthState = { status: "anonymous" } | { status: "authenticated"; user: { id: number } };

let authState: MockAuthState = { status: "authenticated", user: { id: 1 } };
vi.mock("../../auth/AuthContext", () => ({
  useAuth: () => ({ state: authState }),
}));

function authenticatedAs(userId: number): MockAuthState {
  return { status: "authenticated", user: { id: userId } };
}

/* ── Test harness: exposes the hook's return value on window for assertions ── */

function Probe() {
  const value = usePushNotifications();
  (window as any).__push = value;
  return <div data-testid="status">{value.status}</div>;
}

function renderProvider() {
  return render(
    <PushProvider>
      <Probe />
    </PushProvider>,
  );
}

/* ── Browser API stubs ── */

type MockSubscription = {
  endpoint: string;
  toJSON: () => { endpoint: string; keys: { p256dh: string; auth: string } };
  unsubscribe: () => Promise<boolean>;
};

function makeSubscription(endpoint = "https://push.example/abc123"): MockSubscription {
  return {
    endpoint,
    toJSON: () => ({ endpoint, keys: { p256dh: "p256dh-key", auth: "auth-key" } }),
    unsubscribe: vi.fn().mockResolvedValue(true),
  };
}

function installBrowserStubs(opts: {
  supported?: boolean;
  permission?: NotificationPermission;
  /** What Notification.requestPermission() resolves to — defaults to `permission`. */
  requestPermissionResult?: NotificationPermission;
  existingSubscription?: MockSubscription | null;
  registerImpl?: () => Promise<unknown>;
  subscribeImpl?: () => Promise<MockSubscription>;
}) {
  const {
    supported = true,
    permission = "default",
    requestPermissionResult = permission,
    existingSubscription = null,
    registerImpl,
    subscribeImpl,
  } = opts;

  let currentSubscription: MockSubscription | null = existingSubscription;

  const pushManager = {
    getSubscription: vi.fn().mockImplementation(async () => currentSubscription),
    subscribe: vi.fn().mockImplementation(async () => {
      currentSubscription = subscribeImpl ? await subscribeImpl() : makeSubscription();
      return currentSubscription;
    }),
  };

  const registration = { pushManager };

  const register = vi.fn().mockImplementation(registerImpl ?? (async () => registration));
  const getRegistration = vi.fn().mockResolvedValue(registration);

  const serviceWorker = {
    register,
    getRegistration,
    ready: Promise.resolve(registration),
  };

  // `"x" in obj` is true for a defined-but-undefined property, so unsupported browsers
  // must have the property genuinely absent, not merely set to undefined.
  delete (window as any).PushManager;
  delete (navigator as any).serviceWorker;
  delete (window as any).Notification;

  if (supported) {
    Object.defineProperty(window, "PushManager", { value: function () {}, configurable: true, writable: true });
    Object.defineProperty(navigator, "serviceWorker", { value: serviceWorker, configurable: true });

    const requestPermission = vi.fn().mockImplementation(async () => requestPermissionResult);
    Object.defineProperty(window, "Notification", {
      value: Object.assign(function () {}, { permission, requestPermission }),
      configurable: true,
      writable: true,
    });
  }

  const requestPermission = (window as any).Notification?.requestPermission ?? vi.fn();

  return { register, getRegistration, pushManager, requestPermission, getCurrentSubscription: () => currentSubscription };
}

beforeEach(() => {
  apiGetMock.mockReset().mockResolvedValue({ data: { publicKey: "QUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUE" } });
  apiPostMock.mockReset().mockResolvedValue({});
  apiDeleteMock.mockReset().mockResolvedValue({});
  captureExceptionMock.mockReset();
  authState = authenticatedAs(1);
  delete (window as any).__push;
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe("PushProvider — support & initial status", () => {
  it("statut 'unsupported' si serviceWorker/PushManager/Notification absents", () => {
    installBrowserStubs({ supported: false });
    renderProvider();
    expect(screen.getByTestId("status").textContent).toBe("unsupported");
  });

  it("statut 'permission-default' si la permission n'a jamais été demandée", () => {
    installBrowserStubs({ permission: "default" });
    renderProvider();
    expect(screen.getByTestId("status").textContent).toBe("permission-default");
  });

  it("statut 'permission-denied' si la permission a été refusée", () => {
    installBrowserStubs({ permission: "denied" });
    renderProvider();
    expect(screen.getByTestId("status").textContent).toBe("permission-denied");
  });
});

describe("PushProvider — enregistrement du service worker", () => {
  it("enregistre le service worker au montage, sans demander la permission", async () => {
    const { register, requestPermission } = installBrowserStubs({ permission: "default" });
    renderProvider();

    await waitFor(() => expect(register).toHaveBeenCalledWith("/sw.js"));
    expect(requestPermission).not.toHaveBeenCalled();
  });

  it("n'enregistre le service worker qu'une seule fois même après re-render", async () => {
    const { register } = installBrowserStubs({ permission: "default" });
    const { rerender } = renderProvider();
    await waitFor(() => expect(register).toHaveBeenCalledTimes(1));

    rerender(
      <PushProvider>
        <Probe />
      </PushProvider>,
    );
    await new Promise((r) => setTimeout(r, 0));
    expect(register).toHaveBeenCalledTimes(1);
  });
});

describe("PushProvider — auto-resouscription silencieuse (permission déjà accordée)", () => {
  it("réutilise une subscription existante sans en créer une nouvelle", async () => {
    const existing = makeSubscription("https://push.example/existing");
    const { pushManager } = installBrowserStubs({ permission: "granted", existingSubscription: existing });
    renderProvider();

    await waitFor(() => expect(apiPostMock).toHaveBeenCalled());
    expect(pushManager.subscribe).not.toHaveBeenCalled();
    expect(apiPostMock).toHaveBeenCalledWith(
      "/api/push/subscribe",
      expect.objectContaining({ endpoint: "https://push.example/existing" }),
    );
  });

  it("crée une nouvelle subscription si aucune n'existe déjà", async () => {
    const { pushManager } = installBrowserStubs({ permission: "granted", existingSubscription: null });
    renderProvider();

    await waitFor(() => expect(pushManager.subscribe).toHaveBeenCalled());
    await waitFor(() => expect(apiPostMock).toHaveBeenCalled());
  });

  it("ne tente pas d'auto-resouscription tant que l'utilisateur n'est pas authentifié", async () => {
    authState = { status: "anonymous" };
    installBrowserStubs({ permission: "granted" });
    renderProvider();

    await new Promise((r) => setTimeout(r, 0));
    expect(apiPostMock).not.toHaveBeenCalled();
  });
});

describe("PushProvider — changement de compte dans le même onglet (D-081)", () => {
  function rerenderProvider(rerender: (ui: ReactElement) => void) {
    rerender(
      <PushProvider>
        <Probe />
      </PushProvider>,
    );
  }

  it("A → logout → B : deux rattachements distincts, un appel par session, aucun troisième appel parasite", async () => {
    const subA = makeSubscription("https://push.example/shared-device");
    installBrowserStubs({ permission: "granted", existingSubscription: subA });

    authState = authenticatedAs(1);
    const { rerender } = renderProvider();
    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1));
    expect(screen.getByTestId("status").textContent).toBe("subscribed");

    // Logout: same tab, PushProvider never unmounts (mounted above the router).
    authState = { status: "anonymous" };
    await act(async () => {
      rerenderProvider(rerender);
    });
    apiPostMock.mockClear();

    // B logs in on the same device — the browser-level subscription is reused (still
    // the same `subA`, never revoked by logout), but must be re-attached to B server-side.
    authState = authenticatedAs(2);
    await act(async () => {
      rerenderProvider(rerender);
    });
    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1));
    expect(apiPostMock).toHaveBeenCalledWith(
      "/api/push/subscribe",
      expect.objectContaining({ endpoint: "https://push.example/shared-device" }),
    );
    expect(screen.getByTestId("status").textContent).toBe("subscribed");

    // Re-rendering again for the same user B must not fire a third call.
    await act(async () => {
      rerenderProvider(rerender);
    });
    expect(apiPostMock).toHaveBeenCalledTimes(1);
  });

  it("changement direct A → B sans passer par anonymous (isAuthenticated reste true)", async () => {
    const shared = makeSubscription("https://push.example/direct-switch");
    installBrowserStubs({ permission: "granted", existingSubscription: shared });

    authState = authenticatedAs(1);
    const { rerender } = renderProvider();
    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1));

    apiPostMock.mockClear();
    authState = authenticatedAs(2); // isAuthenticated stays true throughout
    await act(async () => {
      rerenderProvider(rerender);
    });

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1));
    expect(screen.getByTestId("status").textContent).toBe("subscribed");
  });

  it("logout simple : le statut ne reste pas trompeusement hérité de l'utilisateur précédent", async () => {
    installBrowserStubs({ permission: "granted", existingSubscription: makeSubscription() });

    authState = authenticatedAs(1);
    const { rerender } = renderProvider();
    await waitFor(() => expect(screen.getByTestId("status").textContent).toBe("subscribed"));

    authState = { status: "anonymous" };
    await act(async () => {
      rerenderProvider(rerender);
    });

    // Recomputed fresh from the browser's actual state (refreshStatus()), not left over
    // from A — the browser-level subscription is still genuinely present (logout never
    // revokes it), so "subscribed" here reflects a real re-check, not a stale value.
    await waitFor(() => expect(screen.getByTestId("status").textContent).toBe("subscribed"));
  });

  it("pas de double réabonnement pour la même session utilisateur (re-render répété)", async () => {
    installBrowserStubs({ permission: "granted", existingSubscription: makeSubscription() });
    authState = authenticatedAs(1);
    const { rerender } = renderProvider();
    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1));

    for (let i = 0; i < 3; i++) {
      await act(async () => {
        rerenderProvider(rerender);
      });
    }

    expect(apiPostMock).toHaveBeenCalledTimes(1);
  });

  it("nouvelle session avec permission 'default' : aucune demande de permission, aucun abonnement, statut correct", async () => {
    installBrowserStubs({ permission: "default" });
    authState = { status: "anonymous" };
    const { rerender } = renderProvider();

    const { requestPermission } = installBrowserStubs({ permission: "default" });
    authState = authenticatedAs(3);
    await act(async () => {
      rerenderProvider(rerender);
    });

    expect(requestPermission).not.toHaveBeenCalled();
    expect(apiPostMock).not.toHaveBeenCalled();
    expect(screen.getByTestId("status").textContent).toBe("permission-default");
  });

  it("nouvelle session avec permission 'denied' : aucun abonnement, aucun Sentry technique, statut correct", async () => {
    installBrowserStubs({ permission: "denied" });
    authState = { status: "anonymous" };
    const { rerender } = renderProvider();

    authState = authenticatedAs(4);
    await act(async () => {
      rerenderProvider(rerender);
    });

    expect(apiPostMock).not.toHaveBeenCalled();
    expect(captureExceptionMock).not.toHaveBeenCalled();
    expect(screen.getByTestId("status").textContent).toBe("permission-denied");
  });
});

describe("PushProvider — subscribe()", () => {
  it("demande la permission uniquement sur action explicite (subscribe()), jamais au montage", async () => {
    const { requestPermission } = installBrowserStubs({ permission: "default" });
    renderProvider();
    await new Promise((r) => setTimeout(r, 0));
    expect(requestPermission).not.toHaveBeenCalled();

    await act(async () => {
      await (window as any).__push.subscribe();
    });
    expect(requestPermission).toHaveBeenCalledTimes(1);
  });

  it("passe à 'subscribed' et appelle le backend quand la permission est accordée", async () => {
    installBrowserStubs({ permission: "granted" });
    renderProvider();

    await act(async () => {
      await (window as any).__push.subscribe();
    });

    expect(screen.getByTestId("status").textContent).toBe("subscribed");
    expect(apiPostMock).toHaveBeenCalledWith("/api/push/subscribe", expect.objectContaining({
      endpoint: expect.any(String),
      keys: { p256dh: "p256dh-key", auth: "auth-key" },
    }));
  });

  it("passe à 'permission-denied' sans jamais appeler le backend si l'utilisateur refuse", async () => {
    installBrowserStubs({ permission: "denied" });
    renderProvider();

    await act(async () => {
      await (window as any).__push.subscribe();
    });

    expect(screen.getByTestId("status").textContent).toBe("permission-denied");
    expect(apiPostMock).not.toHaveBeenCalled();
    // A normal user decline is expected, everyday behavior — never reported to Sentry.
    expect(captureExceptionMock).not.toHaveBeenCalled();
  });

  it("passe à 'error' et journalise via Sentry si l'abonnement backend échoue", async () => {
    // Ambient permission stays "default" so the auto-resubscribe effect (triggered only
    // when already "granted") doesn't consume the rejection before subscribe() runs.
    installBrowserStubs({ permission: "default", requestPermissionResult: "granted" });
    renderProvider();
    await new Promise((r) => setTimeout(r, 0));
    apiPostMock.mockRejectedValueOnce(new Error("network down"));

    await act(async () => {
      await (window as any).__push.subscribe();
    });

    expect(screen.getByTestId("status").textContent).toBe("error");
    expect((window as any).__push.lastError).toBe("subscribe-failed");
    expect(captureExceptionMock).toHaveBeenCalledTimes(1);
  });
});

describe("PushProvider — unsubscribe() (désactivation complète sur cet appareil)", () => {
  it("appelle le backend puis révoque la subscription navigateur", async () => {
    const existing = makeSubscription("https://push.example/to-remove");
    installBrowserStubs({ permission: "granted", existingSubscription: existing });
    renderProvider();
    await waitFor(() => expect(apiPostMock).toHaveBeenCalled()); // auto-resubscribe settles first

    await act(async () => {
      await (window as any).__push.unsubscribe();
    });

    expect(apiDeleteMock).toHaveBeenCalledWith(
      "/api/push/unsubscribe",
      expect.objectContaining({ data: { endpoint: "https://push.example/to-remove" } }),
    );
    expect(existing.unsubscribe).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId("status").textContent).toBe("permission-default");
  });

  it("ne fait rien si aucune subscription n'existe (idempotent)", async () => {
    installBrowserStubs({ permission: "default", existingSubscription: null });
    renderProvider();

    await act(async () => {
      await (window as any).__push.unsubscribe();
    });

    expect(apiDeleteMock).not.toHaveBeenCalled();
  });
});

describe("PushProvider — disponible dans n'importe quel shell (mobile ou desktop)", () => {
  it("le hook fonctionne pour n'importe quel consommateur monté sous le même provider", () => {
    installBrowserStubs({ permission: "default" });
    render(
      <PushProvider>
        <Probe />
        <Probe />
      </PushProvider>,
    );
    const statuses = screen.getAllByTestId("status");
    expect(statuses).toHaveLength(2);
    expect(statuses[0].textContent).toBe(statuses[1].textContent);
  });
});
