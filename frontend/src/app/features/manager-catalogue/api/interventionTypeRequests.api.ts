import { apiClient } from "../../../api/apiClient";
import type { CatalogueFirmRefDTO, CatalogueRequestIgnoreReason, CatalogueRequestMissionDTO, MaterialRequestStatus } from "./catalogue.types";

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
  /** D-146 bis — « Avec quelle firme cette intervention a-t-elle été réalisée ? » */
  requestedFirm?: CatalogueFirmRefDTO | null;
  requestedFirmNameSnapshot?: string | null;
  proposedFirmName?: string | null;
  /** D-178 — demande faite « Aucune firme » : se résout sans firme, sur un type autorisé sans firme. */
  withoutFirm?: boolean;
  /** Intervention provisoire et matériel déjà déclaré dessus (stratégie d'ignorance). */
  draft?: { id: number; status: string; materialLineCount: number; materialItemRequestCount: number } | null;
  mission: (CatalogueRequestMissionDTO & {
    /** Cibles possibles d'une réaffectation du matériel (demande en attente seulement). */
    interventions?: { id: number; label: string; firm: string | null }[];
  }) | null;
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
  createdFirm?: boolean;
  createdInterventionType?: boolean;
  createdOffering?: boolean;
  offeringDecision?: OfferingDecision | null;
};

/**
 * D-146 bis — prestation firme × intervention absente : décision explicite du manager,
 * jamais un montant inventé ni « Pas de forfait » par défaut.
 */
export type OfferingDecision = "FEE_APPLICABLE" | "NO_FEE" | "LEAVE_UNCONFIGURED";

/** D-146 bis — corps de POST /api/intervention-type-requests/{id}/resolve (transaction unique). */
export type ResolveInterventionTypeRequestBody = {
  interventionTypeId?: number;
  /** D-178 — allowedWithoutFirm : autorisation explicite, obligatoire pour résoudre une demande sans firme. */
  newInterventionType?: { code: string; label: string; allowedWithoutFirm?: boolean };
  firmId?: number;
  newFirmName?: string;
  offeringDecision?: OfferingDecision;
  representativePresent?: boolean;
  selectedChoiceOptionId?: number;
};

export type IgnoreStrategy = "KEEP_AS_HISTORY" | "REASSIGN";

export const getInterventionTypeRequests = async (params?: {
  status?: MaterialRequestStatus;
}): Promise<InterventionTypeRequestsListResponseDTO> => {
  const res = await apiClient.get("/api/intervention-type-requests", { params });
  return res.data;
};

/**
 * D-146 bis — résolution atomique : création éventuelle de la firme, du type et de la
 * prestation, intervention créée avec la firme validée, matériel du draft repointé —
 * tout ou rien côté serveur.
 */
export const resolveInterventionTypeRequest = async (
  id: number,
  body: ResolveInterventionTypeRequestBody,
): Promise<InterventionTypeRequestActionResultDTO> => {
  const res = await apiClient.post(`/api/intervention-type-requests/${id}/resolve`, body);
  return res.data;
};

/**
 * Correctif workflow Demandes Catalogue (D-113) — reason/comment toujours obligatoires.
 * D-146 bis — si du matériel est déjà déclaré sur la demande, `strategy` est obligatoire
 * (conserver en historique, ou réaffecter vers `missionInterventionId`) : jamais perdu.
 */
export const ignoreInterventionTypeRequest = async ({
  id,
  reason,
  comment,
  strategy,
  missionInterventionId,
}: {
  id: number;
  reason: CatalogueRequestIgnoreReason;
  comment: string;
  strategy?: IgnoreStrategy;
  missionInterventionId?: number;
}): Promise<InterventionTypeRequestActionResultDTO> => {
  const res = await apiClient.post(`/api/intervention-type-requests/${id}/ignore`, {
    reason,
    comment,
    ...(strategy ? { strategy } : {}),
    ...(strategy === "REASSIGN" ? { missionInterventionId } : {}),
  });
  return res.data;
};
