import { apiClient } from "../../../api/apiClient";
import type { CatalogueRequestIgnoreReason, MaterialRequestStatus } from "./catalogue.types";

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
  /** Renseignés uniquement pour une demande IGNORED (D-113). */
  ignoreReason: CatalogueRequestIgnoreReason | null;
  ignoreComment: string | null;
  decidedBy: { id: number; displayName: string } | null;
  decidedAt: string | null;
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

/**
 * Correctif workflow Demandes Catalogue (D-113) — reason/comment toujours obligatoires,
 * orthogonaux à `strategy`/`missionInterventionId` (non exposés ici : la modal Ignorer
 * unifiée matériel/intervention ne couvre pas la réaffectation de matériel du draft, qui
 * reste un flux manager distinct — voir MissionInterventionDraftServiceIgnoreTest).
 */
export const ignoreInterventionTypeRequest = async ({
  id,
  reason,
  comment,
}: {
  id: number;
  reason: CatalogueRequestIgnoreReason;
  comment: string;
}): Promise<InterventionTypeRequestActionResultDTO> => {
  const res = await apiClient.post(`/api/intervention-type-requests/${id}/ignore`, { reason, comment });
  return res.data;
};
