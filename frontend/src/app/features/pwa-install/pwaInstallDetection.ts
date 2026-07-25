import type { PwaInstallPlatform } from "./pwaInstall.types";

/**
 * Source de vérité unique de l'état "déjà installé" — jamais dérivée de localStorage
 * ("J'ai compris" ne veut pas dire installé, voir pwaInstallStorage.ts). Couvre desktop
 * et Android via `display-mode`, iOS via `navigator.standalone` (jamais reconnu par
 * `matchMedia` sur Safari/WebKit).
 */
export function isStandalone(): boolean {
  if (typeof window === "undefined") return false;
  const displayModeStandalone = window.matchMedia?.("(display-mode: standalone)").matches ?? false;
  const iosStandalone = (window.navigator as Navigator & { standalone?: boolean }).standalone === true;
  return displayModeStandalone || iosStandalone;
}

/**
 * iPadOS 13+ usurpe l'UA desktop Mac ("MacIntel") mais reste un écran tactile sans
 * souris — `maxTouchPoints > 1` est le signal standard pour le distinguer d'un vrai Mac.
 */
export function isIos(): boolean {
  if (typeof window === "undefined") return false;
  const ua = window.navigator.userAgent;
  const isIpadOs = window.navigator.platform === "MacIntel" && (window.navigator.maxTouchPoints ?? 0) > 1;
  return /iPad|iPhone|iPod/.test(ua) || isIpadOs;
}

export function isAndroid(): boolean {
  if (typeof window === "undefined") return false;
  return /Android/.test(window.navigator.userAgent);
}

export function detectPlatform(): PwaInstallPlatform {
  if (isIos()) return "ios";
  if (isAndroid()) return "android";
  return "other";
}
