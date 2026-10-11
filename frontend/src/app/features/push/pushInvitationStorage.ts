/**
 * D-143 — politique de report de l'invitation push (même règle que l'installation PWA,
 * pwaInstallStorage.ts) : 1er « Plus tard » → re-proposée après 7 jours, 2e → 14 jours,
 * 3e et suivants → plus jamais automatiquement (la carte « Notifications » du profil
 * reste toujours disponible). Préférence purement locale à l'appareil : la permission
 * push est elle-même par appareil/navigateur.
 */
const KEY_DISMISSED_AT = "surgicalhub.pushInvite.dismissedAt";
const KEY_DISMISS_COUNT = "surgicalhub.pushInvite.dismissCount";

const DAY_MS = 24 * 60 * 60 * 1000;
const DELAY_AFTER_DISMISSAL_MS = [7 * DAY_MS, 14 * DAY_MS];

function safeGet(key: string): string | null {
  try {
    return window.localStorage.getItem(key);
  } catch {
    return null;
  }
}

function safeSet(key: string, value: string): void {
  try {
    window.localStorage.setItem(key, value);
  } catch {
    // best-effort — stockage indisponible : l'invitation reste simplement proposable.
  }
}

export function recordPushInvitationDismissal(now: number = Date.now()): void {
  const raw = safeGet(KEY_DISMISS_COUNT);
  const count = raw ? parseInt(raw, 10) || 0 : 0;
  safeSet(KEY_DISMISS_COUNT, String(count + 1));
  safeSet(KEY_DISMISSED_AT, String(now));
}

export function shouldShowPushInvitation(now: number = Date.now()): boolean {
  const rawCount = safeGet(KEY_DISMISS_COUNT);
  const count = rawCount ? parseInt(rawCount, 10) || 0 : 0;
  if (count === 0) return true;
  if (count > DELAY_AFTER_DISMISSAL_MS.length) return false;

  const at = parseInt(safeGet(KEY_DISMISSED_AT) ?? "", 10);
  if (!Number.isFinite(at)) return true;

  return now - at >= DELAY_AFTER_DISMISSAL_MS[count - 1];
}
