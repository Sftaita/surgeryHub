import { useQuery } from "@tanstack/react-query";
import { fetchRecentFirms, fetchRecentInterventionTypes } from "./encoding.api";

/**
 * D-178 — raccourcis « récemment utilisés » des fenêtres d'ajout / de modification d'une
 * intervention. Relus à chaque ouverture (staleTime 0) : ils reflètent toujours les derniers
 * encodages enregistrés. Jamais conservés au-delà de la page d'encodage (gcTime 0) : le cache
 * applicatif survit à une déconnexion sans rechargement, et les firmes récentes sont propres au
 * compte connecté — un autre compte ne doit jamais les voir, même un instant. Une erreur
 * n'affiche simplement aucun raccourci — la liste complète reste la voie normale.
 */
export function useRecentFirms(missionId: number | undefined, enabled: boolean) {
  return useQuery({
    queryKey: ["encodingSuggestions", missionId, "firms"],
    queryFn: () => fetchRecentFirms(missionId as number),
    enabled: enabled && missionId != null,
    staleTime: 0,
    gcTime: 0,
    retry: false,
  });
}

/** Clé = mission × firme × chirurgien de la mission : un changement de l'un recalcule. */
export function useRecentInterventionTypes(missionId: number | undefined, firmId: number | "none" | null, surgeonId: number | null | undefined, enabled: boolean) {
  return useQuery({
    queryKey: ["encodingSuggestions", missionId, "interventionTypes", firmId, surgeonId ?? null],
    queryFn: () => fetchRecentInterventionTypes(missionId as number, firmId as number | "none"),
    enabled: enabled && missionId != null && firmId != null,
    staleTime: 0,
    gcTime: 0,
    retry: false,
  });
}
