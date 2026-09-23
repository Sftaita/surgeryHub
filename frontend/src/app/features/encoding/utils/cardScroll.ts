/**
 * Défilement "carte en haut" (DOCUMENTATION.md §6) — porté depuis
 * docs/design/Instruction design/Page encodage instrumentiste/src/useCardScroll.ts.
 *
 * On n'utilise PAS Element.scrollIntoView({behavior:'smooth'}) :
 *  - iOS Safari < 15.4 l'ignore silencieusement (saut brut),
 *  - Android WebView (Chrome < 61 / WebViews embarquées) ne supporte pas l'objet d'options,
 *  - aucun des deux ne permet de compenser un en-tête sticky.
 * On calcule la position cible puis on anime nous-mêmes en requestAnimationFrame.
 */

/** Décalage au-dessus de la carte — EncodeHeader n'est pas sticky sur cet écran
 *  (MobileLayout masque BrandBand ici), donc pas de hauteur de barre à ajouter. */
export const SCROLL_OFFSET = 12;

function prefersReducedMotion(): boolean {
  return typeof window !== "undefined" && window.matchMedia?.("(prefers-reduced-motion: reduce)").matches === true;
}

/** Conteneur défilant : le premier ancêtre scrollable, sinon la fenêtre. */
function scrollParent(el: HTMLElement): HTMLElement | Window {
  let node: HTMLElement | null = el.parentElement;
  while (node) {
    const { overflowY } = getComputedStyle(node);
    if ((overflowY === "auto" || overflowY === "scroll") && node.scrollHeight > node.clientHeight) return node;
    node = node.parentElement;
  }
  return window;
}

const easeOutQuint = (t: number) => 1 - Math.pow(1 - t, 5);

let raf = 0;

/** Anime le défilement du conteneur jusqu'à `top` (px). 320ms, easing rapide en sortie. */
export function smoothScrollTo(container: HTMLElement | Window, top: number, duration = 320) {
  const isWin = container === window;
  const get = () => (isWin ? window.pageYOffset || document.documentElement.scrollTop : (container as HTMLElement).scrollTop);
  const set = (v: number) => {
    if (isWin) window.scrollTo(0, v);
    else (container as HTMLElement).scrollTop = v;
  };

  const max = isWin
    ? Math.max(0, document.documentElement.scrollHeight - window.innerHeight)
    : Math.max(0, (container as HTMLElement).scrollHeight - (container as HTMLElement).clientHeight);
  const target = Math.min(Math.max(0, top), max);
  const start = get();
  const delta = target - start;

  if (raf) cancelAnimationFrame(raf);
  if (prefersReducedMotion() || Math.abs(delta) < 2) { set(target); return; }

  const t0 = performance.now();
  const step = (now: number) => {
    const p = Math.min(1, (now - t0) / duration);
    set(start + delta * easeOutQuint(p));
    if (p < 1) raf = requestAnimationFrame(step);
  };
  raf = requestAnimationFrame(step);
}

/** Amène le haut de `el` en haut de la zone visible (moins SCROLL_OFFSET). */
export function scrollElementToTop(el: HTMLElement | null, offset = SCROLL_OFFSET) {
  if (!el) return;
  const container = scrollParent(el);
  const rect = el.getBoundingClientRect();
  const top =
    container === window
      ? rect.top + (window.pageYOffset || document.documentElement.scrollTop) - offset
      : rect.top - (container as HTMLElement).getBoundingClientRect().top + (container as HTMLElement).scrollTop - offset;
  // deux frames : on laisse le DOM déplié se mesurer avant de viser (iOS mesure faux sinon)
  requestAnimationFrame(() => requestAnimationFrame(() => smoothScrollTo(container, top)));
}
