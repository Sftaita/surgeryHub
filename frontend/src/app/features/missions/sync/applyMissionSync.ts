import type { QueryClient } from "@tanstack/react-query";
import type { Mission, MissionSyncResponse, PaginatedResponse } from "../api/missions.types";

function isPaginatedMissionList(data: unknown): data is PaginatedResponse<Mission> {
  return (
    !!data &&
    typeof data === "object" &&
    Array.isArray((data as PaginatedResponse<Mission>).items)
  );
}

export type ApplyMissionSyncResult = {
  /** Missions OPEN éligibles, claimables, qui n'étaient pas encore dans le cache "Offres". */
  newOpenOffers: Mission[];
  removedCount: number;
};

/**
 * Applique le résultat de GET /api/instrumentist/missions/sync au cache React Query :
 * - met à jour en place toute mission déjà présente dans une liste mise en cache
 *   (Offres, Mes missions, Planning, Aujourd'hui)
 * - retire des listes toute mission présente dans `removedMissionIds`
 * - ajoute les nouvelles offres OPEN éligibles à la liste "Offres"
 * - ajoute les missions nouvellement assignées à l'utilisateur courant à "Mes missions"
 *
 * IMPORTANT : aucune inférence de droits ici — `allowedActions` (fourni par le backend)
 * reste la seule source de vérité, on se contente de répercuter l'état serveur.
 */
export function applyMissionSyncToCache(
  queryClient: QueryClient,
  sync: MissionSyncResponse,
  currentUserId: number | null,
): ApplyMissionSyncResult {
  const removedSet = new Set(sync.removedMissionIds);
  const updatedById = new Map(sync.missions.map((m) => [m.id, m]));

  const previousOffers = queryClient.getQueryData<PaginatedResponse<Mission>>([
    "missions",
    "offers",
  ]);
  const previousOfferIds = new Set((previousOffers?.items ?? []).map((m) => m.id));

  const newOpenOffers = sync.missions.filter(
    (m) =>
      m.status === "OPEN" &&
      (m.allowedActions?.includes("claim") ?? false) &&
      !previousOfferIds.has(m.id),
  );

  const claimedByMe =
    currentUserId != null
      ? sync.missions.filter((m) => m.instrumentist?.id === currentUserId)
      : [];

  // 1) Met à jour en place / retire dans toutes les listes de missions déjà en cache.
  queryClient
    .getQueriesData<PaginatedResponse<Mission>>({ queryKey: ["missions"] })
    .forEach(([queryKey, data]) => {
      if (!isPaginatedMissionList(data)) return;

      let changed = false;

      let items = data.items.filter((it) => {
        if (removedSet.has(it.id)) {
          changed = true;
          return false;
        }
        return true;
      });

      items = items.map((it) => {
        const updated = updatedById.get(it.id);
        if (!updated) return it;
        changed = true;
        return { ...it, ...updated };
      });

      if (changed) {
        queryClient.setQueryData(queryKey, { ...data, items, total: items.length });
      }
    });

  // 2) Offres : préfixe les nouvelles missions OPEN éligibles non encore listées.
  if (newOpenOffers.length > 0) {
    queryClient.setQueryData<PaginatedResponse<Mission>>(["missions", "offers"], (old) => {
      if (!old) return old;
      const existingIds = new Set(old.items.map((i) => i.id));
      const toAdd = newOpenOffers.filter((m) => !existingIds.has(m.id));
      if (toAdd.length === 0) return old;
      const items = [...toAdd, ...old.items];
      return { ...old, items, total: items.length };
    });
  }

  // 3) Mes missions : ajoute les missions nouvellement assignées à l'utilisateur courant.
  if (claimedByMe.length > 0) {
    queryClient.setQueryData<PaginatedResponse<Mission>>(["missions", "my-missions"], (old) => {
      if (!old) return old;
      const existingIds = new Set(old.items.map((i) => i.id));
      const toAdd = claimedByMe.filter((m) => !existingIds.has(m.id));
      if (toAdd.length === 0) return old;
      const items = [...old.items, ...toAdd];
      return { ...old, items, total: items.length };
    });
  }

  // 4) D-156 lot 1 — Planning / Aujourd'hui / À venir : une mission nouvellement assignée
  // (ou modifiée) absente de ces listes n'y était jamais ajoutée (seules les listes déjà
  // chargées étaient patchées). Ces listes ont des filtres serveur (statuts, période) qu'on
  // ne rejoue pas côté client : on les fait simplement relire au backend.
  if (claimedByMe.length > 0) {
    const missingFrom = (key: readonly unknown[]) =>
      queryClient
        .getQueriesData<PaginatedResponse<Mission>>({ queryKey: key })
        .some(([, data]) => isPaginatedMissionList(data) && claimedByMe.some((m) => !data.items.some((it) => it.id === m.id)));
    for (const key of [["missions", "planning"], ["missions", "today"], ["missions", "upcoming"], ["missions", "pending-encoding"]] as const) {
      if (missingFrom(key)) void queryClient.invalidateQueries({ queryKey: key });
    }
  }

  return { newOpenOffers, removedCount: sync.removedMissionIds.length };
}
