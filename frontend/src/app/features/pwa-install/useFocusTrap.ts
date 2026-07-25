import { useEffect } from "react";
import type { RefObject } from "react";

const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Focus trap + fermeture clavier auto-suffisants, scopés à ce module (le composant
 * partagé `SheetModal` n'en a pas — modifier un composant partagé par tout le reste de
 * l'app pour un seul lot était jugé hors périmètre, voir D-082). À l'ouverture : focus le
 * premier élément focusable du conteneur ; Tab/Shift+Tab bouclent à l'intérieur ;
 * Échap ferme ; le focus revient à l'élément déclencheur à la fermeture.
 */
export function useFocusTrap(containerRef: RefObject<HTMLElement | null>, active: boolean, onClose: () => void): void {
  useEffect(() => {
    if (!active) return;

    const previouslyFocused = document.activeElement as HTMLElement | null;
    const container = containerRef.current;
    const focusFirst = () => {
      const focusable = container?.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR);
      focusable?.[0]?.focus();
    };
    // Le contenu (issu d'un portail) n'est pas toujours peint au moment de cet effet.
    const raf = requestAnimationFrame(focusFirst);

    function handleKeyDown(e: KeyboardEvent) {
      if (e.key === "Escape") {
        e.stopPropagation();
        onClose();
        return;
      }
      if (e.key !== "Tab" || !container) return;

      const focusable = Array.from(container.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR));
      if (focusable.length === 0) return;

      const first = focusable[0];
      const last = focusable[focusable.length - 1];

      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    }

    document.addEventListener("keydown", handleKeyDown, true);
    return () => {
      cancelAnimationFrame(raf);
      document.removeEventListener("keydown", handleKeyDown, true);
      previouslyFocused?.focus?.();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [active]);
}
