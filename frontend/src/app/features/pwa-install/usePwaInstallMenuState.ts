import { usePwaInstall } from "./PwaInstallProvider";

export type PwaInstallMenuState = {
  label: string;
  actionLabel: string | null;
  onAction: (() => void) | null;
  disabled: boolean;
  /** "installed"/"actionable" méritent d'être affichés ; "unavailable" peut être masqué
   *  par un consommateur compact (ex. menu compte desktop) sans perdre d'information
   *  utile — cf. PwaInstallMenuItem (profil), qui affiche les trois. */
  variant: "installed" | "actionable" | "unavailable";
};

/**
 * Logique du point d'entrée permanent (§7), partagée entre `PwaInstallMenuItem`
 * (page profil instrumentiste) et l'item de menu compte de `DesktopLayout` — un seul
 * calcul d'état pour les deux, jamais dupliqué.
 */
export function usePwaInstallMenuState(): PwaInstallMenuState {
  const { isInstalled, platform, canPromptAndroid, openIosGuide, promptAndroidInstall } = usePwaInstall();

  if (isInstalled) {
    return { label: "Application installée", actionLabel: null, onAction: null, disabled: true, variant: "installed" };
  }

  if (platform === "ios") {
    return {
      label: "Installer l'application",
      actionLabel: "Voir comment faire",
      onAction: openIosGuide,
      disabled: false,
      variant: "actionable",
    };
  }

  if (canPromptAndroid) {
    return {
      label: "Installer l'application",
      actionLabel: "Installer",
      onAction: () => { void promptAndroidInstall(); },
      disabled: false,
      variant: "actionable",
    };
  }

  // Android/desktop sans évènement beforeinstallprompt actif (déjà refusé cette session,
  // navigateur non compatible type Firefox, ou critères d'installabilité pas encore
  // réunis) : repli manuel, jamais un bouton mort sans explication.
  return {
    label: "Installation non proposée automatiquement sur ce navigateur",
    actionLabel: null,
    onAction: null,
    disabled: true,
    variant: "unavailable",
  };
}
