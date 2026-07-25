import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { render, screen, waitFor, act } from "@testing-library/react";
import { PwaInstallProvider, usePwaInstall } from "./PwaInstallProvider";

const captureExceptionMock = vi.fn();
vi.mock("@sentry/react", () => ({
  captureException: (...args: unknown[]) => captureExceptionMock(...args),
}));

type MockAuthState = { status: "anonymous" } | { status: "authenticated"; user: { id: number } };
let authState: MockAuthState = { status: "authenticated", user: { id: 1 } };
vi.mock("../../auth/AuthContext", () => ({
  useAuth: () => ({ state: authState }),
}));

const ANDROID_UA = "Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/125.0";
const IPHONE_UA = "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15";
const DESKTOP_UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/125.0";

function setUserAgent(ua: string) {
  Object.defineProperty(window.navigator, "userAgent", { value: ua, configurable: true });
}

function setStandalone(standalone: boolean) {
  Object.defineProperty(window, "matchMedia", {
    value: (q: string) => ({ matches: standalone && q === "(display-mode: standalone)", addEventListener: () => {}, removeEventListener: () => {} }),
    configurable: true,
  });
}

function makeBeforeInstallPromptEvent(userChoiceOutcome: "accepted" | "dismissed" = "accepted") {
  const promptMock = vi.fn().mockResolvedValue(undefined);
  const event = new Event("beforeinstallprompt", { cancelable: true }) as any;
  event.prompt = promptMock;
  event.userChoice = Promise.resolve({ outcome: userChoiceOutcome, platform: "web" });
  return { event, promptMock };
}

function Probe() {
  const value = usePwaInstall();
  (window as any).__pwaInstall = value;
  return <div data-testid="status">{value.status}</div>;
}

function renderProvider() {
  return render(
    <PwaInstallProvider>
      <Probe />
    </PwaInstallProvider>,
  );
}

