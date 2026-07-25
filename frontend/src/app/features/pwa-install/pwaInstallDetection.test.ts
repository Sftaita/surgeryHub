import { describe, it, expect, afterEach } from "vitest";
import { detectPlatform, isAndroid, isIos, isStandalone } from "./pwaInstallDetection";

const IPHONE_UA = "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15";
const ANDROID_UA = "Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/125.0";
const DESKTOP_UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/125.0";

function setUserAgent(ua: string) {
  Object.defineProperty(window.navigator, "userAgent", { value: ua, configurable: true });
}

function setPlatform(platform: string) {
  Object.defineProperty(window.navigator, "platform", { value: platform, configurable: true });
}

function setMaxTouchPoints(n: number) {
  Object.defineProperty(window.navigator, "maxTouchPoints", { value: n, configurable: true });
}

afterEach(() => {
  setUserAgent(DESKTOP_UA);
  setPlatform("Win32");
  setMaxTouchPoints(0);
  Object.defineProperty(window, "matchMedia", {
    value: () => ({ matches: false, addEventListener: () => {}, removeEventListener: () => {} }),
    configurable: true,
  });
  delete (window.navigator as any).standalone;
});

describe("détection — plateforme", () => {
  it("reconnaît iPhone comme iOS", () => {
    setUserAgent(IPHONE_UA);
    expect(isIos()).toBe(true);
    expect(detectPlatform()).toBe("ios");
  });

  it("reconnaît un iPad (UA Mac + tactile) comme iOS", () => {
    setUserAgent("Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_6) AppleWebKit/605.1.15");
    setPlatform("MacIntel");
    setMaxTouchPoints(5);
    expect(isIos()).toBe(true);
  });

  it("ne reconnaît pas un vrai Mac (pas tactile) comme iOS", () => {
    setUserAgent("Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_6) AppleWebKit/605.1.15");
    setPlatform("MacIntel");
    setMaxTouchPoints(0);
    expect(isIos()).toBe(false);
  });

  it("reconnaît Android", () => {
    setUserAgent(ANDROID_UA);
    expect(isAndroid()).toBe(true);
    expect(detectPlatform()).toBe("android");
  });

  it("desktop classique → plateforme 'other'", () => {
    setUserAgent(DESKTOP_UA);
    expect(isIos()).toBe(false);
    expect(isAndroid()).toBe(false);
    expect(detectPlatform()).toBe("other");
  });
});

describe("détection — standalone (déjà installé)", () => {
  it("display-mode: standalone → true (Android/desktop)", () => {
    Object.defineProperty(window, "matchMedia", {
      value: (q: string) => ({ matches: q === "(display-mode: standalone)", addEventListener: () => {}, removeEventListener: () => {} }),
      configurable: true,
    });
    expect(isStandalone()).toBe(true);
  });

  it("navigator.standalone === true → true (iOS)", () => {
    Object.defineProperty(window.navigator, "standalone", { value: true, configurable: true });
    expect(isStandalone()).toBe(true);
  });

  it("ni l'un ni l'autre → false (navigateur classique)", () => {
    expect(isStandalone()).toBe(false);
  });
});
