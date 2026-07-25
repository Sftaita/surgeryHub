import { describe, it, expect, beforeEach } from "vitest";
import {
  clearDismissalState,
  getDismissCount,
  hasCompletedGuide,
  recordDismissal,
  recordGuideCompleted,
  shouldShowAutomatically,
} from "./pwaInstallStorage";

const DAY_MS = 24 * 60 * 60 * 1000;

beforeEach(() => {
  window.localStorage.clear();
});

describe("politique de report — §6", () => {
  it("aucun report : affichage automatique autorisé", () => {
    expect(shouldShowAutomatically()).toBe(true);
    expect(getDismissCount()).toBe(0);
  });

  it("1er report : ne reproposer qu'après 7 jours", () => {
    const t0 = Date.now();
    recordDismissal(t0);
    expect(getDismissCount()).toBe(1);

    expect(shouldShowAutomatically(t0 + 6 * DAY_MS)).toBe(false);
    expect(shouldShowAutomatically(t0 + 7 * DAY_MS)).toBe(true);
  });

  it("2e report : ne reproposer qu'après 14 jours", () => {
    const t0 = Date.now();
    recordDismissal(t0);
    recordDismissal(t0 + 7 * DAY_MS);
    expect(getDismissCount()).toBe(2);

    expect(shouldShowAutomatically(t0 + 7 * DAY_MS + 13 * DAY_MS)).toBe(false);
    expect(shouldShowAutomatically(t0 + 7 * DAY_MS + 14 * DAY_MS)).toBe(true);
  });

  it("3e report et suivants : plus jamais d'affichage automatique", () => {
    const t0 = Date.now();
    recordDismissal(t0);
    recordDismissal(t0 + 7 * DAY_MS);
    recordDismissal(t0 + 21 * DAY_MS);
    expect(getDismissCount()).toBe(3);

    expect(shouldShowAutomatically(t0 + 1000 * DAY_MS)).toBe(false);

    recordDismissal(t0 + 30 * DAY_MS);
    expect(shouldShowAutomatically(t0 + 5000 * DAY_MS)).toBe(false);
  });

  it("« J'ai compris » complet appliqué séparément, ne débloque jamais l'affichage automatique à lui seul", () => {
    recordGuideCompleted();
    expect(hasCompletedGuide()).toBe(true);
    // Sans recordDismissal(), le compteur de report reste à 0 — l'appelant
    // (PwaInstallProvider::closeIosGuide) applique toujours les deux.
    expect(getDismissCount()).toBe(0);
  });

  it("appinstalled efface tout l'état de report", () => {
    recordDismissal();
    recordGuideCompleted();
    clearDismissalState();

    expect(getDismissCount()).toBe(0);
    expect(hasCompletedGuide()).toBe(false);
    expect(shouldShowAutomatically()).toBe(true);
  });

  it("état stocké par navigateur (localStorage, jamais partagé entre origines/onglets d'un autre profil)", () => {
    recordDismissal();
    expect(window.localStorage.getItem("surgicalhub.pwaInstall.dismissCount")).toBe("1");
    expect(window.localStorage.getItem("surgicalhub.pwaInstall.dismissedAt")).not.toBeNull();
  });
});