beforeEach(() => {
  window.localStorage.clear();
  captureExceptionMock.mockReset();
  authState = { status: "authenticated", user: { id: 1 } };
  setUserAgent(DESKTOP_UA);
  setStandalone(false);
  delete (window.navigator as any).standalone;
  delete (window as any).__pwaInstall;
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe("PwaInstallProvider — détection initiale", () => {
  it("standalone → statut 'installed'", () => {
    setStandalone(true);
    renderProvider();
    expect(screen.getByTestId("status").textContent).toBe("installed");
  });

  it("iOS non standalone → statut 'ios-installable'", () => {
    setUserAgent(IPHONE_UA);
    renderProvider();
    expect(screen.getByTestId("status").textContent).toBe("ios-installable");
  });

  it("desktop/Android sans beforeinstallprompt reçu → 'not-installable'", () => {
    renderProvider();
    expect(screen.getByTestId("status").textContent).toBe("not-installable");
  });
});

describe("PwaInstallProvider — Android / beforeinstallprompt", () => {
  it("capture l'événement sans déclencher de prompt automatique", async () => {
    setUserAgent(ANDROID_UA);
    renderProvider();
    const { event, promptMock } = makeBeforeInstallPromptEvent();

    await act(async () => {
      window.dispatchEvent(event);
    });

    await waitFor(() => expect(screen.getByTestId("status").textContent).toBe("android-installable"));
    expect(promptMock).not.toHaveBeenCalled();
  });

  it("clic Installer appelle prompt() puis attend userChoice — résultat 'accepted'", async () => {
    setUserAgent(ANDROID_UA);
    renderProvider();
    const { event, promptMock } = makeBeforeInstallPromptEvent("accepted");
    await act(async () => { window.dispatchEvent(event); });
    await waitFor(() => expect(screen.getByTestId("status").textContent).toBe("android-installable"));

    let outcome: string | undefined;
    await act(async () => {
      outcome = await (window as any).__pwaInstall.promptAndroidInstall();
    });

    expect(promptMock).toHaveBeenCalledTimes(1);
    expect(outcome).toBe("accepted");
  });

  it("résultat 'dismissed' : l'événement redevient indisponible (usage unique)", async () => {
    setUserAgent(ANDROID_UA);
    renderProvider();
    const { event } = makeBeforeInstallPromptEvent("dismissed");
    await act(async () => { window.dispatchEvent(event); });
    await waitFor(() => expect(screen.getByTestId("status").textContent).toBe("android-installable"));

    let outcome: string | undefined;
    await act(async () => {
      outcome = await (window as any).__pwaInstall.promptAndroidInstall();
    });
    expect(outcome).toBe("dismissed");

    // Un second appel sans nouvel évènement doit être "unavailable" — la référence a été supprimée.
    let secondOutcome: string | undefined;
    await act(async () => {
      secondOutcome = await (window as any).__pwaInstall.promptAndroidInstall();
    });
    expect(secondOutcome).toBe("unavailable");
  });

  it("appelle prompt() une seule fois même sur double clic (référence supprimée après usage)", async () => {
    setUserAgent(ANDROID_UA);
    renderProvider();
    const { event, promptMock } = makeBeforeInstallPromptEvent("accepted");
    await act(async () => { window.dispatchEvent(event); });
    await waitFor(() => expect(screen.getByTestId("status").textContent).toBe("android-installable"));

    await act(async () => {
      await (window as any).__pwaInstall.promptAndroidInstall();
      await (window as any).__pwaInstall.promptAndroidInstall();
    });

    expect(promptMock).toHaveBeenCalledTimes(1);
  });

  it("appinstalled ferme tout, marque installé, efface le report", async () => {
    setUserAgent(ANDROID_UA);
    renderProvider();
    const { event } = makeBeforeInstallPromptEvent();
    await act(async () => { window.dispatchEvent(event); });
    await waitFor(() => expect(screen.getByTestId("status").textContent).toBe("android-installable"));

    await act(async () => {
      window.dispatchEvent(new Event("appinstalled"));
    });

    expect(screen.getByTestId("status").textContent).toBe("installed");
    expect((window as any).__pwaInstall.canPromptAndroid).toBe(false);
    expect(window.localStorage.getItem("surgicalhub.pwaInstall.dismissCount")).toBeNull();
  });

  it("erreur pendant prompt() : capturée par Sentry, ne bloque pas l'appelant", async () => {
    setUserAgent(ANDROID_UA);
    renderProvider();
    const event = new Event("beforeinstallprompt", { cancelable: true }) as any;
    event.prompt = vi.fn().mockRejectedValue(new Error("boom"));
    // prompt() rejette avant que le code ne touche userChoice — jamais lu ici, donc pas
    // besoin (et pas souhaitable) d'en faire une promesse rejetée jamais awaited.
    event.userChoice = Promise.resolve({ outcome: "dismissed", platform: "web" });
    await act(async () => { window.dispatchEvent(event); });
    await waitFor(() => expect(screen.getByTestId("status").textContent).toBe("android-installable"));

    let outcome: string | undefined;
    await act(async () => {
      outcome = await (window as any).__pwaInstall.promptAndroidInstall();
    });

    expect(outcome).toBe("error");
    expect(captureExceptionMock).toHaveBeenCalledTimes(1);
  });
});

describe("PwaInstallProvider — bannière et report", () => {
  it("showBanner faux si non authentifié (jamais sur l'écran de login)", async () => {
    authState = { status: "anonymous" };
    setUserAgent(ANDROID_UA);
    renderProvider();
    const { event } = makeBeforeInstallPromptEvent();
    await act(async () => { window.dispatchEvent(event); });

    await waitFor(() => expect((window as any).__pwaInstall.canPromptAndroid).toBe(true));
    expect((window as any).__pwaInstall.showBanner).toBe(false);
  });

  it("dismissBanner applique la politique de report — statut 'dismissed' avant le délai", async () => {
    setUserAgent(ANDROID_UA);
    renderProvider();
    const { event } = makeBeforeInstallPromptEvent();
    await act(async () => { window.dispatchEvent(event); });
    await waitFor(() => expect(screen.getByTestId("status").textContent).toBe("android-installable"));

    await act(async () => {
      (window as any).__pwaInstall.dismissBanner();
    });

    expect(screen.getByTestId("status").textContent).toBe("dismissed");
    expect((window as any).__pwaInstall.showBanner).toBe(false);
  });
});
