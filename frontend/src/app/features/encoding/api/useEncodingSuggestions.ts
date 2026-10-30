import { useQuery } from "@tanstack/react-query";
import { fetchRecentFirms, fetchRecentInterventionTypes } from "./encoding.api";

/**
 * D-178 — raccourcis « récemment utilisés » des fenêtres d'ajout / de modification d'une
 * intervention. Relus à chaque ouverture (staleTime 0) : ils reflètent toujours les derniers
 * encodages enregistrés. Une erreur n'affiche simplement aucun raccourci — la liste complète
 * reste la voie normale.
 */
export function useRecentFirms(missionId: number | undefined, enabled: boolean) {
  return useQuery({
    queryKey: ["encodingSuggestions", missionId, "firms"],
    queryFn: () => fetchRecentFirms(missionId as number),
    enabled: enabled && missionId != null,
    staleTime: 0,
    retry: false,
  });
}

/** Clé = mission × firme × chirurgien de la mission : un changement de l'un recalcule. */
export function useRecentInterventionTypes(missionId: number | undefined, firmId: number | null, surgeonId: number | null | undefined, enabled: boolean) {
  return useQuery({
    queryKey: ["encodingSuggestions", missionId, "interventionTypes", firmId, surgeonId ?? null],
    queryFn: () => fetchRecentInterventionTypes(missionId as number, firmId as number),
    enabled: enabled && missionId != null && firmId != null,
    staleTime: 0,
    retry: false,
  });
}
