import { useQuery, type QueryKey } from "@tanstack/react-query";

/**
 * Compteur de badge de navigation — généralisé depuis le `useQuery` +
 * `Badge` câblé en dur dans `DesktopLayout.tsx` (demandes matériel
 * PENDING). Permet d'ajouter d'autres badges (ex. demandes intervention)
 * sans recopier la requête à chaque fois.
 *
 * Correctif workflow Demandes Catalogue (D-113) — `select` optionnel : le badge
 * "Demandes" doit lire le compte depuis la MÊME clé/queryFn que la liste
 * (`CatalogueRequestsPage`), pas un fetch séparé renvoyant une forme différente sous la
 * même clé (collision qui corrompait le cache React Query selon l'ordre de montage — voir
 * docs/decisions.md D-113). `select` réduit la donnée partagée à un nombre côté badge
 * uniquement ; sans lui, le comportement historique (queryFn renvoyant déjà un number) est
 * inchangé.
 */
export function useNavBadgeCount<T = number>(
  queryKey: QueryKey,
  queryFn: () => Promise<T>,
  refetchInterval = 60_000,
  select?: (data: T) => number,
): number {
  const query = useQuery({ queryKey, queryFn, refetchInterval, select });
  return (query.data as number | undefined) ?? 0;
}

export default useNavBadgeCount;
