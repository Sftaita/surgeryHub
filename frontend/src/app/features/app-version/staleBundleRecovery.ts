/**
 * D-163 — après un déploiement, un onglet ou une PWA restés ouverts référencent encore les
 * chunks de l'ancien bundle, supprimés du serveur (404, voir docker/nginx/prod.conf). Vite
 * émet alors `vite:preloadError` au chargement d'un écran : on recharge la page UNE fois pour
 * obtenir le nouveau bundle (index.html est revalidé à chaque chargement).
 *
 * Garde anti-boucle : si un rechargement a déjà été tenté il y a moins de 30 s, on laisse
 * l'erreur remonter normalement (panne réelle, pas une simple version obsolète).
 */
const KEY = "surgicalhub.staleBundleReloadAt";
const WINDOW_MS = 30_000;

export function handleStaleBundle(
  event: Event,
  storage: Pick<Storage, "getItem" | "setItem"> = window.sessionStorage,
  reload: () => void = () => window.location.reload(),
  now: number = Date.now(),
): boolean {
  let last = NaN;
  try {
    last = Number(storage.getItem(KEY));
  } catch {
    // stockage indisponible : on tente quand même un rechargement
  }
  if (Number.isFinite(last) && now - last < WINDOW_MS) {
    return false;
  }
  try {
    storage.setItem(KEY, String(now));
  } catch {
    // best-effort
  }
  event.preventDefault();
  reload();
  return true;
}

export function installStaleBundleRecovery(target: Pick<Window, "addEventListener"> = window): void {
  target.addEventListener("vite:preloadError", (event) => {
    handleStaleBundle(event);
  });
}
