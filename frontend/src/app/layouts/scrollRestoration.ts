import * as React from "react";

/**
 * Restauration de scroll pour l'espace instrumentiste (MobileLayout) — état en
 * mémoire uniquement (jamais persisté au-delà de l'onglet navigateur courant), tenu
 * en dehors de React pour survivre aux changements de route internes sans jamais
 * remonter MobileLayout. La vraie zone scrollable est `window`/`document` : le
 * contenu du layout n'a pas de conteneur `overflow` propre (voir style.css).
 */
const scrollPositions = new Map<string, number>();
const visitedRoutes = new Set<string>();
let encodingOrigin: string | null = null;

/** Route de secours pour le bouton retour de l'encodage quand aucune origine n'a été
 * mémorisée (ouverture directe : lien profond, notification, rechargement complet). */
export const ENCODING_FALLBACK_ROUTE = "/app/i/today";

/**
 * Nouvelle entrée dans l'espace instrumentiste — définie précisément comme le montage
 * initial de MobileLayout après authentification (connexion, ou remontage après une
 * déconnexion/reconnexion dans le même onglet). Un simple changement de route interne
 * ne remonte jamais ce layout, donc ne déclenche jamais cette réinitialisation.
 */
export function resetScrollRestoration(): void {
  scrollPositions.clear();
  visitedRoutes.clear();
  encodingOrigin = null;
}

/** Mémorise la route depuis laquelle on ouvre un encodage — navigate(-1) est trop
 * imprévisible (lien profond, notification, historique externe à l'app) pour servir
 * de bouton retour fiable. */
export function recordEncodingOrigin(pathname: string): void {
  encodingOrigin = pathname;
}

export function getEncodingBackTarget(): string {
  return encodingOrigin ?? ENCODING_FALLBACK_ROUTE;
}

// Quelques frames après le montage pour laisser le contenu asynchrone (React Query)
// s'installer avant de borner/appliquer la position mémorisée — une seule tentative
// immédiate sous-estimerait souvent la hauteur réellement disponible.
const RESTORE_FRAMES = 3;

/**
 * À appeler une seule fois, dans MobileLayout, avec le pathname courant. Première
 * visite de la route pendant la session → haut de page. Retour vers une route déjà
 * visitée → dernière position mémorisée, bornée à la hauteur réellement disponible
 * après rendu (le contenu peut avoir changé depuis la dernière visite).
 */
export function useRouteScrollRestoration(pathname: string): void {
  React.useEffect(() => {
    let ticking = false;
    const onScroll = () => {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(() => {
        scrollPositions.set(pathname, window.scrollY);
        ticking = false;
      });
    };
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => window.removeEventListener("scroll", onScroll);
  }, [pathname]);

  React.useEffect(() => {
    const alreadyVisited = visitedRoutes.has(pathname);
    visitedRoutes.add(pathname);

    if (!alreadyVisited) {
      window.scrollTo(0, 0);
      return;
    }

    let cancelled = false;
    let frame = 0;
    let handle = 0;
    const apply = () => {
      if (cancelled) return;
      const saved = scrollPositions.get(pathname) ?? 0;
      const maxScroll = Math.max(0, document.documentElement.scrollHeight - window.innerHeight);
      window.scrollTo(0, Math.min(saved, maxScroll));
      frame += 1;
      if (frame < RESTORE_FRAMES) handle = requestAnimationFrame(apply);
    };
    handle = requestAnimationFrame(apply);

    return () => {
      cancelled = true;
      cancelAnimationFrame(handle);
    };
  }, [pathname]);
}
