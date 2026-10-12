import type { QueryClient } from "@tanstack/react-query";
import type { ManagerSurgeonMissionRequest, SurgeonMissionRequestCounts } from "./api/managerSurgeonMissionRequests.types";

/**
 * D-147 — mise à jour optimiste de la revue des demandes chirurgien (accepter / refuser).
 *
 * Ce que l'optimisme touche, et seulement ça : la demande quitte la liste « En attente »
 * et les compteurs se déplacent d'une unité (PENDING → ACCEPTED/REJECTED). Le statut
 * n'est jamais décidé ici : c'est la transition que le manager vient de déclencher sur
 * l'endpoint dédié (accept/reject), affichée avant la réponse. Aucune ligne n'est
 * fabriquée dans les listes Acceptées/Refusées (reviewedBy, mission créée… viennent du
 * serveur) — elles sont rechargées au règlement, comme les compteurs.
 *
 * Retour arrière par opération inverse (pas par restauration d'un instantané) : deux
 * décisions concurrentes ne s'écrasent pas — annuler l'une ne fait pas réapparaître
 * l'autre.
 */
export const REQUESTS_LIST_KEY = ["surgeon-mission-requests-manager"] as const;
export const REQUESTS_COUNTS_KEY = ["surgeon-mission-requests-counts"] as const;
export const REQUESTS_HISTORY_KEY = ["surgeon-mission-requests-history"] as const;

export type ReviewDecision = "ACCEPTED" | "REJECTED";

type ListData = { items: ManagerSurgeonMissionRequest[]; total: number };

function pendingListKey() {
  return [...REQUESTS_LIST_KEY, "PENDING"] as const;
}

function shiftCounts(qc: QueryClient, decision: ReviewDecision, delta: 1 | -1) {
  qc.setQueryData<SurgeonMissionRequestCounts>(REQUESTS_COUNTS_KEY, (c) =>
    c
      ? { ...c, PENDING: Math.max(0, c.PENDING - delta), [decision]: Math.max(0, c[decision] + delta) }
      : c,
  );
}

/** onMutate — retire la demande de « En attente » et déplace les compteurs. */
export async function applyOptimisticReview(
  qc: QueryClient,
  request: ManagerSurgeonMissionRequest,
  decision: ReviewDecision,
): Promise<{ removed: boolean }> {
  await Promise.all([
    qc.cancelQueries({ queryKey: REQUESTS_LIST_KEY }),
    qc.cancelQueries({ queryKey: REQUESTS_COUNTS_KEY }),
  ]);

  let removed = false;
  qc.setQueryData<ListData>(pendingListKey(), (list) => {
    if (!list || !list.items.some((r) => r.id === request.id)) return list;
    removed = true;
    return { items: list.items.filter((r) => r.id !== request.id), total: Math.max(0, list.total - 1) };
  });
  shiftCounts(qc, decision, 1);

  return { removed };
}

/** onError — opération inverse : la demande revient à sa place, les compteurs aussi. */
export function rollbackOptimisticReview(
  qc: QueryClient,
  request: ManagerSurgeonMissionRequest,
  decision: ReviewDecision,
  context: { removed: boolean } | undefined,
): void {
  if (context?.removed) {
    qc.setQueryData<ListData>(pendingListKey(), (list) => {
      if (!list || list.items.some((r) => r.id === request.id)) return list;
      // Liste serveur triée par createdAt DESC — réinsérée à sa place.
      const items = [...list.items, request].sort((a, b) => b.createdAt.localeCompare(a.createdAt) || b.id - a.id);
      return { items, total: list.total + 1 };
    });
  }
  shiftCounts(qc, decision, -1);
}

/** onSettled — le serveur reste la source de vérité : tout est rechargé. */
export function settleReview(qc: QueryClient): Promise<unknown> {
  return Promise.all([
    qc.invalidateQueries({ queryKey: REQUESTS_LIST_KEY }),
    qc.invalidateQueries({ queryKey: REQUESTS_COUNTS_KEY }),
    qc.invalidateQueries({ queryKey: REQUESTS_HISTORY_KEY }),
  ]);
}
