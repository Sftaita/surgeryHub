/**
 * Politique de report — §6 du lot :
 *   1er "Plus tard"/"J'ai compris" → ne re-proposer automatiquement qu'après 7 jours ;
 *   2e  → 14 jours ;
 *   3e et suivants → ne plus jamais re-proposer automatiquement (le point d'entrée
 *   manuel du profil/menu reste, lui, toujours disponible — voir PwaInstallMenuItem).
 * "J'ai compris" ne signifie pas installé : il applique la même règle de report que
 * "Plus tard" (§6 "une règle adaptée" = celle-ci, pas une suppression plus forte) — la
 * détection standalone (pwaInstallDetection.ts) reste seule source de vérité sur
 * l'installation réelle.
 */
const KEY_DISMISSED_AT = "surgicalhub.pwaInstall.dismissedAt";
const KEY_DISMISS_COUNT = "surgicalhub.pwaInstall.dismissCount";
const KEY_COMPLETED_GUIDE = "surgicalhub.pwaInstall.completedGuide";

const DAY_MS = 24 * 60 * 60 * 1000;
const DELAY_AFTER_DISMISSAL_MS = [7 * DAY_MS, 14 * DAY_MS];

function safeGet(key: string): string | null {
  try {
    return window.localStorage.getItem(key);
  } catch {
    // Stockage indisponible (navigation privée stricte, quota) — dégrade en "toujours
    // proposer", jamais en erreur bloquante pour l'utilisateur.
    return null;
  }
}

function safeSet(key: string, value: string): void {
  try {
    window.localStorage.setItem(key, value);
  } catch {
    // best-effort — voir safeGet
  }
}

function safeRemove(key: string): void {
  try {
    window.localStorage.removeItem(key);
  } catch {
    // best-effort
  }
}

export function getDismissCount(): number {
  const raw = safeGet(KEY_DISMISS_COUNT);
  const n = raw ? parseInt(raw, 10) : 0;
  return Number.isFinite(n) && n > 0 ? n : 0;
}

export function getDismissedAt(): number | null {
  const raw = safeGet(KEY_DISMISSED_AT);
  const n = raw ? parseInt(raw, 10) : NaN;
  return Number.isFinite(n) ? n : null;
}

/** Appelé par "Plus tard" (bannière) et "J'ai compris" (modal iOS) — même règle. */
export function recordDismissal(now: number = Date.now()): void {
  const nextCount = getDismissCount() + 1;
  safeSet(KEY_DISMISS_COUNT, String(nextCount));
  safeSet(KEY_DISMISSED_AT, String(now));
}

/** Marqueur informatif seul (jamais utilisé pour décider de l'affichage automatique). */
export function recordGuideCompleted(now: number = Date.now()): void {
  safeSet(KEY_COMPLETED_GUIDE, String(now));
}

export function hasCompletedGuide(): boolean {
  return safeGet(KEY_COMPLETED_GUIDE) !== null;
}

/**
 * Décide si la bannière/le modal peuvent apparaître automatiquement à cette session.
 * Le point d'entrée manuel (profil/menu) n'appelle jamais cette fonction — il reste
 * toujours disponible, quel que soit le nombre de reports.
 */
export function shouldShowAutomatically(now: number = Date.now()): boolean {
  const count = getDismissCount();
  if (count === 0) return true;
  if (count > DELAY_AFTER_DISMISSAL_MS.length) return false;

  const dismissedAt = getDismissedAt();
  if (dismissedAt === null) return true;

  const delay = DELAY_AFTER_DISMISSAL_MS[count - 1];
  return now - dismissedAt >= delay;
}

/** Appelé sur `appinstalled` — §4.4 "supprimer l'état de report ; ne plus reproposer". */
export function clearDismissalState(): void {
  safeRemove(KEY_DISMISSED_AT);
  safeRemove(KEY_DISMISS_COUNT);
  safeRemove(KEY_COMPLETED_GUIDE);
}
