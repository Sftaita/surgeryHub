import { apiClient } from "../../../api/apiClient";
import type {
  CreateMaterialItemBody,
  FirmDTO,
  MaterialItemDTO,
  MaterialItemsListResponseDTO,
  MaterialRequestDTO,
  MaterialRequestsListResponseDTO,
  MaterialRequestStatus,
  UpdateMaterialItemBody,
} from "./catalogue.types";

export const getFirms = async (): Promise<FirmDTO[]> => {
  const res = await apiClient.get("/api/firms");
  return res.data;
};

export const getMaterialItems = async (params?: {
  search?: string;
  page?: number;
  limit?: number;
}): Promise<MaterialItemsListResponseDTO> => {
  const res = await apiClient.get("/api/material-items", { params });
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

export const resolveMaterialRequest = async (
  id: number,
  materialItemId: number,
): Promise<MaterialRequestDTO> => {
  const res = await apiClient.post(
    `/api/material-item-requests/${id}/resolve`,
    { materialItemId },
  );
  return res.data.request;
};

export const ignoreMaterialRequest = async (
  id: number,
): Promise<MaterialRequestDTO> => {
  const res = await apiClient.post(
    `/api/material-item-requests/${id}/ignore`,
  );
  return res.data;
};
