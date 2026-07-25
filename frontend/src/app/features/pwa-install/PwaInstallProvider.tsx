import * as Sentry from "@sentry/react";
import { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";
import type { ReactNode } from "react";
import { useAuth } from "../../auth/AuthContext";
import { IosInstallGuideModal } from "./IosInstallGuideModal";
import { detectPlatform, isStandalone } from "./pwaInstallDetection";
import { clearDismissalState, recordDismissal, recordGuideCompleted, shouldShowAutomatically } from "./pwaInstallStorage";
import type { BeforeInstallPromptEvent, PwaInstallPlatform, PwaInstallStatus } from "./pwaInstall.types";

type PwaInstallContextValue = {
  /** État global affichable — replie la politique de report (voir pwaInstallStorage). */
  status: PwaInstallStatus;
  /** Détection brute, jamais affectée par la politique de report — pour le point d'entrée manuel. */
  platform: PwaInstallPlatform;
  isInstalled: boolean;
  canPromptAndroid: boolean;
  /** true seulement si authentifié, installable, et la politique de report l'autorise. */
  showBanner: boolean;
  isIosGuideOpen: boolean;
  openIosGuide: () => void;
  closeIosGuide: (outcome: "later" | "understood") => void;
  dismissBanner: () => void;
  promptAndroidInstall: () => Promise<"accepted" | "dismissed" | "unavailable" | "error">;
};

const PwaInstallContext = createContext<PwaInstallContextValue | null>(null);

/**
 * Monté une seule fois à la racine authentifiée (voir AppProviders.tsx), sur le même
 * principe que PushProvider (D-081) : socle unique, jamais dupliqué par layout. Les deux
 * parcours (installation PWA, autorisation Push) restent strictement indépendants — ce
 * provider n'importe rien de features/push et ne demande jamais la permission de
 * notification (voir D-082).
 */
export function PwaInstallProvider({ children }: { children: ReactNode }) {
  const { state } = useAuth();
  const isAuthenticated = state.status === "authenticated";

  const platformRef = useRef<PwaInstallPlatform>(detectPlatform());
  const deferredPromptRef = useRef<BeforeInstallPromptEvent | null>(null);

  const [isInstalled, setIsInstalled] = useState<boolean>(isStandalone);
  const [canPromptAndroid, setCanPromptAndroid] = useState(false);
  const [isIosGuideOpen, setIsIosGuideOpen] = useState(false);
  // Incrémenté à chaque report — force la relecture de shouldShowAutomatically() sans
  // dupliquer l'état de pwaInstallStorage.ts (source de vérité unique, localStorage).
  const [dismissTick, setDismissTick] = useState(0);

  useEffect(() => {
    if (isInstalled) return;

    function handleBeforeInstallPrompt(e: Event) {
      // Empêche le mini-infobar Chrome automatique — on affiche notre propre bannière,
      // déclenchée par une action utilisateur explicite (§4.1).
      e.preventDefault();
      deferredPromptRef.current = e as BeforeInstallPromptEvent;
      setCanPromptAndroid(true);
    }

    function handleAppInstalled() {
      setIsInstalled(true);
      setCanPromptAndroid(false);
      deferredPromptRef.current = null;
      setIsIosGuideOpen(false);
      clearDismissalState();
    }

    window.addEventListener("beforeinstallprompt", handleBeforeInstallPrompt);
    window.addEventListener("appinstalled", handleAppInstalled);
    return () => {
      window.removeEventListener("beforeinstallprompt", handleBeforeInstallPrompt);
      window.removeEventListener("appinstalled", handleAppInstalled);
    };
  }, [isInstalled]);

  const platform = platformRef.current;

  const status: PwaInstallStatus = isInstalled
    ? "installed"
    : !shouldShowAutomatically(Date.now())
      ? "dismissed"
      : canPromptAndroid
        ? "android-installable"
        : platform === "ios"
          ? "ios-installable"
          : "not-installable";

  // Jamais sur l'écran de login (isAuthenticated), jamais si la politique de report ne
  // l'autorise pas (status "dismissed" le reflète déjà).
  const showBanner =
    isAuthenticated && (status === "android-installable" || status === "ios-installable");

  const dismissBanner = useCallback(() => {
    recordDismissal();
    setDismissTick((t) => t + 1);
  }, []);

  const openIosGuide = useCallback(() => setIsIosGuideOpen(true), []);

  const closeIosGuide = useCallback((outcome: "later" | "understood") => {
    // "J'ai compris" ne signifie pas installé — même règle de report que "Plus tard"
    // (voir pwaInstallStorage.ts). La détection standalone reste seule source de vérité.
    recordDismissal();
    if (outcome === "understood") recordGuideCompleted();
    setDismissTick((t) => t + 1);
    setIsIosGuideOpen(false);
  }, []);

  const promptAndroidInstall = useCallback(async (): Promise<"accepted" | "dismissed" | "unavailable" | "error"> => {
    const deferredPrompt = deferredPromptRef.current;
    if (!deferredPrompt) return "unavailable";

    try {
      await deferredPrompt.prompt();
      const { outcome } = await deferredPrompt.userChoice;
      // Événement à usage unique côté spec — invalide après le premier prompt() qu'il
      // ait été accepté ou refusé (§4.3 "après utilisation, supprimer la référence").
      deferredPromptRef.current = null;
      setCanPromptAndroid(false);
      return outcome;
    } catch (err) {
      deferredPromptRef.current = null;
      setCanPromptAndroid(false);
      Sentry.captureException(err, { tags: { feature: "pwa-install", stage: "prompt" } });
      return "error";
    }
  }, []);

  const value: PwaInstallContextValue = {
    status,
    platform,
    isInstalled,
    canPromptAndroid,
    showBanner,
    isIosGuideOpen,
    openIosGuide,
    closeIosGuide,
    dismissBanner,
    promptAndroidInstall,
  };

  // dismissTick n'est lu nulle part directement : il ne sert qu'à provoquer ce re-render
  // pour que `status`/`showBanner` (recalculés à chaque rendu) reflètent immédiatement un
  // nouveau report sans dupliquer l'état déjà en localStorage.
  void dismissTick;

  return (
    <PwaInstallContext.Provider value={value}>
      {children}
      <IosInstallGuideModal open={isIosGuideOpen} onClose={closeIosGuide} />
    </PwaInstallContext.Provider>
  );
}

export function usePwaInstall(): PwaInstallContextValue {
  const ctx = useContext(PwaInstallContext);
  if (!ctx) {
    throw new Error("usePwaInstall must be used inside PwaInstallProvider");
  }
  return ctx;
}
