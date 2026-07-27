import { useEffect, useState } from "react";

/**
 * Retourne `value` avec un délai — généralisé depuis le debounce manuel
 * dupliqué (AdminUsersPage, CataloguePage utilisaient chacun leur propre
 * `setTimeout` local). Aucun hook de debounce n'existait avant dans le
 * projet.
 */
export function useDebouncedValue<T>(value: T, delayMs = 300): T {
  const [debounced, setDebounced] = useState(value);

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delayMs);
    return () => clearTimeout(timer);
  }, [value, delayMs]);

  return debounced;
}

export default useDebouncedValue;
