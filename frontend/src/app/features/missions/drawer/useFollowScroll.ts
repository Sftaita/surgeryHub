import { useCallback, useEffect, useRef, type RefObject } from 'react';

/**
 * Garde le haut d'un élément visible dans un conteneur scrollable PENDANT une animation de hauteur
 * (ex. : la carte du dessus se referme et décale celle qu'on ouvre).
 *
 * - Pas de scrollIntoView (comportement erratique iOS Safari / WebView Android).
 * - Démarre APRÈS le commit React (appeler depuis un useEffect / useLayoutEffect).
 * - Recalcule la cible à chaque frame, s'arrête quand elle est stable 2 frames ET que la
 *   transition CSS (300ms) est passée ; plafond 900ms.
 */
export function useFollowScroll(containerRef: RefObject<HTMLElement | null>, offset = 14) {
  const raf = useRef(0);
  useEffect(() => () => cancelAnimationFrame(raf.current), []);

  return useCallback((selector: string) => {
    const body = containerRef.current;
    if (!body) return;
    cancelAnimationFrame(raf.current);
    const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    const t0 = performance.now(), D = reduce ? 0 : 340, MIN = 320, MAX = 900;
    const ease = (t: number) => 1 - Math.pow(1 - t, 3);
    const from = body.scrollTop;
    let last: number | null = null, stable = 0;

    const step = (now: number) => {
      const el = body.querySelector<HTMLElement>(selector);
      if (!el) return;
      const elapsed = now - t0;
      const maxScroll = body.scrollHeight - body.clientHeight;
      const raw = el.getBoundingClientRect().top - body.getBoundingClientRect().top + body.scrollTop - offset;
      const target = Math.max(0, Math.min(maxScroll, raw));
      const t = D ? Math.min(1, elapsed / D) : 1;
      body.scrollTop = t < 1 ? from + (target - from) * ease(t) : target;
      stable = last !== null && Math.abs(target - last) < 0.5 ? stable + 1 : 0;
      last = target;
      if (elapsed < MAX && (elapsed < MIN || stable < 2)) raf.current = requestAnimationFrame(step);
    };
    raf.current = requestAnimationFrame(step);
  }, [containerRef, offset]);
}
