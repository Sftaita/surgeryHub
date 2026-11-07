import { apiClient } from "../../../api/apiClient";
import type { MissionStatus } from "../api/missions.types";
import type { ReassignmentRecipientRole } from "../reassignment/reassignment.api";

/**
 * D-182 — « Mission remplacée par une autre mission » : les missions existantes que le serveur
 * accepte de conserver à la place de celle-ci (même jour, site, chirurgien, horaires qui se
 * recouvrent), puis l'annulation de celle-ci comme doublon, en une seule opération.
 */
export interface ReplacementCandidate {
  id: number;
  status: MissionStatus;
  startAt: string;
  endAt: string;
  siteName: string | null;
  roomLabel: string | null;
  instrumentist: { id: number; firstname: string | null; lastname: string | null } | null;
  /** DECLARED : déclaration encore à valider (validable dans la même opération). */
  declared: boolean;
  hasEncodingData: boolean;
}

export interface CancelAsReplacedResult {
  operationId: string;
  cancelledMissionId: number;
  replacementMissionId: number;
  /** La déclaration de la mission conservée a été validée par cette opération. */
  approved: boolean;
  recipients: Array<{ userId: number; name: string; roles: ReassignmentRecipientRole[] }>;
}

export async function fetchReplacementCandidates(missionId: number): Promise<ReplacementCandidate[]> {
  const { data } = await apiClient.get<{ items: ReplacementCandidate[] }>(`/api/missions/${missionId}/replacement-candidates`);
  return data.items;
}

export async function cancelAsReplaced(
  missionId: number,
  payload: {
    replacementMissionId: number;
    approveReplacement: boolean;
    /** Le statut vu à l'écran : le serveur refuse si la mission conservée a changé entre-temps. */
    expectedReplacementStatus: MissionStatus;
    internalComment?: string;
    /** Confirme la correction de l'encodage soumis / validé de la mission annulée, faite d'abord. */
    correctionConfirmed?: boolean;
  },
): Promise<CancelAsReplacedResult> {
  const { data } = await apiClient.post<CancelAsReplacedResult>(`/api/missions/${missionId}/cancel-as-replaced`, {
    ...payload,
    internalComment: payload.internalComment?.trim() || undefined,
    correctionConfirmed: payload.correctionConfirmed || undefined,
  });
  return data;
}
