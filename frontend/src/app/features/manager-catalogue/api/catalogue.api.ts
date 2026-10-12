import { apiClient } from "../../../api/apiClient";
import type {
  CatalogueRequestIgnoreReason,
  CreateMaterialItemBody,
  FirmDTO,
  MaterialItemDTO,
  MaterialItemsListResponseDTO,
  MaterialRegularisationResponse,
  MaterialRequestDTO,
  MaterialRequestsListResponseDTO,
  MaterialRequestStatus,
  MaterialDuplicateCandidateDTO,
  ResolveMaterialRequestBody,
  ResolveMaterialRequestResultDTO,
  UpdateMaterialItemBody,
} from "./catalogue.types";

export const getFirms = async (): Promise<FirmDTO[]> => {
  const res = await apiClient.get("/api/firms");
  return res.data;
};

/** Catalogue > Prestations, refonte UX — logo = propriété exclusive de Firm. */
export const uploadFirmLogo = async (firmId: number, file: File): Promise<FirmDTO> => {
  const formData = new FormData();
  formData.append("logo", file);
  const res = await apiClient.post(`/api/firms/${firmId}/logo`, formData, {
    headers: { "Content-Type": "multipart/form-data" },
  });
  return res.data;
};

export const deleteFirmLogo = async (firmId: number): Promise<FirmDTO> => {
  const res = await apiClient.delete(`/api/firms/${firmId}/logo`);
  return res.data;
};

export const getMaterialItems = async (params?: {
  search?: string;
  firmId?: number;
  page?: number;
  limit?: number;
}): Promise<MaterialItemsListResponseDTO> => {
  const res = await apiClient.get("/api/material-items", { params });
  return res.data;
};

/** D-142 — vue de régularisation du catalogue matériel (lecture seule, manager/admin). */
export const getMaterialBillingRegularisation = async (): Promise<MaterialRegularisationResponse> => {
  const res = await apiClient.get("/api/material-items/billing-regularisation");
  return res.data;
};

export const createMaterialItem = async (
  body: CreateMaterialItemBody,
): Promise<MaterialItemDTO> => {
  const res = await apiClient.post("/api/material-items", body);
  return res.data;
};

export const updateMaterialItem = async (
  id: number,
  body: UpdateMaterialItemBody,
): Promise<MaterialItemDTO> => {
  const res = await apiClient.patch(`/api/material-items/${id}`, body);
  return res.data;
};

export const getMaterialRequests = async (params?: {
  status?: MaterialRequestStatus;
}): Promise<MaterialRequestsListResponseDTO> => {
  const res = await apiClient.get("/api/material-item-requests", { params });
  return res.data;
};

/**
 * D-146 bis — résolution atomique : création éventuelle de la firme et du matériel,
 * ligne de matériel avec la quantité validée, traçabilité — tout ou rien côté serveur.
 */
export const resolveMaterialRequest = async (
  id: number,
  body: ResolveMaterialRequestBody,
): Promise<ResolveMaterialRequestResultDTO> => {
  const res = await apiClient.post(`/api/material-item-requests/${id}/resolve`, body);
  return res.data;
};

/** D-146 bis — matériels existants semblables (référence ou nom), firme proposée en tête. */
export const getMaterialRequestDuplicateCandidates = async (id: number): Promise<MaterialDuplicateCandidateDTO[]> => {
  const res = await apiClient.get(`/api/material-item-requests/${id}/duplicate-candidates`);
  return res.data.items ?? [];
};

/**
 * Correctif workflow Demandes Catalogue (D-113) — reason/comment toujours obligatoires
 * (validés côté backend, 422 sinon) : le demandeur doit toujours recevoir une réponse
 * utile, jamais un simple flip de statut.
 */
export const ignoreMaterialRequest = async ({
  id,
  reason,
  comment,
}: {
  id: number;
  reason: CatalogueRequestIgnoreReason;
  comment: string;
}): Promise<MaterialRequestDTO> => {
  const res = await apiClient.post(
    `/api/material-item-requests/${id}/ignore`,
    { reason, comment },
  );
  return res.data;
};
