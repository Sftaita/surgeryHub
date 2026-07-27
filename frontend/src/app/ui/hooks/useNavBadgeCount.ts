import { useQuery, type QueryKey } from "@tanstack/react-query";

/**
 * Compteur de badge de navigation — généralisé depuis le `useQuery` +
 * `Badge` câblé en dur dans `DesktopLayout.tsx` (demandes matériel
 * PENDING). Permet d'ajouter d'autres badges (ex. demandes intervention)
 * sans recopier la requête à chaque fois.
 */
export function useNavBadgeCount(queryKey: QueryKey, queryFn: () => Promise<number>, refetchInterval = 60_000): number {
  const query = useQuery({ queryKey, queryFn, refetchInterval });
  return query.data ?? 0;
}

export default useNavBadgeCount;
