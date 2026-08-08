import { useQuery } from "@tanstack/react-query";
import { fetchSurgeonActivity } from "./api/surgeonActivity.api";
import { getActivityRange, type PeriodMode } from "./period";

/**
 * Hook partagé (Lot 4, D-098, §12) — SurgeonActivityPage ET SurgeonHomePage passent par ici,
 * jamais un calcul dupliqué côté Home. Avec les mêmes (mode, referenceYmd), la queryKey est
 * identique : Home ("year", aujourd'hui) et l'Activity page ouverte sans paramètres d'URL
 * (mêmes défauts) partagent donc le même cache React Query, sans requête réseau redondante.
 */
export function useSurgeonActivity(mode: PeriodMode, referenceYmd: string) {
  const range = getActivityRange(mode, referenceYmd);
  return useQuery({
    queryKey: ["surgeon-activity", { from: range.from, to: range.to }],
    queryFn: () => fetchSurgeonActivity(range.from, range.to),
  });
}
