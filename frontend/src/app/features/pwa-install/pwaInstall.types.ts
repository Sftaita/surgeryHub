/**
 * "installed" : mode standalone détecté (display-mode ou navigator.standalone) — quelle
 * que soit la plateforme, source de vérité unique, jamais dérivée de "J'ai compris".
 * "android-installable" : `beforeinstallprompt` capturé (Android Chrome, mais aussi tout
 * Chromium desktop qui le supporte — le type ne distingue pas, voir PwaInstallProvider).
 * "ios-installable" : iPhone/iPad, pas standalone — le parcours ne peut être que manuel.
 * "not-installable" : ni l'un ni l'autre (navigateur non compatible, ou événement pas
 * encore reçu).
 * "dismissed" : installable mais l'utilisateur a dépassé le seuil de reports (§ storage)
 * — l'affichage automatique s'arrête, le point d'entrée manuel reste disponible.
 */
export type PwaInstallStatus =
  | "installed"
  | "android-installable"
  | "ios-installable"
  | "not-installable"
  | "dismissed";

export type PwaInstallPlatform = "android" | "ios" | "other";

/** Sous-ensemble typé de l'événement non standard `beforeinstallprompt`. */
export interface BeforeInstallPromptEvent extends Event {
  readonly platforms: string[];
  readonly userChoice: Promise<{ outcome: "accepted" | "dismissed"; platform: string }>;
  prompt(): Promise<void>;
}
