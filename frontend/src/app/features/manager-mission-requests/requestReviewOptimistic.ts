import type { QueryClient } from "@tanstack/react-query";
import type { ManagerSurgeonMissionRequest, SurgeonMissionRequestCounts } from "./api/managerSurgeonMissionRequests.types";

/**
 * D-147 — mise à jour des listes et compteurs après la revue d'une demande chirurgien
 * (accepter / refuser).
 *
 * Appliquée uniquement une fois la transition CONFIRMÉE par le serveur (onSuccess) : la
 * fenêtre de revue reste ouverte pendant l'enregistrement et en cas d'échec (saisie
 * conservée, nouvelle tentative possible). La demande quitte alors « En attente » et les
 * compteurs se déplacent d'une unité sans attendre le rechargement ; aucune ligne n'est
 * fabriquée dans Acceptées/Refusées (reviewedBy, mission créée… viennent du serveur) —
 * tout est rechargé au règlement.
 */
export const REQUESTS_LIST_KEY = ["surgeon-mission-requests-manager"] as const;
export const REQUESTS_COUNTS_KEY = ["surgeon-mission-requests-counts"] as const;
export const REQUESTS_HISTORY_KEY = ["surgeon-mission-requests-history"] as const;

export type ReviewDecision = "ACCEPTED" | "REJECTED";

type ListData = { items: ManagerSurgeonMissionRequest[]; total: number };

function pendingListKey() {
  return [...REQUESTS_LIST_KEY, "PENDING"] as const;
}

function shiftCounts(qc: QueryClient, decision: ReviewDecision, delta: 1) {
  qc.setQueryData<SurgeonMissionRequestCounts>(REQUESTS_COUNTS_KEY, (c) =>
    c
      ? { ...c, PENDING: Math.max(0, c.PENDING - delta), [decision]: Math.max(0, c[decision] + delta) }
      : c,
  );
}

/** onSuccess — retire la demande de « En attente » et déplace les compteurs. */
export async function applyConfirmedReview(
  qc: QueryClient,
  request: ManagerSurgeonMissionRequest,
  decision: ReviewDecision,
): Promise<void> {
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
  // Demande absente de la liste en cache (déjà rechargée) : les compteurs viendront du serveur.
  if (removed) shiftCounts(qc, decision, 1);
}

/** onSettled — le serveur reste la source de vérité : tout est rechargé. */
export function settleReview(qc: QueryClient): Promise<unknown> {
  return Promise.all([
    qc.invalidateQueries({ queryKey: REQUESTS_LIST_KEY }),
    qc.invalidateQueries({ queryKey: REQUESTS_COUNTS_KEY }),
    qc.invalidateQueries({ queryKey: REQUESTS_HISTORY_KEY }),
  ]);
}
