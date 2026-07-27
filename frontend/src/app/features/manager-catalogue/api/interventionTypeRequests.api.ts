import { apiClient } from "../../../api/apiClient";
import type { MaterialRequestStatus } from "./catalogue.types";

/**
 * Client frontend pour `InterventionTypeRequestManagerController` (backend
 * D-068, Lot 5) — jusqu'ici seul le côté instrumentiste (création de la
 * demande, `features/encoding/api/encoding.api.ts`) était branché. Aucun
 * écran manager ne consommait list/resolve/ignore avant la fusion de
 * "Demandes" (D-079).
 */
export type InterventionTypeRequestDTO = {
  id: number;
  status: MaterialRequestStatus;
  label: string;
  suggestedCode: string | null;
  comment: string | null;
  createdAt: string;
  mission: {
    id: number;
    site: string | null;
  } | null;
  requestedBy: {
    id: number;
    displayName: string;
  } | null;
  resolvedInterventionType: {
    id: number;
    code: string;
    label: string;
  } | null;
};

export type InterventionTypeRequestsListResponseDTO = {
  items: InterventionTypeRequestDTO[];
  total: number;
};

/**
 * Forme réelle de la réponse de resolve()/ignore() côté backend
 * (InterventionTypeRequestManagerController) — un résumé de transition, jamais le DTO
 * complet de la demande (celui-ci n'est reconstruit qu'au prochain GET liste/détail).
 */
export type InterventionTypeRequestActionResultDTO = {
  requestId: number;
  draftId: number;
  status: MaterialRequestStatus;
  draftStatus: string;
  missionInterventionId: number | null;
};

export const getInterventionTypeRequests = async (params?: {
  status?: MaterialRequestStatus;
}): Promise<InterventionTypeRequestsListResponseDTO> => {
  const res = await apiClient.get("/api/intervention-type-requests", { params });
  return res.data;
};

export const resolveInterventionTypeRequest = async (
  id: number,
  interventionTypeId: number,
  primaryFirmId?: number,
): Promise<InterventionTypeRequestActionResultDTO> => {
  // Le backend attend `firmId` (InterventionTypeRequestManagerController::resolve()) —
  // `primaryFirmId` reste le nom côté appelant (cohérent avec le champ "firme principale"
  // du formulaire), mais ne doit jamais être envoyé tel quel sur le fil.
  const res = await apiClient.post(`/api/intervention-type-requests/${id}/resolve`, {
    interventionTypeId,
    firmId: primaryFirmId,
  });
  return res.data;
};

export const ignoreInterventionTypeRequest = async (
  id: number,
): Promise<InterventionTypeRequestActionResultDTO> => {
  const res = await apiClient.post(`/api/intervention-type-requests/${id}/ignore`);
  return res.data;
};
