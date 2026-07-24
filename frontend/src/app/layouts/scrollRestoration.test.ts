import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { renderHook, act } from "@testing-library/react";
import {
  ENCODING_FALLBACK_ROUTE,
  getEncodingBackTarget,
  recordEncodingOrigin,
  resetScrollRestoration,
  useRouteScrollRestoration,
} from "./scrollRestoration";

function setViewport({ scrollHeight, innerHeight }: { scrollHeight: number; innerHeight: number }) {
  Object.defineProperty(document.documentElement, "scrollHeight", { value: scrollHeight, configurable: true });
  Object.defineProperty(window, "innerHeight", { value: innerHeight, configurable: true });
}

/**
 * jsdom ne fait pas de mise en page réelle : `window.scrollTo()` n'y met jamais
 * vraiment à jour `window.scrollY` (toujours borné à 0 en pratique), et un appel
 * programmatique n'y déclenche pas non plus d'événement "scroll" comme dans un vrai
 * navigateur. On fait tenir les deux manuellement pour exercer le hook fidèlement :
 * le mock ci-dessous fait office de "vrai navigateur" pour scrollTo, et
 * userScrollsTo() simule un utilisateur qui scrolle réellement la page.
 */
function mockScrollTo() {
  let y = 0;
  Object.defineProperty(window, "scrollY", { get: () => y, configurable: true });
  vi.spyOn(window, "scrollTo").mockImplementation(((...args: unknown[]) => {
    const next = typeof args[0] === "object" && args[0] !== null ? (args[0] as ScrollToOptions).top ?? 0 : (args[1] as number) ?? 0;
    y = next as number;
  }) as typeof window.scrollTo);
}

function userScrollsTo(yValue: number) {
  window.scrollTo(0, yValue);
  window.dispatchEvent(new Event("scroll"));
}

/** Fait avancer les rAF successifs planifiés par useRouteScrollRestoration (RESTORE_FRAMES = 3). */
function flushRestoreFrames() {
  act(() => {
    for (let i = 0; i < 4; i += 1) vi.advanceTimersByTime(16);
  });
}

describe("scrollRestoration", () => {
  beforeEach(() => {
    resetScrollRestoration();
    mockScrollTo();
    vi.useFakeTimers({ toFake: ["requestAnimationFrame", "cancelAnimationFrame"] });
    setViewport({ scrollHeight: 2000, innerHeight: 800 });
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  describe("useRouteScrollRestoration", () => {
    it("première ouverture d'une route → scroll en haut", () => {
      renderHook(() => useRouteScrollRestoration("/app/i/today"));
      expect(window.scrollY).toBe(0);
    });

    it("passage d'une route scrollée vers une route jamais visitée → scroll en haut, pas d'héritage", () => {
      const { rerender, unmount } = renderHook(({ path }) => useRouteScrollRestoration(path), {
        initialProps: { path: "/app/i/offers" },
      });
      act(() => userScrollsTo(450));
      // le listener de scroll est throttlé via rAF
      act(() => vi.advanceTimersByTime(16));

      rerender({ path: "/app/i/planning" });
      expect(window.scrollY).toBe(0);
      unmount();
    });

    it("retour vers une route déjà visitée → position restaurée", () => {
      const { rerender, unmount } = renderHook(({ path }) => useRouteScrollRestoration(path), {
        initialProps: { path: "/app/i/offers" },
      });
      act(() => userScrollsTo(450));
      act(() => vi.advanceTimersByTime(16));

      rerender({ path: "/app/i/planning" });
      expect(window.scrollY).toBe(0);

      rerender({ path: "/app/i/offers" });
      flushRestoreFrames();
      expect(window.scrollY).toBe(450);
      unmount();
    });

    it("positions distinctes entre deux routes, jamais mélangées", () => {
      const { rerender, unmount } = renderHook(({ path }) => useRouteScrollRestoration(path), {
        initialProps: { path: "/app/i/offers" },
      });
      act(() => userScrollsTo(300));
      act(() => vi.advanceTimersByTime(16));

      rerender({ path: "/app/i/planning" });
      act(() => userScrollsTo(900));
      act(() => vi.advanceTimersByTime(16));

      rerender({ path: "/app/i/offers" });
      flushRestoreFrames();
      expect(window.scrollY).toBe(300);

      rerender({ path: "/app/i/planning" });
      flushRestoreFrames();
      expect(window.scrollY).toBe(900);
      unmount();
    });

    it("position mémorisée supérieure à la hauteur disponible → bornée au scroll maximum réel", () => {
      const { rerender, unmount } = renderHook(({ path }) => useRouteScrollRestoration(path), {
        initialProps: { path: "/app/i/offers" },
      });
      act(() => userScrollsTo(1800));
      act(() => vi.advanceTimersByTime(16));

      rerender({ path: "/app/i/planning" });
      expect(window.scrollY).toBe(0);

      // Le contenu a changé depuis la dernière visite : moins de hauteur disponible désormais.
      setViewport({ scrollHeight: 900, innerHeight: 800 });
      rerender({ path: "/app/i/offers" });
      flushRestoreFrames();
      expect(window.scrollY).toBe(100); // max réel = 900 - 800
      unmount();
    });

    it("réinitialisation au montage initial de l'espace instrumentiste → toutes les positions repartent à zéro", () => {
      const { rerender, unmount } = renderHook(({ path }) => useRouteScrollRestoration(path), {
        initialProps: { path: "/app/i/offers" },
      });
      act(() => userScrollsTo(500));
      act(() => vi.advanceTimersByTime(16));
      rerender({ path: "/app/i/planning" });

      // Équivalent d'une nouvelle connexion : MobileLayout remonte et appelle resetScrollRestoration().
      resetScrollRestoration();

      rerender({ path: "/app/i/offers" });
      // La route est de nouveau inconnue après le reset : premier passage → haut de page,
      // jamais la position 500 mémorisée avant la réinitialisation.
      expect(window.scrollY).toBe(0);
      unmount();
    });

    it("un simple changement de route interne ne réinitialise rien (pas d'appel à resetScrollRestoration)", () => {
      const { rerender, unmount } = renderHook(({ path }) => useRouteScrollRestoration(path), {
        initialProps: { path: "/app/i/offers" },
      });
      act(() => userScrollsTo(500));
      act(() => vi.advanceTimersByTime(16));

      rerender({ path: "/app/i/planning" });
      rerender({ path: "/app/i/offers" });
      flushRestoreFrames();
      expect(window.scrollY).toBe(500);
      unmount();
    });
  });

  describe("origine de l'écran d'encodage", () => {
    it("sans origine mémorisée → route de secours /app/i/today", () => {
      expect(getEncodingBackTarget()).toBe(ENCODING_FALLBACK_ROUTE);
    });

    it("avec une origine mémorisée → cette route exacte", () => {
      recordEncodingOrigin("/app/i/offers");
      expect(getEncodingBackTarget()).toBe("/app/i/offers");
    });

    it("resetScrollRestoration() efface l'origine mémorisée", () => {
      recordEncodingOrigin("/app/i/planning");
      resetScrollRestoration();
      expect(getEncodingBackTarget()).toBe(ENCODING_FALLBACK_ROUTE);
    });
  });
});
